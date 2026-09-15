<?php

/**
 * The chunk worker which advances a user import job.
 *
 * This is the single piece of machinery shared by both runners; the cron
 * command calls it repeatedly within a time budget, the queue task calls it in
 * a loop until the job is terminal. Every call does a bounded amount of work
 * and leaves the job in a state it can be resumed from.
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    Service
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Auth\Service\User\Import;

use Generator;
use Nails\Auth\Cdn\MetaData\SystemKey;
use Nails\Auth\Constants;
use Nails\Auth\Enum\User\Import\ItemStatus;
use Nails\Auth\Enum\User\Import\Status;
use Nails\Auth\Exception\User\Import\LogException;
use Nails\Auth\Factory\Email\User\Import\Complete;
use Nails\Auth\Factory\Email\User\Import\Failed;
use Nails\Auth\Model;
use Nails\Auth\Resource;
use Nails\Auth\Service\User\Import as ImportService;
use Nails\Cdn;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\ModelException;
use Nails\Common\Exception\NailsException;
use Nails\Common\Exception\ValidationException;
use Nails\Common\Service\Database;
use Nails\Common\Service\Logger;
use Nails\Config;
use Nails\Factory;
use Throwable;

/**
 * Class Processor
 *
 * @package Nails\Auth\Service\User\Import
 */
class Processor
{
    /**
     * The bucket both the source CSV and the generated log are stored in
     *
     * @var array
     */
    const array IMPORT_BUCKET = [
        'slug'          => 'import-user',
        'is_hidden'     => true,
        'allowed_types' => 'csv',
    ];

    /**
     * The number of rows to handle in a single call to process()
     *
     * @var int
     */
    const CHUNK_SIZE = 100;

    /**
     * The number of items to read from the database at a time when building the log
     *
     * @var int
     */
    const LOG_PAGE_SIZE = 1000;

    /**
     * The number of failing lines to quote in the job's error
     *
     * Enough to recognise a pattern - "they are all already registered" - without
     * duplicating the log CSV, which carries every row.
     *
     * @var int
     */
    const ERROR_SAMPLE_SIZE = 10;

    /**
     * The prefix of the composed error's "how far did it get" fact
     *
     * A const because summariseError() has to recognise this line to keep it
     * while dropping the facts either side of it; the two must not drift.
     *
     * @var string
     */
    const ERROR_FACT_PHASE = 'Phase: ';

    /**
     * The maximum length of the job's error
     *
     * The column is TEXT, so this is a sanity bound rather than a hard limit; it
     * exists so a pathological CSV cannot fill the row with error text.
     *
     * @var int
     */
    const ERROR_MAX_LENGTH = 8000;

    /**
     * The maximum length of a stack trace recorded in the log
     *
     * @var int
     */
    const ERROR_TRACE_MAX_LENGTH = 2000;

    /**
     * The levels report() can log at
     *
     * @var string
     */
    const LOG_INFO    = 'info';
    const LOG_WARNING = 'warning';
    const LOG_ERROR   = 'error';

    // --------------------------------------------------------------------------

    /**
     * Advances the job by, at most, $iLimit rows
     *
     * @param Resource\User\Import $oImport The job to advance
     * @param int|null             $iLimit  The number of rows to handle, null for the configured chunk size
     *
     * @return Resource\User\Import The job, as it now stands
     * @throws FactoryException
     * @throws ModelException
     * @throws NailsException
     */
    public function process(Resource\User\Import $oImport, ?int $iLimit = null): Resource\User\Import
    {
        $iLimit = $iLimit ?: static::getChunkSize();

        try {

            return match ($oImport->status) {
                Status::PENDING    => $this->begin($oImport),
                Status::VALIDATING => $this->validate($oImport, $iLimit),
                Status::RUNNING    => $this->run($oImport, $iLimit),
                default            => $oImport,
            };

        } catch (Throwable $e) {
            /**
             * Re-read before composing: the counts the message quotes have to be
             * the ones on disk, not the ones this call started with.
             */
            $oImport = $this->refresh($oImport);

            return $this->fail($oImport, $this->composeThrowableError($oImport, $e), $e);
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Rejects a job which has stopped making progress
     *
     * Public because it is the runners, not the processor, which detect a stall -
     * they are the ones holding the before-and-after fingerprints. Named to match
     * the copy they already print rather than exposing fail() itself.
     *
     * @throws FactoryException
     * @throws ModelException
     */
    public function abandon(Resource\User\Import $oImport, string $sReason): Resource\User\Import
    {
        $oImport = $this->refresh($oImport);

        return $this->fail($oImport, $this->composeStallError($oImport, $sReason));
    }

    // --------------------------------------------------------------------------

    /**
     * The number of rows to handle in a single call to process()
     */
    public static function getChunkSize(): int
    {
        return (int) Config::get('AUTH_USER_IMPORT_CHUNK', static::CHUNK_SIZE) ?: static::CHUNK_SIZE;
    }

    // --------------------------------------------------------------------------

    /**
     * Opens the job: records when it started and how much work there is to do
     *
     * @throws FactoryException
     * @throws ModelException
     */
    protected function begin(Resource\User\Import $oImport): Resource\User\Import
    {
        $oModel = $this->getModel();
        /** @var Csv $oCsv */
        $oCsv = Factory::service('UserImportCsv', Constants::MODULE_SLUG);
        /** @var \DateTime $oNow */
        $oNow = Factory::factory('DateTime');

        $sPath = $this->getSourcePath($oImport);

        $this->clearPreviousAttempt($oImport);

        $iRowCount = $oCsv->countRows($sPath);

        $oModel->update($oImport->id, [
            'started'         => $oNow->format('Y-m-d H:i:s'),
            'finished'        => null,
            'log_id'          => null,
            'row_count'       => $iRowCount,
            'validated_count' => 0,
            'processed_count' => 0,
            'success_count'   => 0,
            'warning_count'   => 0,
            'error_count'     => 0,
            'error'           => null,
            'status'          => Status::VALIDATING->value,
        ]);

        $this->report(
            $oImport,
            sprintf(
                'starting; %s rows, %s runner',
                number_format($iRowCount),
                $oImport->runner?->value ?? 'unknown'
            ),
            null,
            static::LOG_INFO
        );

        return $this->refresh($oImport);
    }

    // --------------------------------------------------------------------------

    /**
     * Validates the next chunk of rows
     *
     * Nothing is created during this phase. If a single row fails then the whole
     * job is rejected, because a half-imported file is worse than no import at
     * all; the errors are written out as a log so they can be corrected and the
     * file re-uploaded.
     *
     * @throws FactoryException
     * @throws ModelException
     * @throws NailsException
     */
    protected function validate(Resource\User\Import $oImport, int $iLimit): Resource\User\Import
    {
        $oModel = $this->getModel();
        /** @var Csv $oCsv */
        $oCsv = Factory::service('UserImportCsv', Constants::MODULE_SLUG);
        /** @var Validator $oValidator */
        $oValidator = Factory::service('UserImportValidator', Constants::MODULE_SLUG);

        $sPath   = $this->getSourcePath($oImport);
        $aHeader = $oCsv->getHeader($sPath);

        /**
         * Both are fatal and neither produces a per-row log worth generating: a
         * template which cannot identify an account, or a header we do not
         * understand, means no row could ever be imported.
         */
        $oValidator->validateTemplate();
        $oValidator->validateHeader($aHeader);

        $aChunk = iterator_to_array(
            $oCsv->readRawRows($sPath, $oImport->validated_count, $iLimit),
            true
        );

        $aErrors = $oValidator->validateRows($aHeader, $aChunk);

        /**
         * Whether an already-registered row is fatal is the admin's call, taken
         * when the job was approved. When they have opted to skip, the rows are
         * deliberately *not* recorded here and are left for run() to deal with
         * as it reaches them - see the note there on why an item must not exist
         * ahead of its row.
         */
        if (!$oImport->skip_registered) {
            foreach ($oValidator->detectRegistered($aHeader, $aChunk) as $iLine => $aLineErrors) {
                $aErrors[$iLine] = array_merge($aErrors[$iLine] ?? [], $aLineErrors);
            }
        }

        foreach ($aErrors as $iLine => $aLineErrors) {
            $this->recordItem(
                $oImport,
                $iLine,
                ItemStatus::ERROR,
                implode('; ', $aLineErrors)
            );
        }

        $oModel->setValidatedCount($oImport->id, $oImport->validated_count + count($aChunk));
        $oModel->syncValidationCounts($oImport->id);

        $oImport = $this->refresh($oImport);

        //  More to do?
        if (!empty($aChunk) && $oImport->validated_count < $oImport->row_count) {
            return $oImport;
        }

        if ($oImport->error_count) {

            $this->report(
                $oImport,
                sprintf(
                    'validation rejected %s of %s rows',
                    number_format($oImport->error_count),
                    number_format((int) $oImport->row_count)
                ),
                null,
                static::LOG_WARNING
            );

            return $this->fail($oImport, $this->composeValidationError($oImport));
        }

        /**
         * The app's last chance to refuse the job, and the only hook which is
         * allowed to: nothing has been created yet, so a throw here is
         * deliberately left to reach process()'s catch, which fails the job with
         * the reason folded into its composed error. Every other hook is
         * wrapped, because by the time they run there are accounts which cannot
         * be taken back.
         */
        $this->getImportService()->onImportStart($oImport);

        $oModel->update($oImport->id, [
            'processed_count' => 0,
            'success_count'   => 0,
            'warning_count'   => 0,
            'error_count'     => 0,
            'status'          => Status::RUNNING->value,
        ]);

        return $this->refresh($oImport);
    }

    // --------------------------------------------------------------------------

    /**
     * Creates the users for the next chunk of rows
     *
     * @throws FactoryException
     * @throws ModelException
     * @throws NailsException
     */
    protected function run(Resource\User\Import $oImport, int $iLimit): Resource\User\Import
    {
        $oModel         = $this->getModel();
        $oUserModel     = $this->getUserModel();
        $oImportService = $this->getImportService();
        /** @var Csv $oCsv */
        $oCsv = Factory::service('UserImportCsv', Constants::MODULE_SLUG);
        /** @var Validator $oValidator */
        $oValidator = Factory::service('UserImportValidator', Constants::MODULE_SLUG);

        $sPath   = $this->getSourcePath($oImport);
        $aHeader = $oCsv->getHeader($sPath);
        $aKeys   = $oImportService->getKeys();

        $aChunk = iterator_to_array(
            $oCsv->readRawRows($sPath, $oImport->processed_count, $iLimit),
            true
        );

        /**
         * One batched lookup for the chunk, and only when the admin asked for it:
         * without their consent a row which became registered since validation
         * is left to create(), which refuses it on its own authority and reports
         * its own reason.
         */
        $aRegistered = $oImport->skip_registered
            ? $oValidator->detectRegistered($aHeader, $aChunk)
            : [];

        /**
         * A job which was interrupted mid-chunk will have a cursor which lags the
         * item table; skip anything already accounted for so a row can never be
         * imported twice.
         */
        $aDone = $this->getHandledLines($oImport, array_keys($aChunk));

        foreach ($aChunk as $iLine => $aRow) {

            if (in_array($iLine, $aDone, true)) {
                continue;
            }

            $aDatum = $oCsv->align($aHeader, $aRow);

            /**
             * Claim the line before doing anything which cannot be undone; if the
             * process dies part way through, the line is marked as handled and
             * will not be attempted again.
             */
            $iItemId = $this->recordItem(
                $oImport,
                $iLine,
                ItemStatus::ERROR,
                'The import was interrupted before this row was completed'
            );

            if (empty($iItemId)) {
                continue;
            }

            /**
             * Claimed, then resolved without creating anything. Handling the skip
             * here rather than pre-recording it during validation is deliberate:
             * the read offset above is processed_count, which only tracks the
             * file because every row of a chunk yields exactly one item. An item
             * which exists ahead of its row inflates the count and silently
             * steps over a row which was never imported.
             */
            if (array_key_exists($iLine, $aRegistered)) {
                $this->resolveItem(
                    $iItemId,
                    ItemStatus::ERROR,
                    sprintf('%s; skipped', implode('; ', $aRegistered[$iLine]))
                );
                continue;
            }

            $bSendEmail = stringToBoolean($aDatum['send_email'] ?? false);
            $aUserData  = [];

            foreach ($aKeys as $sKey) {
                $aUserData[$sKey] = $aDatum[$sKey] ?? null;
                if ($aUserData[$sKey] === '') {
                    $aUserData[$sKey] = $oImportService->getDefaultValue($sKey);
                }
            }

            //  Apply additional fields
            foreach ($oImport->additional as $sProperty => $mValue) {
                $aUserData[$sProperty] = $oImportService->parseAdditionalFields($sProperty, $mValue);
            }

            try {

                /**
                 * Deliberately after the loop above rather than in place of it,
                 * so an app which overrides the hook without calling parent::
                 * still gets the additional fields it configured. The raw row is
                 * handed over too, because it carries the columns create() is
                 * about to discard.
                 */
                $aUserData = $oImportService->prepareUserData($aUserData, $oImport, $aDatum);

                $oUser = $oUserModel->create($aUserData, $bSendEmail);

                if (!$oUser) {
                    $this->resolveItem(
                        $iItemId,
                        ItemStatus::ERROR,
                        /**
                         * lastError() returns false, not '', for an empty error
                         * stack; without the fallback a failed create() leaves
                         * the row with no reason recorded against it at all.
                         */
                        $oUserModel->lastError() ?: 'The account could not be created'
                    );
                    continue;
                }

                /**
                 * The account is committed from here on - create() commits, and
                 * queues the welcome email, before it returns - so nothing below
                 * may pretend it can be undone. A hook which fails leaves the row
                 * WARNING against the new account's ID, which is the thread back
                 * to whatever an admin now has to finish by hand.
                 */
                try {
                    $oImportService->afterUserCreate($oUser, $aUserData, $oImport);
                    $this->resolveItem($iItemId, ItemStatus::SUCCESS, null, $oUser->id);

                } catch (Throwable $e) {
                    $this->resolveItem(
                        $iItemId,
                        ItemStatus::WARNING,
                        sprintf('The account was created, but %s', $e->getMessage()),
                        $oUser->id
                    );
                    $this->report(
                        $oImport,
                        sprintf('line %s: afterUserCreate() failed', $iLine),
                        $e
                    );
                }

            } catch (Throwable $e) {
                //  Covers prepareUserData() and create() itself; no account exists
                $this->resolveItem($iItemId, ItemStatus::ERROR, $e->getMessage());
                $this->report($oImport, sprintf('line %s could not be imported', $iLine), $e);
            }
        }

        $oModel->syncProcessingCounts($oImport->id);
        $oImport = $this->refresh($oImport);

        if (empty($aChunk) || $oImport->processed_count >= $oImport->row_count) {
            return $this->complete($oImport);
        }

        return $oImport;
    }

    // --------------------------------------------------------------------------

    /**
     * Finishes the job, generating and attaching the log
     *
     * @throws FactoryException
     * @throws ModelException
     * @throws NailsException
     */
    protected function complete(Resource\User\Import $oImport): Resource\User\Import
    {
        $oModel = $this->getModel();
        /** @var \DateTime $oNow */
        $oNow = Factory::factory('DateTime');

        $aLog = $this->attachLog($oImport);

        //  A warned account exists, so it is one of the accounts this created
        $iCreated = $oImport->success_count + $oImport->warning_count;

        /**
         * A log which could not be written must not turn a finished import into
         * a failed one - the accounts exist either way - but nor should it
         * disappear, so it is recorded against the job. This used to be an
         * un-caught call, which meant a log failure here reached process()'s
         * catch and re-branded a perfectly good import as FAILED.
         */
        $sError = $aLog['error']
            ? $this->truncateError($this->appendLogFailure(
                sprintf(
                    '%s user %s created; the import itself finished normally.',
                    number_format($iCreated),
                    $iCreated === 1 ? 'account was' : 'accounts were'
                ),
                $aLog['error']
            ))
            : null;

        /**
         * A warning is a row whose account exists but whose follow-up did not
         * happen, so it counts against a clean finish just as an error does; a
         * job which quietly failed every subscription must not read COMPLETE.
         */
        $sStatus = $oImport->error_count || $oImport->warning_count
            ? Status::PARTIAL->value
            : Status::COMPLETE->value;

        if (!$oModel->update($oImport->id, [
            'log_id'   => $aLog['log_id'],
            'finished' => $oNow->format('Y-m-d H:i:s'),
            'error'    => $sError,
            'status'   => $sStatus,
        ])) {
            $this->report($oImport, sprintf(
                'failed to record completion; %s',
                $oModel->lastError() ?: 'no reason was reported'
            ));
        }

        $oImport = $this->refresh($oImport);

        /**
         * Fired before the notification, so a hook which refuses the finished
         * job has its reason in the email as well as in the modal.
         */
        $oImport = $this->fireImportComplete($oImport);

        $this->report(
            $oImport,
            sprintf(
                '%s; %s created, %s warned, %s errored, log %s',
                $sStatus,
                number_format($oImport->success_count),
                number_format($oImport->warning_count),
                number_format($oImport->error_count),
                $oImport->log_id ? '#' . $oImport->log_id : 'not attached'
            ),
            null,
            static::LOG_INFO
        );

        $this->notify(
            $oImport,
            Factory::factory('EmailUserImportComplete', Constants::MODULE_SLUG)
        );

        return $oImport;
    }

    // --------------------------------------------------------------------------

    /**
     * Rejects the job
     *
     * @throws FactoryException
     * @throws ModelException
     */
    protected function fail(
        Resource\User\Import $oImport,
        string $sError,
        ?Throwable $e = null
    ): Resource\User\Import {

        $oModel = $this->getModel();
        /** @var \DateTime $oNow */
        $oNow = Factory::factory('DateTime');

        /**
         * Warnings are logged alongside the errors: a job which warned and then
         * failed would otherwise drop the accounts which need attention from the
         * only record which names them.
         */
        $aLog = $oImport->error_count || $oImport->warning_count
            ? $this->attachLog($oImport, [ItemStatus::ERROR, ItemStatus::WARNING])
            : ['log_id' => $oImport->log_id, 'error' => null];

        if ($aLog['error']) {
            $sError = $this->appendLogFailure($sError, $aLog['error']);
        }

        $sError = $this->truncateError($sError);

        /**
         * Reported rather than thrown: throwing here would re-enter process()'s
         * catch and recurse straight back into fail(). Left alone, the job's
         * fingerprint does not move and the runner's stall path picks it up.
         */
        if (!$oModel->update($oImport->id, [
            'log_id'   => $aLog['log_id'],
            'finished' => $oNow->format('Y-m-d H:i:s'),
            'error'    => $sError,
            'status'   => Status::FAILED->value,
        ])) {
            $this->report($oImport, sprintf(
                'failed to record the failure; %s',
                $oModel->lastError() ?: 'no reason was reported'
            ));
        }

        $this->report($oImport, sprintf('FAILED; %s', $this->firstLine($sError)), $e);

        $oImport = $this->refresh($oImport);

        $this->fireImportFailed($oImport);

        $this->notify(
            $oImport,
            Factory::factory('EmailUserImportFailed', Constants::MODULE_SLUG)
        );

        return $oImport;
    }

    // --------------------------------------------------------------------------

    /**
     * Emails the person who requested the import
     *
     * Sent rather than queued: this is the operator's feedback on the job, and
     * queueing it would put it behind the welcome email of every row the import
     * created. One inline send at the end of a finished job is a price worth
     * paying for them finding out promptly whether it worked.
     *
     * Counts, links and a redacted error only. The failing rows quote cell
     * values straight out of the CSV - an email address, a name - and an inbox
     * is the wrong place to keep those. Whoever needs to know which rows failed
     * opens the log, which is behind a permission check.
     */
    protected function notify(Resource\User\Import $oImport, Complete|Failed $oEmail): void
    {
        $iRecipient = $oImport->created_by instanceof \Nails\Common\Resource
            ? $oImport->created_by->id
            : $oImport->created_by;

        if (empty($iRecipient)) {
            return;
        }

        try {

            $oEmail
                ->to($iRecipient)
                ->data([
                    'import'  => [
                        'row_count' => $oImport->row_count,
                        'success'   => $oImport->success_count,
                        'warnings'  => $oImport->warning_count,
                        'errors'    => $oImport->error_count,
                        'url'       => siteUrl('admin/auth/import/preview/' . $oImport->id),
                    ],
                    'error'   => $this->summariseError($oImport->error),
                    /**
                     * The guarded admin route rather than the CDN object: the
                     * log carries personal data, so the URL which reaches it is
                     * minted behind a permission check and expires.
                     */
                    'log_url' => $oImport->log_id
                        ? siteUrl('admin/auth/import/log/' . $oImport->id)
                        : null,
                ])
                ->send();

        } catch (Throwable $e) {
            /**
             * An email which cannot be sent must not undo the import. The
             * exception is deliberately not handed to the error handler: a bad
             * mail configuration would otherwise raise an alert on every import.
             */
            $this->report(
                $oImport,
                sprintf('could not send the notification email; %s', $e->getMessage()),
                null,
                static::LOG_WARNING
            );
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Generates the log CSV, puts it in the CDN, and returns its object ID
     *
     * The ID is returned rather than a resource deliberately. Cdn::objectCreate()
     * hands back a plain stdClass - Cdn::getObject() is declared `bool|stdClass`
     * and never returns a Cdn\Resource\CdnObject - so declaring a resource
     * return type here raises a TypeError on the way out, which the caller then
     * has to know to catch. An int cannot be got wrong, and both callers only
     * ever read the ID. The admin upload path converts via the Object model
     * instead; see Admin\Import::uploadCsv().
     *
     * @param iterable<int, array{id: int|null, status: string, message: string|null}> $aItems
     *
     * @return int The CDN object ID of the stored log
     * @throws LogException If the log cannot be built or stored
     */
    protected function uploadLog(Resource\User\Import $oImport, iterable $aItems): int
    {
        /** @var Csv $oCsv */
        $oCsv = Factory::service('UserImportCsv', Constants::MODULE_SLUG);
        /** @var \DateTime $oNow */
        $oNow = Factory::factory('DateTime');

        $oCdn = $this->getCdn();

        try {
            $sLogPath = $oCsv->writeLog($this->getSourcePath($oImport), $aItems);

        } catch (Throwable $e) {
            throw new LogException(
                sprintf('Failed to build the import log; %s', $e->getMessage()),
                0,
                $e
            );
        }

        try {

            $mLog = $oCdn->objectCreate(
                $sLogPath,
                static::IMPORT_BUCKET,
                [
                    'no-md5-check'     => true,
                    'Content-Type'     => 'text/csv',
                    'filename_display' => sprintf(
                        'user-import-log-%s.csv',
                        $oNow->format('Y-m-d_H-i-s')
                    ),
                    'metadata'         => $this->getLogMetaData($oImport),
                ]
            );

        } finally {
            @unlink($sLogPath);
        }

        /**
         * objectCreate() signals failure by returning false and stashing the
         * reason on the service, so it has to be read out explicitly or it is
         * lost. lastError() returns false - not '' - for an empty error stack,
         * hence the fallback.
         */
        if (empty($mLog) || empty($mLog->id)) {
            throw new LogException(sprintf(
                'Failed to store the import log in the CDN; %s',
                $oCdn->lastError() ?: 'no reason was reported'
            ));
        }

        return (int) $mLog->id;
    }

    // --------------------------------------------------------------------------

    /**
     * Attaches the log to the job, keeping hold of the reason if it cannot be
     *
     * The single place a log is attached. A log which cannot be written must not
     * mask the reason the job finished the way it did - but it must not vanish
     * either, which is what used to happen. Throwable rather than LogException
     * on purpose: a programming error in here must still not take the job down,
     * but it must be reported.
     *
     * @param ItemStatus[]|null $aStatuses The statuses to log, null for every item
     *
     * @return array{log_id: int|null, error: string|null}
     * @throws FactoryException
     * @throws ModelException
     */
    protected function attachLog(Resource\User\Import $oImport, ?array $aStatuses = null): array
    {
        try {
            return [
                'log_id' => $this->uploadLog($oImport, $this->streamItems($oImport, $aStatuses)),
                'error'  => null,
            ];

        } catch (Throwable $e) {
            $this->report($oImport, 'failed to attach the log', $e);

            return [
                'log_id' => $oImport->log_id,
                'error'  => $e->getMessage(),
            ];
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Records something noteworthy about a job
     *
     * Everything goes to the application log prefixed with the job's ID, so
     * `grep 'import #6' application/logs/log-*.php` is the recovery path for a
     * job whose stored error is not enough. A per-job log file would be tidier
     * but nothing in the UI could link to it, which is the very problem this is
     * meant to solve.
     */
    protected function report(
        Resource\User\Import $oImport,
        string $sMessage,
        ?Throwable $e = null,
        string $sLevel = self::LOG_ERROR
    ): void {

        $sLine = sprintf('User import #%s: %s', $oImport->id, $sMessage);

        if ($e !== null) {
            $sLine .= ' ' . json_encode($this->errorPayload($e), JSON_UNESCAPED_SLASHES);
        }

        try {
            match ($sLevel) {
                static::LOG_INFO    => $this->getLogger()->info($sLine),
                static::LOG_WARNING => $this->getLogger()->warning($sLine),
                default             => $this->getLogger()->error($sLine),
            };
        } catch (Throwable $eLogger) {
            //  A worker which cannot log must still finish the job
        }

        if ($e === null || $sLevel !== static::LOG_ERROR) {
            return;
        }

        /**
         * Hand the exception to whatever the app reports errors with - Sentry,
         * Rollbar - without halting; see module-cron's Run::logException().
         */
        try {
            $sDriver = Factory::service('ErrorHandler')::getDriverClass();
            $sDriver::exception($e, false);
        } catch (Throwable $eHandler) {
            //  A reporting failure must never take the job with it
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Reduces a Throwable to the detail worth keeping
     *
     * Mirrors the shape module-queue records for a failed job; see
     * Nails\Queue\Service\Manager::buildErrorPayload(). Deliberately duplicated
     * rather than called: that method is protected, and module-queue is only a
     * dev/suggested dependency, so a hard reference would break every install
     * without it.
     *
     * @return array{type: string, message: string, code: mixed, file: string, line: int, trace: string, occurred_at: string}
     */
    protected function errorPayload(Throwable $e): array
    {
        $sTrace = $e->getTraceAsString();

        if (mb_strlen($sTrace) > static::ERROR_TRACE_MAX_LENGTH) {
            $sTrace = mb_substr($sTrace, 0, static::ERROR_TRACE_MAX_LENGTH) . '…';
        }

        return [
            'type'        => $e::class,
            'message'     => $e->getMessage(),
            'code'        => $e->getCode(),
            'file'        => $e->getFile(),
            'line'        => $e->getLine(),
            'trace'       => $sTrace,
            'occurred_at' => (new \DateTime())->format('Y-m-d H:i:s'),
        ];
    }

    // --------------------------------------------------------------------------

    /**
     * Yields the job's items, in line order, in pages
     *
     * Takes a set of statuses rather than one: a failed job wants its errors and
     * its warnings in the same log, and asking twice would interleave two sorted
     * streams for no gain.
     *
     * @param ItemStatus[]|null $aStatuses The statuses to yield, null for every item
     *
     * @return Generator<int, array{id: int|null, status: string, message: string|null}>
     * @throws FactoryException
     * @throws ModelException
     */
    protected function streamItems(Resource\User\Import $oImport, ?array $aStatuses = null): Generator
    {
        /** @var Model\User\Import\Item $oItemModel */
        $oItemModel = Factory::model('UserImportItem', Constants::MODULE_SLUG);
        /** @var Database $oDb */
        $oDb = Factory::service('Database');

        $iOffset = 0;

        do {

            $oDb
                ->select('line, user_id, status, message')
                ->where('import_id', $oImport->id)
                ->order_by('line', 'asc')
                ->limit(static::LOG_PAGE_SIZE, $iOffset);

            if (!empty($aStatuses)) {
                $oDb->where_in('status', array_map(
                    fn(ItemStatus $oStatus): string => $oStatus->value,
                    $aStatuses
                ));
            }

            $aRows = $oDb->get($oItemModel->getTableName())->result();

            foreach ($aRows as $oRow) {
                yield (int) $oRow->line => [
                    'id'      => $oRow->user_id === null ? null : (int) $oRow->user_id,
                    'status'  => $oRow->status,
                    'message' => $oRow->message,
                ];
            }

            $iOffset += static::LOG_PAGE_SIZE;

        } while (count($aRows) === static::LOG_PAGE_SIZE);
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the lines, within the given set, which already have an item
     *
     * @param int[] $aLines
     *
     * @return int[]
     * @throws FactoryException
     * @throws ModelException
     */
    protected function getHandledLines(Resource\User\Import $oImport, array $aLines): array
    {
        if (empty($aLines)) {
            return [];
        }

        /** @var Model\User\Import\Item $oItemModel */
        $oItemModel = Factory::model('UserImportItem', Constants::MODULE_SLUG);
        /** @var Database $oDb */
        $oDb = Factory::service('Database');

        $aRows = $oDb
            ->select('line')
            ->where('import_id', $oImport->id)
            ->where_in('line', $aLines)
            ->get($oItemModel->getTableName())
            ->result();

        return array_map(fn($oRow) => (int) $oRow->line, $aRows);
    }

    // --------------------------------------------------------------------------

    /**
     * Records the outcome of a line
     *
     * The unique key on (import_id, line) is what makes resuming idempotent; a
     * clash simply means an earlier run already dealt with this line.
     *
     * @return int|null The new item's ID, or null if the line was already handled
     * @throws FactoryException
     */
    protected function recordItem(
        Resource\User\Import $oImport,
        int $iLine,
        ItemStatus $oStatus,
        ?string $sMessage = null,
        ?int $iUserId = null
    ): ?int {

        /** @var Model\User\Import\Item $oItemModel */
        $oItemModel = Factory::model('UserImportItem', Constants::MODULE_SLUG);

        try {

            $iItemId = $oItemModel->create([
                'import_id' => $oImport->id,
                'line'      => $iLine,
                'user_id'   => $iUserId,
                'status'    => $oStatus->value,
                'message'   => $sMessage,
            ]);

        } catch (Throwable $e) {
            /**
             * A clash on the (import_id, line) unique key means an earlier run
             * already dealt with this line, which is how resuming stays
             * idempotent - the expected path, not a fault. Anything else means
             * the row will silently never be imported, which is worth the one
             * extra query to tell apart.
             */
            if (empty($this->getHandledLines($oImport, [$iLine]))) {
                $this->report($oImport, sprintf('line %s could not be recorded', $iLine), $e);
            }

            return null;
        }

        return $iItemId ?: null;
    }

    // --------------------------------------------------------------------------

    /**
     * Updates a previously claimed line with what actually happened
     *
     * @throws FactoryException
     * @throws ModelException
     */
    protected function resolveItem(
        int $iItemId,
        ItemStatus $oStatus,
        ?string $sMessage = null,
        ?int $iUserId = null
    ): void {

        /** @var Model\User\Import\Item $oItemModel */
        $oItemModel = Factory::model('UserImportItem', Constants::MODULE_SLUG);

        $oItemModel->update($iItemId, [
            'status'  => $oStatus->value,
            'message' => $sMessage,
            'user_id' => $iUserId,
        ]);
    }

    // --------------------------------------------------------------------------

    /**
     * Clears down a previous attempt at the job
     *
     * A job restarted from PENDING has to be cleared first: the unique key on
     * (import_id, line) would otherwise make every line look as though it had
     * already been handled, and the counts would be re-derived from the previous
     * run's items.
     *
     * @throws FactoryException
     * @throws ModelException
     */
    protected function clearPreviousAttempt(Resource\User\Import $oImport): void
    {
        $this->getModel()->deleteItems($oImport->id);

        if (empty($oImport->log_id)) {
            return;
        }

        /**
         * begin() is about to null log_id, and UserImports housekeeping only reaps
         * objects it can still reach from a job row, so the previous log is
         * destroyed here or not at all.
         */
        try {
            if (!$this->getCdn()->objectDestroy($oImport->log_id)) {
                $this->report(
                    $oImport,
                    sprintf(
                        'failed to destroy the previous log #%s; %s',
                        $oImport->log_id,
                        $this->getCdn()->lastError() ?: 'no reason was reported'
                    ),
                    null,
                    static::LOG_WARNING
                );
            }

        } catch (Throwable $e) {
            $this->report(
                $oImport,
                sprintf('failed to destroy the previous log #%s', $oImport->log_id),
                $e
            );
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Composes the job's error
     *
     * Plain text with newlines. Line 1 is the only line a non-technical admin
     * has to read - it is what the list cell and the preview alert show - and
     * everything below it is for whoever has to fix the CSV.
     *
     * Deliberately not translated: this is a stored audit record written by a
     * worker whose locale is the app default rather than the reader's, so it is
     * written in English and translated at presentation if it ever needs to be.
     *
     * @param array<int, array{line: int, message: string|null}> $aSample
     */
    protected function composeError(
        string $sSummary,
        ?string $sPhase = null,
        ?string $sCause = null,
        ?string $sWhere = null,
        array $aSample = [],
        int $iTotal = 0
    ): string {

        $aBlocks = [$sSummary];

        $aFacts = array_filter([
            $sPhase !== null ? static::ERROR_FACT_PHASE . $sPhase : null,
            $sCause !== null ? 'Cause: ' . $sCause : null,
            $sWhere !== null ? 'Where: ' . $sWhere : null,
        ]);

        if (!empty($aFacts)) {
            $aBlocks[] = implode("\n", $aFacts);
        }

        if (!empty($aSample)) {

            $aLines = [
                $iTotal > count($aSample)
                    ? sprintf(
                        'Rows with errors (showing the first %s of %s):',
                        number_format(count($aSample)),
                        number_format($iTotal)
                    )
                    : sprintf('Rows with errors (%s):', number_format(count($aSample))),
            ];

            foreach ($aSample as $aItem) {
                $aLines[] = sprintf(
                    '  Line %s: %s',
                    $aItem['line'],
                    $aItem['message'] ?: '(no reason was recorded)'
                );
            }

            $aBlocks[] = implode("\n", $aLines);
        }

        return implode("\n\n", $aBlocks);
    }

    // --------------------------------------------------------------------------

    /**
     * Composes the error for a job rejected during validation
     *
     * @throws FactoryException
     * @throws ModelException
     */
    protected function composeValidationError(Resource\User\Import $oImport): string
    {
        return $this->composeError(
            sprintf(
                '%s %s in the CSV could not be validated, so no user accounts were created. Correct them and upload the file again.',
                number_format($oImport->error_count),
                $oImport->error_count === 1 ? 'row' : 'rows'
            ),
            $this->describePhase($oImport),
            null,
            null,
            $this->sampleErrors($oImport, static::ERROR_SAMPLE_SIZE),
            $oImport->error_count
        );
    }

    // --------------------------------------------------------------------------

    /**
     * Composes the error for a job brought down by something unexpected
     *
     * @throws FactoryException
     * @throws ModelException
     */
    protected function composeThrowableError(Resource\User\Import $oImport, Throwable $e): string
    {
        $sSummary = 'The import stopped unexpectedly and did not finish.';

        $sSurvivors = $this->describeSurvivors($oImport);
        if ($sSurvivors !== null) {
            $sSummary .= ' ' . $sSurvivors;
        }

        return $this->composeError(
            $sSummary,
            $this->describePhase($oImport),
            sprintf('%s - %s', $e::class, $e->getMessage()),
            /**
             * basename() only - an absolute path from inside a container is
             * noise to an admin, and this string reaches their inbox.
             */
            sprintf('%s line %s', basename($e->getFile()), $e->getLine()),
            $this->sampleErrors($oImport, static::ERROR_SAMPLE_SIZE),
            $oImport->error_count
        ) . "\n\nThe full technical detail was written to the application log.";
    }

    // --------------------------------------------------------------------------

    /**
     * Composes the error for a job which stopped making progress
     *
     * @throws FactoryException
     * @throws ModelException
     */
    protected function composeStallError(Resource\User\Import $oImport, string $sReason): string
    {
        $sSummary = 'The import made no progress and was stopped to prevent it looping.';

        $sSurvivors = $this->describeSurvivors($oImport);
        if ($sSurvivors !== null) {
            $sSummary .= ' ' . $sSurvivors;
        }

        return $this->composeError(
            $sSummary,
            $this->describePhase($oImport),
            $sReason,
            null,
            $this->sampleErrors($oImport, static::ERROR_SAMPLE_SIZE),
            $oImport->error_count
        );
    }

    // --------------------------------------------------------------------------

    /**
     * Describes what survived a job which stopped part way through
     *
     * validate()'s "no user accounts were created" cannot be reused once run()
     * has started: accounts may well exist by then, and telling an admin none
     * were created when some were is worse than saying nothing at all.
     */
    protected function describeSurvivors(Resource\User\Import $oImport): ?string
    {
        /**
         * Warnings count as created: the account exists, it is only the work
         * which was meant to follow it which did not happen.
         */
        $iCreated = $oImport->success_count + $oImport->warning_count;

        if ($iCreated) {
            return sprintf(
                '%s user %s had already been created and %s been kept.',
                number_format($iCreated),
                $iCreated === 1 ? 'account' : 'accounts',
                $iCreated === 1 ? 'has' : 'have'
            );

        } elseif ($oImport->status === Status::VALIDATING) {
            return 'No user accounts were created.';
        }

        return null;
    }

    // --------------------------------------------------------------------------

    /**
     * Describes how far the job had got
     */
    protected function describePhase(Resource\User\Import $oImport): string
    {
        return match ($oImport->status) {
            Status::PENDING    => 'opening the job',
            Status::VALIDATING => sprintf(
                'validating the CSV (%s of %s rows checked)',
                number_format($oImport->validated_count),
                number_format((int) $oImport->row_count)
            ),
            Status::RUNNING    => sprintf(
                'creating user accounts (%s of %s rows processed)',
                number_format($oImport->processed_count),
                number_format((int) $oImport->row_count)
            ),
            default            => sprintf('finishing the job (%s)', $oImport->status->value),
        };
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the job's first failing lines
     *
     * The log CSV carries every row, but it sits behind a download; this is what
     * the modal, the CLI and the database itself show, so it is worth one
     * bounded query. Deliberately not the email - see notify().
     *
     * @return array<int, array{line: int, message: string|null}>
     * @throws FactoryException
     * @throws ModelException
     */
    protected function sampleErrors(Resource\User\Import $oImport, int $iLimit): array
    {
        return $this->sampleItems($oImport, ItemStatus::ERROR, $iLimit);
    }


    // --------------------------------------------------------------------------

    /**
     * Returns the job's first rows of a given status
     *
     * @return array<int, array{line: int, message: string|null}>
     * @throws FactoryException
     * @throws ModelException
     */
    protected function sampleItems(Resource\User\Import $oImport, ItemStatus $oStatus, int $iLimit): array
    {
        /** @var Model\User\Import\Item $oItemModel */
        $oItemModel = Factory::model('UserImportItem', Constants::MODULE_SLUG);
        /** @var Database $oDb */
        $oDb = Factory::service('Database');

        $aRows = $oDb
            ->select('line, message')
            ->where('import_id', $oImport->id)
            ->where('status', $oStatus->value)
            ->order_by('line', 'asc')
            ->limit($iLimit)
            ->get($oItemModel->getTableName())
            ->result();

        return array_map(
            fn($oRow): array => [
                'line'    => (int) $oRow->line,
                'message' => $oRow->message,
            ],
            $aRows
        );
    }

    // --------------------------------------------------------------------------

    /**
     * Reduces a composed error to the part which is safe to email
     *
     * Keeps the summary and the phase; drops the quoted rows, the exception and
     * the file it came from. The rows name people and the cause can quote one
     * too - a driver reporting a duplicate key repeats the address - so none of
     * it belongs in an inbox. The stored error keeps everything; this is only
     * what the notification is allowed to repeat.
     */
    protected function summariseError(?string $sError): ?string
    {
        if (empty($sError)) {
            return null;
        }

        $aBlocks  = explode("\n\n", $sError);
        $sSummary = array_shift($aBlocks);

        $aPhase = array_filter(
            explode("\n", implode("\n", $aBlocks)),
            fn(string $sLine): bool => str_starts_with($sLine, static::ERROR_FACT_PHASE)
        );

        return implode("\n\n", [$sSummary, ...$aPhase]);
    }

    // --------------------------------------------------------------------------

    /**
     * Notes, on the end of the job's error, that the log could not be attached
     */
    protected function appendLogFailure(string $sError, string $sReason): string
    {
        return sprintf(
            "%s\n\nThe error log could not be attached: %s",
            $sError,
            $sReason
        );
    }

    // --------------------------------------------------------------------------

    /**
     * Bounds the job's error
     *
     * The column is TEXT, so this guards against a pathological CSV rather than
     * against the schema.
     */
    protected function truncateError(string $sError): string
    {
        /**
         * Multibyte-aware: the composed error quotes cell values straight out of
         * the CSV, and cutting one of those mid-character would leave invalid
         * UTF-8 in the column.
         */
        return mb_strlen($sError) > static::ERROR_MAX_LENGTH
            ? mb_substr($sError, 0, static::ERROR_MAX_LENGTH) . '…'
            : $sError;
    }

    // --------------------------------------------------------------------------

    /**
     * The summary line of a composed error, for places with room for one line
     */
    protected function firstLine(string $sError): string
    {
        return explode("\n", $sError, 2)[0];
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the local path of the job's source CSV
     *
     * @throws FactoryException
     * @throws ValidationException
     */
    protected function getSourcePath(Resource\User\Import $oImport): string
    {
        $sPath = $this->getCdn()->objectLocalPath($oImport->object_id);

        if (empty($sPath)) {
            throw new ValidationException(
                'Failed to get a local path for the CSV file'
            );
        }

        return $sPath;
    }

    // --------------------------------------------------------------------------

    /**
     * Re-reads the job so callers always see live state
     *
     * @throws FactoryException
     * @throws ModelException
     */
    protected function refresh(Resource\User\Import $oImport): Resource\User\Import
    {
        $oModel = $this->getModel();

        /** @var Resource\User\Import|null $oRefreshed */
        $oRefreshed = $oModel->getById($oImport->id);

        return $oRefreshed ?? $oImport;
    }

    // --------------------------------------------------------------------------

    /**
     * The system keys which mark the log as belonging to a user import
     *
     * The UserImport key is what stops the CDN's unused-object monitor reaping
     * the log; see Cdn\Monitor\User\ImportCsv. Broken out as its own method
     * because it is the one part of storing the log which needs module-cdn's
     * MetaData interfaces, and so the one part this module cannot exercise on
     * its own.
     *
     * @return array<int, array{key: string, value: mixed}>
     */
    protected function getLogMetaData(Resource\User\Import $oImport): array
    {
        return [
            [
                'key'   => (new SystemKey\UserImport())->get(),
                'value' => true,
            ],
            [
                'key'   => (new SystemKey\ImportedFrom())->get(),
                'value' => $oImport->object_id,
            ],
        ];
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the logger
     *
     * Broken out so that what a job reports can be asserted without writing to
     * the filesystem, as Model\User\Import::getDb() does for the database.
     *
     * @throws FactoryException
     */
    protected function getLogger(): Logger
    {
        /** @var Logger $oLogger */
        $oLogger = Factory::service('Logger');
        return $oLogger;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the CDN service
     *
     * Broken out so that the log-attach contract can be exercised against each
     * of objectCreate()'s three outcomes without standing up a CDN.
     *
     * @throws FactoryException
     */
    protected function getCdn(): Cdn\Service\Cdn
    {
        /** @var Cdn\Service\Cdn $oCdn */
        $oCdn = Factory::service('Cdn', Cdn\Constants::MODULE_SLUG);
        return $oCdn;
    }

    // --------------------------------------------------------------------------

    /**
     * Hands the finished job to the app, keeping hold of the reason if it objects
     *
     * Deliberately unable to change the job's status: every account which was
     * going to be created exists by the time this runs, so a hook which throws
     * is a note against a finished import, not a failed one. The reason is
     * appended to `error` rather than replacing it - complete() may already have
     * recorded that the log could not be attached, and both matter.
     *
     * @throws FactoryException
     * @throws ModelException
     */
    protected function fireImportComplete(Resource\User\Import $oImport): Resource\User\Import
    {
        try {
            $this->getImportService()->onImportComplete($oImport);

            return $oImport;

        } catch (Throwable $e) {

            $this->report($oImport, 'onImportComplete() failed', $e);

            $sError = $this->truncateError(trim(sprintf(
                "%s\n\n%s",
                (string) $oImport->error,
                sprintf(
                    'The import itself finished normally, but the application '
                    . 'reported a problem afterwards: %s',
                    $e->getMessage()
                )
            )));

            if (!$this->getModel()->update($oImport->id, ['error' => $sError])) {
                $this->report($oImport, sprintf(
                    'failed to record the onImportComplete() failure; %s',
                    $this->getModel()->lastError() ?: 'no reason was reported'
                ));
            }

            return $this->refresh($oImport);
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Tells the app the job was rejected
     *
     * Reported and otherwise swallowed. Nothing may escape: fail() is reached
     * from process()'s catch-all, so a throw from here would recurse straight
     * back into it - the same reason fail()'s own update is checked rather than
     * asserted. The reason is deliberately not written to the job either; that
     * column holds the composed explanation of why the import was rejected, and
     * this is not it.
     */
    protected function fireImportFailed(Resource\User\Import $oImport): void
    {
        try {
            $this->getImportService()->onImportFailed($oImport);

        } catch (Throwable $e) {
            $this->report($oImport, 'onImportFailed() failed', $e);
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the user model
     *
     * Broken out alongside getImportService(): the per-row loop is where both
     * row hooks fire, and what they do to a row cannot be asserted without
     * standing an account creation up otherwise.
     *
     * @throws FactoryException
     */
    protected function getUserModel(): Model\User
    {
        /** @var Model\User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);
        return $oUserModel;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the import service, which is where an app hooks into a job
     *
     * Broken out for the same reason Validator::getImportService() is: the app
     * subclasses it, so what a hook does to a job has to be assertable without
     * standing one up.
     *
     * @throws FactoryException
     */
    protected function getImportService(): ImportService
    {
        /** @var ImportService $oImportService */
        $oImportService = Factory::service('UserImport', Constants::MODULE_SLUG);
        return $oImportService;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the job model
     *
     * @throws FactoryException
     */
    protected function getModel(): Model\User\Import
    {
        /** @var Model\User\Import $oModel */
        $oModel = Factory::model('UserImport', Constants::MODULE_SLUG);
        return $oModel;
    }
}

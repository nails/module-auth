<?php

/**
 * This class provides the ability to import users from a CSV
 *
 * The controller is only responsible for accepting the upload and for
 * approving a job; everything else — reading the CSV, validating it, and
 * creating the users — happens in the background, driven by
 * \Nails\Auth\Service\User\Import\Processor.
 *
 * Deletion lives on \Nails\Auth\Api\Controller\Import so that the admin UI can
 * drive it with fetch() and drop a row from the list without a reload.
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    AdminController
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Admin\Auth;

use Nails\Admin\Helper;
use Nails\Auth\Cdn\MetaData\SystemKey;
use Nails\Auth\Constants;
use Nails\Auth\Controller\BaseAdmin;
use Nails\Auth\Enum\User\Import\Status;
use Nails\Auth\Exception\User\Import\TemplateException;
use Nails\Auth\Model;
use Nails\Auth\Resource;
use Nails\Auth\Service\User\Import\Csv;
use Nails\Auth\Service\User\Import\Dispatcher;
use Nails\Auth\Service\User\Import\Processor;
use Nails\Auth\Service\User\Import\Validator;
use Nails\Cdn;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\ModelException;
use Nails\Common\Exception\NailsException;
use Nails\Common\Exception\ValidationException;
use Nails\Common\Service\FormValidation;
use Nails\Common\Service\Input;
use Nails\Common\Service\Uri;
use Nails\Factory;
use RuntimeException;

/**
 * Class Import
 *
 * @package Nails\Admin\Auth
 */
class Import extends BaseAdmin
{
    /**
     * The bucket the source CSV and its log are stored in
     *
     * @var array
     */
    const array IMPORT_BUCKET = Processor::IMPORT_BUCKET;

    /**
     * The permission required to work with imports
     *
     * @var string
     */
    const PERMISSION = 'admin:auth:accounts:create';

    /**
     * Where the controller lives
     *
     * @var string
     */
    const URL = 'admin/auth/import';

    /**
     * The number of per-line errors reported when an upload is rejected
     *
     * A wholly malformed file produces one per row; the first few say everything
     * the thousandth does. Matches Processor::ERROR_SAMPLE_SIZE.
     *
     * @var int
     */
    const ERROR_SAMPLE_SIZE = Processor::ERROR_SAMPLE_SIZE;

    /**
     * How long the expiring CDN URL a download hands out is valid for, in seconds
     *
     * Shorter than module-admin's ADMIN_DATA_EXPORT_URL_TTL (300, see
     * Nails\Admin\Service\DataExport::EXPORT_TTL) because the token only has to
     * survive the 302 hop; a visitor who was not signed in re-enters log() after
     * logging in and is issued a fresh one.
     *
     * @var int
     */
    const int URL_TTL = 60;

    // --------------------------------------------------------------------------

    /**
     * Import users from CSV
     *
     * @return void
     * @throws FactoryException
     * @throws ModelException
     */
    public function index(): void
    {
        $this->assertPermission();
        $this->assertRunning();

        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        /** @var \Nails\Auth\Service\User\Import $oImportService */
        $oImportService = Factory::service('UserImport', Constants::MODULE_SLUG);

        try {
            /**
             * On GET as well as POST: a template which cannot identify an account
             * must not be offered for upload in the first place.
             */
            $this->assertTemplate();

        } catch (TemplateException $e) {
            $this->oUserFeedback->error($this->escape($e->getMessage()));
            $this->data['bTemplateUnusable'] = true;
        }

        if ($oInput->post() && empty($this->data['bTemplateUnusable'])) {
            try {

                $this->handleUpload();
                return;

            } catch (ValidationException $e) {

                /**
                 * Escaped on the way in: UserFeedback messages are rendered raw
                 * so that the markup below survives, and both the message and
                 * the itemised errors quote cell and header values lifted
                 * straight out of the uploaded CSV.
                 */
                $sMessage = $this->escape($e->getMessage());
                $aErrors  = $e->getData() ?? [];

                if (!empty($aErrors)) {
                    $sMessage .= sprintf(
                        ':<div class="alert alert-warning" style="%s">%s</div>',
                        implode(';', [
                            'max-height: 10rem',
                            'overflow: auto',
                            'margin-bottom: 0;',
                        ]),
                        implode('<br>', array_map([$this, 'escape'], $aErrors))
                    );
                }

                $this->oUserFeedback->error($sMessage);

            } catch (\Exception $e) {
                $this->oUserFeedback->error($this->escape($e->getMessage()));
            }
        }

        // --------------------------------------------------------------------------

        $this->data['page']->title      = 'Users &rsaquo; Import';
        $this->data['additionalFields'] = $oImportService->getAdditionalFields();
        Helper::loadView('index');
    }

    // --------------------------------------------------------------------------

    /**
     * Generates a CSV template to upload
     *
     * @return void
     * @throws FactoryException
     */
    public function template(): void
    {
        $this->assertPermission();
        $this->assertTemplate();

        /** @var \Nails\Auth\Service\User\Import $oImportService */
        $oImportService = Factory::service('UserImport', Constants::MODULE_SLUG);

        $aKeys    = $oImportService->getKeys();
        $aExample = array_map(
            fn($key) => rtrim(
                trim(
                    sprintf(
                        '%s: %s',
                        in_array(FormValidation::RULE_REQUIRED, $oImportService->getValidationRules($key))
                            ? 'Required'
                            : 'Optional',
                        $oImportService->getExample($key)
                    )
                ),
                ':'
            ),
            $aKeys
        );

        Helper::loadCsv(
            [
                array_combine($aKeys, $aKeys),
                array_combine($aKeys, $aExample),
            ],
            'import-users.csv'
        );
    }

    // --------------------------------------------------------------------------

    /**
     * Renders the shell of the preview; the rows themselves are paged in from
     * the API
     *
     * @return void
     * @throws FactoryException
     * @throws ModelException
     */
    public function preview(): void
    {
        $this->assertPermission();
        $this->assertRunning();

        $oImport = $this->getImport();

        /** @var \Nails\Auth\Service\User\Import $oImportService */
        $oImportService = Factory::service('UserImport', Constants::MODULE_SLUG);

        try {
            $aKeys = $this->getHeader($oImport);
        } catch (ValidationException $e) {
            //  The CSV has gone; show the columns we would have expected
            $this->oUserFeedback->error($this->escape($e->getMessage()));
            $aKeys = [];
        }

        $this->data['oImport']     = $oImport;
        $this->data['bCsvMissing'] = empty($aKeys);
        $this->data['aRegistered'] = $this->getRegistered($oImport);
        $this->data['aKeys']       = $aKeys ?: $oImportService->getKeys();
        $this->data['sApproveUrl'] = static::URL . '/approve/' . $oImport->id;
        $this->data['sListUrl']    = static::URL;
        $this->data['page']->title = sprintf(
            'Users &rsaquo; Import &rsaquo; Preview (#%s &mdash; %s rows)',
            $oImport->id,
            $oImport->row_count ?? 0
        );

        Helper::loadView('preview');
    }

    // --------------------------------------------------------------------------

    /**
     * Approves a draft import and hands it to a runner
     *
     * @return void
     * @throws FactoryException
     * @throws ModelException
     */
    public function approve(): void
    {
        $this->assertPermission();
        $this->assertPost();

        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        /** @var Dispatcher $oDispatcher */
        $oDispatcher = Factory::service('UserImportDispatcher', Constants::MODULE_SLUG);
        /** @var Validator $oValidator */
        $oValidator = Factory::service('UserImportValidator', Constants::MODULE_SLUG);
        /** @var Model\User\Import $oModel */
        $oModel = Factory::model('UserImport', Constants::MODULE_SLUG);

        $oImport = $this->getImport();

        try {

            if ($oImport->status !== Status::DRAFT) {
                throw new ValidationException(
                    'Only draft imports can be approved'
                );
            }

            $this->assertTemplate();

            //  The file has been sat in the CDN since it was uploaded; make sure
            //  it is still there, and still makes sense, before committing to it
            $oValidator->validateHeader($this->getHeader($oImport));

            /**
             * Registration is state, so it is re-checked here rather than trusted
             * from the upload. Without the admin's consent to skip them, a job
             * with registered rows would reach a runner only to be rejected, so
             * it is refused now while there is somebody to tell.
             */
            $bSkipRegistered = (bool) $oInput->post('skip_registered');
            $aRegistered     = $this->getRegistered($oImport);

            if (!empty($aRegistered) && !$bSkipRegistered) {
                $this->assertNoErrors($this->flattenRowErrors($aRegistered));
            }

            $oModel->update($oImport->id, [
                'skip_registered' => $bSkipRegistered,
            ]);

            $oDispatcher->dispatch($oImport);

            $this->oUserFeedback->success(sprintf(
                'Import #%s has been queued and will begin shortly.',
                $oImport->id
            ));

        } catch (\Exception $e) {
            $this->oUserFeedback->error($this->escape($e->getMessage()));
        }

        redirect(static::URL);
    }

    // --------------------------------------------------------------------------

    /**
     * Redirects to a short-lived URL for the job's source CSV
     *
     * @return void
     * @throws FactoryException
     * @throws ModelException
     */
    public function source(): void
    {
        $this->assertPermission();
        $this->download($this->getImport()->object_id);
    }

    // --------------------------------------------------------------------------

    /**
     * Redirects to a short-lived URL for the job's log
     *
     * @return void
     * @throws FactoryException
     * @throws ModelException
     */
    public function log(): void
    {
        $this->assertPermission();
        $this->download($this->getImport()->log_id);
    }

    // --------------------------------------------------------------------------

    /**
     * Redirects to a short-lived URL for one of the job's CDN objects
     *
     * The source CSV and the log both contain personal data, so neither is ever
     * linked directly: the URL is minted here, behind the permission check, and
     * expires. It does end up in the address bar and the Referer of the CDN
     * request - the same trade module-admin's data export makes - which is why
     * the TTL is measured in seconds.
     *
     * Reading personal data sits behind a create permission because that is the
     * permission which governs the whole of this controller; the ability to run
     * an import is the ability to read its file.
     *
     * @param int|null $iObjectId The CDN object to hand out
     *
     * @return void
     */
    protected function download(?int $iObjectId): void
    {
        if (empty($iObjectId)) {
            //  No log was attached, or the object has since been destroyed
            show404();
        }

        redirect(cdnExpiringUrl($iObjectId, static::URL_TTL, true));
    }

    // --------------------------------------------------------------------------

    /**
     * Validates the upload, stores it, and records the job
     *
     * Nothing is persisted unless the file is usable; a rejected upload leaves
     * neither a CDN object nor a job behind.
     *
     * @throws FactoryException
     * @throws ModelException
     * @throws NailsException
     * @throws ValidationException
     */
    protected function handleUpload(): void
    {
        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        /** @var Csv $oCsv */
        $oCsv = Factory::service('UserImportCsv', Constants::MODULE_SLUG);
        /** @var Model\User\Import $oModel */
        $oModel = Factory::model('UserImport', Constants::MODULE_SLUG);

        $aFile     = $this->validateUpload();
        $iRowCount = $oCsv->countRows($aFile['tmp_name']);
        $oObject   = $this->uploadCsv();

        $iId = $oModel->create([
            'object_id'  => $oObject->id,
            'additional' => json_encode((object) ($oInput->post('additional') ?: [])),
            'row_count'  => $iRowCount,
            'status'     => Status::DRAFT->value,
        ]);

        if (empty($iId)) {
            throw new RuntimeException(sprintf(
                'Failed to record the import; %s',
                $oModel->lastError()
            ));
        }

        redirect(static::URL . '/preview/' . $iId);
    }

    // --------------------------------------------------------------------------

    /**
     * Validates the CSV file upload
     *
     * Only whole-of-file concerns are checked here — that there is a file, that
     * it is a CSV, that its header is one we understand, and that it does not
     * contradict itself. Row level validation is the runner's job.
     *
     * @return array The $_FILES entry
     * @throws FactoryException
     * @throws ModelException
     * @throws NailsException
     * @throws ValidationException
     */
    protected function validateUpload(): array
    {
        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        /** @var Cdn\Service\Cdn $oCdn */
        $oCdn = Factory::service('Cdn', Cdn\Constants::MODULE_SLUG);
        /** @var Csv $oCsv */
        $oCsv = Factory::service('UserImportCsv', Constants::MODULE_SLUG);
        /** @var Validator $oValidator */
        $oValidator = Factory::service('UserImportValidator', Constants::MODULE_SLUG);

        $aFile = $oInput::file('csv');
        if (empty($aFile)) {
            throw new ValidationException('No file selected for upload');
        }

        if ($aFile['error'] !== UPLOAD_ERR_OK) {
            throw new ValidationException(
                $oCdn::getUploadError($aFile['error'])
            );
        }

        $sMime = $oCdn->getMimeFromFile($aFile['tmp_name']);
        if ($aFile['type'] !== 'text/csv' && $sMime !== 'text/plain') {
            throw new ValidationException(
                'Uploaded file is not a CSV'
            );
        }

        $aHeader = $oCsv->getHeader($aFile['tmp_name']);

        $oValidator->validateHeader($aHeader);

        $this->assertNoErrors(
            $oValidator->detectDuplicates($aFile['tmp_name'])
        );

        /**
         * Row validation used to be left entirely to the runner, which meant an
         * admin could approve - and commit to - a file which could never import.
         * The rows are streamed, so this holds no more than one at a time, and
         * the request already reads the whole file twice before reaching here.
         *
         * Only "the CSV is wrong" problems are checked. Values which are already
         * registered are a separate, skippable concern handled at preview; see
         * Import\Validator::detectRegistered().
         */
        $this->assertNoErrors(
            $this->flattenRowErrors(
                $oValidator->validateRows($aHeader, $oCsv->readRawRows($aFile['tmp_name']))
            )
        );

        return $aFile;
    }

    // --------------------------------------------------------------------------

    /**
     * Renders per-line errors as flat, prefixed strings
     *
     * Matches detectDuplicates()'s output so both can go through the same
     * reporting path.
     *
     * @param array<int, string[]> $aErrors Errors keyed by line number
     *
     * @return string[]
     */
    protected function flattenRowErrors(array $aErrors): array
    {
        $aOut = [];

        foreach ($aErrors as $iLine => $aLineErrors) {
            foreach ($aLineErrors as $sError) {
                $aOut[] = sprintf('Line %d: %s', $iLine, $sError);
            }
        }

        return $aOut;
    }

    // --------------------------------------------------------------------------

    /**
     * Rejects the upload if anything was found
     *
     * The list is capped: a wholly malformed file can produce an error for every
     * row, and thousands of near-identical lines tell an admin less than the
     * first few do.
     *
     * @param string[] $aErrors
     *
     * @throws ValidationException
     */
    protected function assertNoErrors(array $aErrors): void
    {
        if (empty($aErrors)) {
            return;
        }

        $iTotal = count($aErrors);

        $sMessage = $iTotal === 1
            ? '1 error was found in the CSV file'
            : sprintf('%s errors were found in the CSV file', number_format($iTotal));

        if ($iTotal > static::ERROR_SAMPLE_SIZE) {
            $aErrors   = array_slice($aErrors, 0, static::ERROR_SAMPLE_SIZE);
            $aErrors[] = sprintf(
                '…and %s more',
                number_format($iTotal - static::ERROR_SAMPLE_SIZE)
            );
        }

        throw (new ValidationException($sMessage))
            ->setData($aErrors);
    }

    // --------------------------------------------------------------------------

    /**
     * @return Cdn\Resource\CdnObject
     * @throws FactoryException
     * @throws ModelException
     * @throws ValidationException
     */
    protected function uploadCsv(): Cdn\Resource\CdnObject
    {
        /** @var Cdn\Service\Cdn $oCdn */
        $oCdn = Factory::service('Cdn', Cdn\Constants::MODULE_SLUG);
        /** @var Cdn\Model\CdnObject $oObjectModel */
        $oObjectModel = Factory::model('Object', Cdn\Constants::MODULE_SLUG);

        $oObject = $oCdn->objectCreate(
            'csv',
            static::IMPORT_BUCKET,
            [
                'Content-Type' => 'text/csv',
                'metadata'     => [
                    [
                        'key'   => (new SystemKey\UserImport())->get(),
                        'value' => true,
                    ],
                ],
            ]
        );

        if (!$oObject) {
            throw new ValidationException(sprintf(
                'Failed to upload CSV; %s',
                $oCdn->lastError()
            ));
        }

        /** @var Cdn\Resource\CdnObject $oObject */
        $oObject = $oObjectModel->getById($oObject->id);
        return $oObject;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the import named by the URL, or 404s
     *
     * @throws FactoryException
     * @throws ModelException
     */
    protected function getImport(): Resource\User\Import
    {
        /** @var Uri $oUri */
        $oUri = Factory::service('Uri');
        /** @var Model\User\Import $oModel */
        $oModel = Factory::model('UserImport', Constants::MODULE_SLUG);

        /** @var Resource\User\Import|null $oImport */
        $oImport = $oModel->getById((int) $oUri->segment(5));

        if (empty($oImport)) {
            show404();
        }

        return $oImport;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the header row of an import's CSV
     *
     * @return string[]
     * @throws FactoryException
     * @throws ValidationException
     */
    protected function getHeader(Resource\User\Import $oImport): array
    {
        /** @var Csv $oCsv */
        $oCsv = Factory::service('UserImportCsv', Constants::MODULE_SLUG);

        return $oCsv->getHeader($this->getCsvPath($oImport));
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the local path of an import's CSV
     *
     * @throws FactoryException
     * @throws ValidationException If the file is no longer retrievable
     */
    protected function getCsvPath(Resource\User\Import $oImport): string
    {
        /** @var Cdn\Service\Cdn $oCdn */
        $oCdn = Factory::service('Cdn', Cdn\Constants::MODULE_SLUG);

        $sPath = $oCdn->objectLocalPath($oImport->object_id);
        if (empty($sPath)) {
            throw new ValidationException(
                'Failed to get a local path for the CSV file'
            );
        }

        return $sPath;
    }

    // --------------------------------------------------------------------------

    /**
     * @throws FactoryException
     */
    /**
     * Escapes a value destined for a user feedback message
     *
     * UserFeedback messages are rendered raw - see module-admin's
     * page-header.php - and every message this controller reports quotes header
     * or cell values taken from an uploaded CSV, so they are escaped here rather
     * than trusted downstream.§
     */
    protected function escape(?string $sValue): string
    {
        return htmlspecialchars((string) $sValue, ENT_QUOTES, 'UTF-8');
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the job's rows whose unique values are already registered
     *
     * Recomputed rather than stored: registration is state which can change
     * between upload and approval, and a stale answer here would either block a
     * job which is now fine or wave through one which is not.
     *
     * Only meaningful before the job runs, so a job which is past DRAFT is not
     * made to pay for the pass.
     *
     * @return array<int, string[]> Errors keyed by line number
     * @throws FactoryException
     */
    protected function getRegistered(Resource\User\Import $oImport): array
    {
        /** @var Csv $oCsv */
        $oCsv = Factory::service('UserImportCsv', Constants::MODULE_SLUG);
        /** @var Validator $oValidator */
        $oValidator = Factory::service('UserImportValidator', Constants::MODULE_SLUG);

        if ($oImport->status !== Status::DRAFT) {
            return [];
        }

        try {
            $sPath = $this->getCsvPath($oImport);

        } catch (ValidationException $e) {
            //  The CSV has gone; the caller has already reported that
            return [];
        }

        return $oValidator->detectRegistered(
            $oCsv->getHeader($sPath),
            $oCsv->readRawRows($sPath)
        );
    }

    // --------------------------------------------------------------------------

    /**
     * Asserts the import template can produce a usable account
     *
     * @throws TemplateException
     * @throws FactoryException
     */
    protected function assertTemplate(): void
    {
        /** @var Validator $oValidator */
        $oValidator = Factory::service('UserImportValidator', Constants::MODULE_SLUG);

        $oValidator->validateTemplate();
    }

    // --------------------------------------------------------------------------

    protected function assertPermission(): void
    {
        if (!userHasPermission(static::PERMISSION)) {
            unauthorised();
        }
    }

    protected function assertRunning(): void
    {
        /** @var Dispatcher $oDispatcher */
        $oDispatcher = Factory::service('UserImportDispatcher', Constants::MODULE_SLUG);

        if (!$oDispatcher->isRunning()) {
            $this->oUserFeedback->warning(
                '<strong>The user import cron job is not running</strong>' .
                '<br>The cron job has not been executed within the past 5 minutes; imports will not be processed.'
            );
        }
    }

    // --------------------------------------------------------------------------

    /**
     * State changes are POST only so they cannot be triggered by a link
     *
     * @throws FactoryException
     */
    protected function assertPost(): void
    {
        /** @var Input $oInput */
        $oInput = Factory::service('Input');

        if (strtoupper((string) $oInput::server('REQUEST_METHOD')) !== 'POST') {
            show404();
        }
    }
}

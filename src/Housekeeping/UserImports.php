<?php

namespace Nails\Auth\Housekeeping;

use DateInterval;
use Nails\Auth\Constants;
use Nails\Auth\Enum\User\Import\Status;
use Nails\Auth\Model;
use Nails\Auth\Resource;
use Nails\Common\Service\Database;
use Nails\Config;
use Nails\Factory;
use Nails\Housekeeping\Routine\Base;
use Nails\Housekeeping\Routine\Context;
use Nails\Housekeeping\Routine\Result;

class UserImports extends Base
{
    /**
     * How long, in seconds, a claim may go without the cursor moving before it
     * is considered orphaned
     */
    const STALE_CLAIM = 900;

    /**
     * How long, in seconds, an unapproved job is kept before it is reaped
     */
    const DRAFT_TTL = 86400;

    /**
     * How long, in seconds, a finished job is kept before it is rotated out
     */
    const RETENTION = 2592000;

    /**
     * The maximum number of jobs to delete in a single run
     */
    const MAX_PER_RUN = 100;

    const LABEL           = 'User imports';
    const DESCRIPTION     = 'Releases orphaned user import claims and deletes abandoned drafts and finished jobs';
    const CRON_EXPRESSION = '*/15 * * * *';

    public function execute(Context $oContext): Result
    {
        $iReleased = $this->releaseOrphans($oContext);
        $iDrafts   = $this->reapDrafts($oContext);
        $iFinished = $this->rotateFinished($oContext);

        return Result::ok($iReleased + $iDrafts + $iFinished);
    }

    /**
     * Releases claims held by processes which are no longer with us.
     *
     * The status is deliberately left alone; a job resumes from its cursor, and
     * sending it back to PENDING would restart it from the top and re-validate
     * rows whose users have since been created.
     */
    protected function releaseOrphans(Context $oContext): int
    {
        $oContext->writeln('<comment>Releasing orphaned claims</comment>');

        /** @var Model\User\Import $oModel */
        $oModel = Factory::model('UserImport', Constants::MODULE_SLUG);
        /** @var Database $oDb */
        $oDb = Factory::service('Database');

        $sCutOff    = $this->getCutOff('AUTH_USER_IMPORT_STALE_CLAIM', static::STALE_CLAIM);
        $iProcessed = 0;
        $iLastId    = 0;

        while (true) {
            $aRows = $oDb
                ->select('id, status, modified, claimed')
                ->where('claim_token IS NOT NULL', null, false)
                ->where('claimed <', $sCutOff)
                ->where('id >', $iLastId)
                ->order_by('id', 'asc')
                ->limit(200)
                ->get($oModel->getTableName())
                ->result();

            if (empty($aRows)) {
                break;
            }

            $aIds = [];
            foreach ($aRows as $oRow) {
                $iId       = (int) $oRow->id;
                $iLastId   = $iId;
                $aIds[]    = $iId;
                $sAudit    = sprintf(
                    'id=%d status=%s modified=%s claimed=%s',
                    $iId,
                    (string) $oRow->status,
                    (string) $oRow->modified,
                    (string) $oRow->claimed
                );
                $oContext
                    ->log('RELEASE ' . $sAudit)
                    ->writeln(' ↳ ' . $sAudit);
            }

            if (!$oContext->isDryRun()) {
                $oDb
                    ->set('claim_token', null)
                    ->set('claimed', null)
                    ->where_in('id', $aIds)
                    ->update($oModel->getTableName());
            }

            $iProcessed += count($aIds);
        }

        $oContext->writeln(sprintf(
            'Released <info>%s</info>',
            number_format($iProcessed)
        ));

        return $iProcessed;
    }

    protected function reapDrafts(Context $oContext): int
    {
        $oContext->writeln('');
        $oContext->writeln('<comment>Reaping abandoned drafts</comment>');

        $iDeleted = $this->deleteJobs(
            $oContext,
            [Status::DRAFT],
            $this->getCutOff('AUTH_USER_IMPORT_DRAFT_TTL', static::DRAFT_TTL)
        );

        $oContext->writeln(sprintf('Deleted <info>%s</info>', number_format($iDeleted)));

        return $iDeleted;
    }

    protected function rotateFinished(Context $oContext): int
    {
        $oContext->writeln('');
        $oContext->writeln('<comment>Rotating finished jobs</comment>');

        $iDeleted = $this->deleteJobs(
            $oContext,
            Status::terminal(),
            $this->getCutOff('AUTH_USER_IMPORT_RETENTION', static::RETENTION)
        );

        $oContext->writeln(sprintf('Deleted <info>%s</info>', number_format($iDeleted)));

        return $iDeleted;
    }

    /**
     * @param Status[] $aStatuses
     */
    protected function deleteJobs(Context $oContext, array $aStatuses, string $sCutOff): int
    {
        /** @var Model\User\Import $oModel */
        $oModel = Factory::model('UserImport', Constants::MODULE_SLUG);
        /** @var Database $oDb */
        $oDb = Factory::service('Database');

        $aRows = $oDb
            ->select('id')
            ->where_in('status', Status::values($aStatuses))
            ->where('modified <', $sCutOff)
            ->order_by('id', 'asc')
            ->limit(static::MAX_PER_RUN)
            ->get($oModel->getTableName())
            ->result();

        $iDeleted = 0;

        foreach ($aRows as $oRow) {
            /** @var Resource\User\Import|null $oImport */
            $oImport = $oModel->getById((int) $oRow->id);
            if (empty($oImport)) {
                continue;
            }

            $sStatus = $oImport->status instanceof Status
                ? $oImport->status->value
                : (string) $oImport->status;
            $sAudit  = sprintf(
                'id=%d status=%s modified=%s',
                (int) $oImport->id,
                $sStatus,
                $this->stringifyDate($oImport->modified)
            );

            $oContext
                ->log('DELETE ' . $sAudit)
                ->writeln(' ↳ ' . $sAudit);

            if ($oContext->isDryRun()) {
                $iDeleted++;
                continue;
            }

            // The job goes first; the CDN objects cascade onto it, and a
            // half-deleted job is worse than a lingering file.
            if (!$oModel->delete($oImport->id)) {
                $oContext
                    ->log('ERROR id=' . $oImport->id . ' ' . $oModel->lastError())
                    ->writeln(sprintf(
                        '↳ <error>Failed to delete import #%s; %s</error>',
                        $oImport->id,
                        $oModel->lastError()
                    ));
                continue;
            }

            foreach ($oModel->destroyObjects($oImport) as $iObjectId => $sError) {
                $oContext
                    ->log('ERROR cdn_object=' . $iObjectId . ' ' . $sError)
                    ->writeln(sprintf(
                        '↳ <error>Failed to destroy CDN object #%s; %s</error>',
                        $iObjectId,
                        $sError
                    ));
            }

            $iDeleted++;
        }

        return $iDeleted;
    }

    protected function getCutOff(string $sConfigKey, int $iDefault): string
    {
        $iSeconds = (int) Config::get($sConfigKey, $iDefault) ?: $iDefault;

        /** @var \DateTime $oCutOff */
        $oCutOff = Factory::factory('DateTime');
        $oCutOff->sub(new DateInterval('PT' . $iSeconds . 'S'));

        return $oCutOff->format('Y-m-d H:i:s');
    }

    protected function stringifyDate(mixed $mValue): string
    {
        if ($mValue === null) {
            return 'null';
        }

        if ($mValue instanceof \DateTimeInterface) {
            return $mValue->format('Y-m-d H:i:s');
        }

        return (string) $mValue;
    }
}

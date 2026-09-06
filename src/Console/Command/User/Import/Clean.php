<?php

namespace Nails\Auth\Console\Command\User\Import;

use DateInterval;
use Nails\Auth\Constants;
use Nails\Auth\Enum\User\Import\Status;
use Nails\Auth\Model;
use Nails\Auth\Resource;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\ModelException;
use Nails\Common\Service\Database;
use Nails\Config;
use Nails\Console\Command\Base;
use Nails\Factory;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Class Clean
 *
 * @package Nails\Auth\Console\Command\User\Import
 */
class Clean extends Base
{
    /**
     * How long, in seconds, a claim may go without the cursor moving before it
     * is considered orphaned
     *
     * @var int
     */
    const STALE_CLAIM = 900;

    /**
     * How long, in seconds, an unapproved job is kept before it is reaped
     *
     * @var int
     */
    const DRAFT_TTL = 86400;

    /**
     * How long, in seconds, a finished job is kept before it is rotated out
     *
     * @var int
     */
    const RETENTION = 2592000;

    /**
     * The maximum number of jobs to delete in a single run
     *
     * @var int
     */
    const MAX_PER_RUN = 100;

    // --------------------------------------------------------------------------

    /**
     * Configure the command
     */
    protected function configure(): void
    {
        $this
            ->setName('auth:user:import:clean')
            ->setDescription('Releases orphaned user import jobs and reaps old ones');
    }

    // --------------------------------------------------------------------------

    /**
     * Executes the command
     *
     * @param InputInterface  $oInput  The Input Interface provided by Symfony
     * @param OutputInterface $oOutput The Output Interface provided by Symfony
     */
    protected function execute(InputInterface $oInput, OutputInterface $oOutput): int
    {
        parent::execute($oInput, $oOutput);

        try {

            $this->banner('User Import: Clean');
            $this
                ->releaseOrphans()
                ->reapDrafts()
                ->rotateFinished();

        } catch (Throwable $e) {
            return $this->abort(
                self::EXIT_CODE_FAILURE,
                [$e->getMessage()]
            );
        }

        $oOutput->writeln('');
        $oOutput->writeln('Complete!');

        return self::EXIT_CODE_SUCCESS;
    }

    // --------------------------------------------------------------------------

    /**
     * Releases claims held by processes which are no longer with us
     *
     * The status is deliberately left alone; a job resumes from its cursor, and
     * sending it back to PENDING would restart it from the top and re-validate
     * rows whose users have since been created.
     *
     * @throws FactoryException
     * @throws ModelException
     */
    protected function releaseOrphans(): self
    {
        $this->oOutput->writeln('<comment>Releasing orphaned claims</comment>');

        /** @var Model\User\Import $oModel */
        $oModel = Factory::model('UserImport', Constants::MODULE_SLUG);
        /** @var Database $oDb */
        $oDb = Factory::service('Database');

        $oDb
            ->set('claim_token', null)
            ->set('claimed', null)
            ->where('claim_token IS NOT NULL', null, false)
            ->where('claimed <', $this->getCutOff('AUTH_USER_IMPORT_STALE_CLAIM', static::STALE_CLAIM))
            ->update($oModel->getTableName());

        $this->oOutput->writeln(sprintf(
            'Released <info>%s</info>',
            $oDb->affected_rows()
        ));

        return $this;
    }

    // --------------------------------------------------------------------------

    /**
     * Deletes uploads which were never approved
     *
     * @throws FactoryException
     * @throws ModelException
     */
    protected function reapDrafts(): self
    {
        $this->oOutput->writeln('');
        $this->oOutput->writeln('<comment>Reaping abandoned drafts</comment>');

        $iDeleted = $this->deleteJobs(
            [Status::DRAFT],
            $this->getCutOff('AUTH_USER_IMPORT_DRAFT_TTL', static::DRAFT_TTL)
        );

        $this->oOutput->writeln(sprintf('Deleted <info>%s</info>', $iDeleted));

        return $this;
    }

    // --------------------------------------------------------------------------

    /**
     * Deletes jobs which finished long enough ago that nobody is coming back for them
     *
     * @throws FactoryException
     * @throws ModelException
     */
    protected function rotateFinished(): self
    {
        $this->oOutput->writeln('');
        $this->oOutput->writeln('<comment>Rotating finished jobs</comment>');

        $iDeleted = $this->deleteJobs(
            Status::terminal(),
            $this->getCutOff('AUTH_USER_IMPORT_RETENTION', static::RETENTION)
        );

        $this->oOutput->writeln(sprintf('Deleted <info>%s</info>', $iDeleted));

        return $this;
    }

    // --------------------------------------------------------------------------

    /**
     * Deletes jobs of the given statuses which were last modified before the cut off
     *
     * @param Status[] $aStatuses
     *
     * @throws FactoryException
     * @throws ModelException
     */
    protected function deleteJobs(array $aStatuses, string $sCutOff): int
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

            //  The job goes first; the CDN objects cascade onto it, and a
            //  half-deleted job is worse than a lingering file.
            if (!$oModel->delete($oImport->id)) {
                $this->oOutput->writeln(sprintf(
                    '↳ <error>Failed to delete import #%s; %s</error>',
                    $oImport->id,
                    $oModel->lastError()
                ));
                continue;
            }

            foreach ($oModel->destroyObjects($oImport) as $iObjectId => $sError) {
                $this->oOutput->writeln(sprintf(
                    '↳ <error>Failed to destroy CDN object #%s; %s</error>',
                    $iObjectId,
                    $sError
                ));
            }

            $iDeleted++;
        }

        return $iDeleted;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the datetime $sConfigKey seconds ago
     *
     * @throws FactoryException
     */
    protected function getCutOff(string $sConfigKey, int $iDefault): string
    {
        $iSeconds = (int) Config::get($sConfigKey, $iDefault) ?: $iDefault;

        /** @var \DateTime $oCutOff */
        $oCutOff = Factory::factory('DateTime');
        $oCutOff->sub(new DateInterval('PT' . $iSeconds . 'S'));

        return $oCutOff->format('Y-m-d H:i:s');
    }
}

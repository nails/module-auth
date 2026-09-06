<?php

namespace Nails\Auth\Console\Command\User\Import;

use Nails\Auth\Constants;
use Nails\Auth\Enum\User\Import\Runner;
use Nails\Auth\Enum\User\Import\Status;
use Nails\Auth\Model;
use Nails\Auth\Resource;
use Nails\Auth\Service\User\Import\Processor;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\ModelException;
use Nails\Common\Service\Database;
use Nails\Config;
use Nails\Console\Command\Base;
use Nails\Factory;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Class Process
 *
 * @package Nails\Auth\Console\Command\User\Import
 */
class Process extends Base
{
    /**
     * How long, in seconds, the command will keep picking up work for
     *
     * The default leaves comfortable headroom within the minute before the next
     * run of the cron task.
     *
     * @var int
     */
    const RUN_BUDGET = 50;

    // --------------------------------------------------------------------------

    /**
     * Configure the command
     */
    protected function configure(): void
    {
        $this
            ->setName('auth:user:import:process')
            ->setDescription('Processes any approved user import jobs')
            ->addOption(
                'chunk',
                'c',
                InputOption::VALUE_REQUIRED,
                'The number of CSV rows to handle at a time'
            )
            ->addOption(
                'budget',
                'b',
                InputOption::VALUE_REQUIRED,
                'How long, in seconds, to keep processing for'
            );
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

            $this->banner('User Import: Process');
            $this->recordLastRun();
            $this->processJobs();

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
     * Records that the cron ran, so admin can warn when it stops
     *
     * @throws FactoryException
     */
    protected function recordLastRun(): void
    {
        /** @var \DateTime $oNow */
        $oNow = Factory::factory('DateTime');
        setAppSetting(
            'user-import-cron-last-run',
            Constants::MODULE_SLUG,
            $oNow->format('Y-m-d H:i:s')
        );
    }

    // --------------------------------------------------------------------------

    /**
     * Works through the outstanding jobs, oldest first, until the budget is spent
     *
     * @throws FactoryException
     * @throws ModelException
     */
    protected function processJobs(): void
    {
        /** @var Model\User\Import $oModel */
        $oModel = Factory::model('UserImport', Constants::MODULE_SLUG);
        /** @var Processor $oProcessor */
        $oProcessor = Factory::service('UserImportProcessor', Constants::MODULE_SLUG);

        $iChunk    = (int) ($this->oInput->getOption('chunk') ?: $oProcessor::getChunkSize());
        $iBudget   = (int) ($this->oInput->getOption('budget') ?: Config::get('AUTH_USER_IMPORT_RUN_BUDGET', static::RUN_BUDGET));
        $fDeadline = microtime(true) + $iBudget;

        $aSkip  = [];
        $iFound = 0;

        while (microtime(true) < $fDeadline) {

            $oImport = $this->getNextJob($aSkip);
            if (empty($oImport)) {
                break;
            }

            $iFound++;
            $aSkip[] = $oImport->id;

            $sToken = md5(uniqid((string) getmypid(), true));

            if (!$oModel->claim($oImport->id, $sToken)) {
                $this->oOutput->writeln(sprintf(
                    'Import #<info>%s</info> is claimed by another process, skipping',
                    $oImport->id
                ));
                continue;
            }

            $this->oOutput->writeln(sprintf(
                'Processing import #<info>%s</info> (<info>%s</info>)',
                $oImport->id,
                $oImport->status->value
            ));

            try {

                while (!$oImport->status->isTerminal() && microtime(true) < $fDeadline) {

                    $sBefore = $this->fingerprint($oImport);
                    $oImport = $oProcessor->process($oImport, $iChunk);

                    $this->oOutput->writeln(sprintf(
                        '↳ <info>%s</info>: validated <info>%s</info>, processed <info>%s</info> of <info>%s</info> (<info>%s</info> ok, <info>%s</info> warned, <info>%s</info> errored)',
                        $oImport->status->value,
                        $oImport->validated_count,
                        $oImport->processed_count,
                        $oImport->row_count ?? 0,
                        $oImport->success_count,
                        $oImport->warning_count,
                        $oImport->error_count
                    ));

                    if ($this->fingerprint($oImport) === $sBefore) {
                        /**
                         * Recorded on the job as well as printed: console output
                         * from a cron run is nobody's idea of a diagnostic, and
                         * an admin with no shell has to be able to see why this
                         * stopped.
                         */
                        $oImport = $oProcessor->abandon(
                            $oImport,
                            'no progress was made between two consecutive attempts'
                        );

                        $this->oOutput->writeln('↳ <error>No progress was made, abandoning this job</error>');
                        break;
                    }
                }

                $this->reportOutcome($oImport);

            } finally {
                $oModel->release($oImport->id);
            }
        }

        if (empty($iFound)) {
            $this->oOutput->writeln('Nothing to do');
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Prints why a job ended the way it did
     *
     * The job's stored error already carries the phase, the cause and the first
     * of the failing lines, so it is printed rather than recomposed here; that
     * way the console, the details modal and the notification email can never
     * disagree with one another.
     */
    protected function reportOutcome(Resource\User\Import $oImport): void
    {
        if ($oImport->status === Status::FAILED) {
            $this->error(array_merge(
                [sprintf('Import #%s failed', $oImport->id)],
                array_values(array_filter(
                    explode("\n", (string) $oImport->error),
                    fn(string $sLine): bool => trim($sLine) !== ''
                ))
            ));

        } elseif ($oImport->status === Status::PARTIAL) {
            /**
             * A warning is a row whose account exists but whose follow-up did
             * not happen, so it is named separately: the reader's next step for
             * an errored row is to correct the CSV, and for a warned one it is
             * to finish the account by hand.
             */
            $this->warning(array_values(array_filter([
                sprintf(
                    'Import #%s finished with %s of %s rows unresolved',
                    $oImport->id,
                    $oImport->error_count + $oImport->warning_count,
                    $oImport->row_count ?? 0
                ),
                $oImport->error_count
                    ? sprintf('%s rows failed and no account was created', $oImport->error_count)
                    : null,
                $oImport->warning_count
                    ? sprintf(
                        '%s accounts were created but something afterwards did not complete',
                        $oImport->warning_count
                    )
                    : null,
            ])));
        }

        if ($oImport->log_id) {
            $this->oOutput->writeln(sprintf(
                '↳ Log: CDN object #<info>%s</info>',
                $oImport->log_id
            ));
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the oldest unclaimed job which this runner owns
     *
     * @param int[] $aSkip Jobs which have already been looked at this run
     *
     * @throws FactoryException
     * @throws ModelException
     */
    protected function getNextJob(array $aSkip): ?Resource\User\Import
    {
        /** @var Model\User\Import $oModel */
        $oModel = Factory::model('UserImport', Constants::MODULE_SLUG);
        /** @var Database $oDb */
        $oDb = Factory::service('Database');

        $oDb
            ->select('id')
            ->where('runner', Runner::CRON->value)
            ->where_in('status', Status::values(Status::active()))
            ->where('claim_token', null)
            ->order_by('id', 'asc')
            ->limit(1);

        if (!empty($aSkip)) {
            $oDb->where_not_in('id', $aSkip);
        }

        $oRow = $oDb->get($oModel->getTableName())->row();

        /** @var Resource\User\Import|null $oImport */
        $oImport = $oRow ? $oModel->getById((int) $oRow->id) : null;

        return $oImport;
    }

    // --------------------------------------------------------------------------

    /**
     * A cheap representation of the job's progress, used to detect a stall
     */
    protected function fingerprint(Resource\User\Import $oImport): string
    {
        return implode(':', [
            $oImport->status->value,
            $oImport->validated_count,
            $oImport->processed_count,
        ]);
    }
}

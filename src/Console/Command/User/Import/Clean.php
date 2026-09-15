<?php

namespace Nails\Auth\Console\Command\User\Import;

use Nails\Auth\Housekeeping\UserImports;
use Nails\Components;
use Nails\Console\Command\Base;
use Nails\Factory;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @deprecated Use housekeeping:run --routine=Nails\Auth\Housekeeping\UserImports
 */
class Clean extends Base
{
    /**
     * Configure the command
     */
    protected function configure(): void
    {
        $this
            ->setName('auth:user:import:clean')
            ->setDescription('[DEPRECATED] Releases orphaned user import jobs and reaps old ones')
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Log what would be changed without writing'
            );
    }

    /**
     * Executes the command
     *
     * @param InputInterface  $oInput  The Input Interface provided by Symfony
     * @param OutputInterface $oOutput The Output Interface provided by Symfony
     */
    protected function execute(InputInterface $oInput, OutputInterface $oOutput): int
    {
        parent::execute($oInput, $oOutput);

        $this->banner('User Import: Clean (deprecated)');

        if (!Components::exists('nails/module-housekeeping')) {
            $oOutput->writeln('<error>This command now requires nails/module-housekeeping.</error>');
            $oOutput->writeln('Install it with <comment>composer require nails/module-housekeeping</comment>');
            $oOutput->writeln('then run <comment>nails housekeeping:run --routine=' . UserImports::class . '</comment>');

            return static::EXIT_CODE_FAILURE;
        }

        /** @var \Nails\Housekeeping\Service\Orchestrator $oOrchestrator */
        $oOrchestrator = Factory::service('Orchestrator', 'nails/module-housekeeping');
        $oResult       = $oOrchestrator->runRoutine(
            UserImports::class,
            (bool) $oInput->getOption('dry-run'),
            true,
            $oOutput
        );

        return $oResult->isSuccess() ? static::EXIT_CODE_SUCCESS : static::EXIT_CODE_FAILURE;
    }
}

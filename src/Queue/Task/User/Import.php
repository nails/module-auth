<?php

/**
 * Runs a user import job on a nails/module-queue worker.
 *
 * The worker is long lived, so unlike the cron runner this carries the job all
 * the way to a terminal status in a single go.
 *
 * This class references Nails\Queue\*, which is only present when
 * nails/module-queue is installed. That is safe: autoloading is lazy, and
 * nothing reflects over this namespace — module-cron only scans Cron\Task, and
 * module-console only Console\Command.
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    Task
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Auth\Queue\Task\User;

use Nails\Auth\Constants;
use Nails\Auth\Model;
use Nails\Auth\Resource;
use Nails\Auth\Service\User\Import\Processor;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\ModelException;
use Nails\Factory;
use Nails\Queue\Interface\Data;
use Nails\Queue\Interface\Task;
use RuntimeException;

/**
 * Class Import
 *
 * @package Nails\Auth\Queue\Task\User
 */
class Import implements Task
{
    /**
     * Retrying would re-create users; interrupted jobs resume from their cursor
     * instead, so a retry has nothing useful to add.
     */
    public static function getMaxRetries(): int
    {
        return 0;
    }

    // --------------------------------------------------------------------------

    /**
     * @throws FactoryException
     * @throws ModelException
     */
    public function run(Data $data): void
    {
        /** @var Model\User\Import $oModel */
        $oModel = Factory::model('UserImport', Constants::MODULE_SLUG);
        /** @var Processor $oProcessor */
        $oProcessor = Factory::service('UserImportProcessor', Constants::MODULE_SLUG);

        $iId = (int) $data->get();

        /** @var Resource\User\Import|null $oImport */
        $oImport = $oModel->getById($iId);

        if (empty($oImport)) {
            throw new RuntimeException(sprintf(
                'User import #%s does not exist',
                $iId
            ));

        } elseif ($oImport->status->isTerminal()) {
            return;
        }

        $sToken = md5(uniqid((string) getmypid(), true));

        if (!$oModel->claim($iId, $sToken)) {
            //  Another process is already working this job
            return;
        }

        try {

            while (!$oImport->status->isTerminal()) {

                $sBefore = $this->fingerprint($oImport);
                $oImport = $oProcessor->process($oImport);

                if ($this->fingerprint($oImport) === $sBefore) {

                    /**
                     * Recorded on the job before it is thrown: module-queue's own
                     * failure record is not reachable from the user import admin,
                     * so without this an admin sees a job stuck mid-flight with
                     * nothing to explain it.
                     */
                    $oProcessor->abandon(
                        $oImport,
                        'no progress was made between two consecutive attempts'
                    );

                    throw new RuntimeException(sprintf(
                        'User import #%s made no progress; abandoning to avoid spinning',
                        $iId
                    ));
                }
            }

        } finally {
            $oModel->release($iId);
        }
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

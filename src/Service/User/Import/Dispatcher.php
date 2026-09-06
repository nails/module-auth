<?php

/**
 * Hands an approved import job to whichever runner the installation has.
 *
 * If nails/module-queue is installed the job is pushed onto the queue and a
 * worker will carry it through in one go; otherwise it waits for the
 * auth:user:import:process cron task to pick it up a chunk at a time. The
 * runner is recorded on the job so the two can never both claim it.
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    Service
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Auth\Service\User\Import;

use Nails\Auth\Constants;
use Nails\Auth\Enum\User\Import\Runner;
use Nails\Auth\Enum\User\Import\Status;
use Nails\Auth\Model;
use Nails\Auth\Queue\Task\User\Import as ImportTask;
use Nails\Auth\Resource;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\ModelException;
use Nails\Components;
use Nails\Factory;

/**
 * Class Dispatcher
 *
 * @package Nails\Auth\Service\User\Import
 */
class Dispatcher
{
    /**
     * The module which, when installed, takes precedence over the cron runner
     *
     * @var string
     */
    const QUEUE_MODULE = 'nails/module-queue';

    /**
     * How long, in seconds, the cron runner may go without checking in before
     * admin starts warning about it
     *
     * @var int
     */
    const RUNNING_THRESHOLD = 300;

    // --------------------------------------------------------------------------

    /**
     * Approves a job and sends it to a runner
     *
     * @throws FactoryException
     * @throws ModelException
     */
    public function dispatch(Resource\User\Import $oImport): void
    {
        /** @var Model\User\Import $oModel */
        $oModel = Factory::model('UserImport', Constants::MODULE_SLUG);

        $oRunner = $this->getRunner();

        if (!$oModel->update($oImport->id, [
            'runner' => $oRunner->value,
            'status' => Status::PENDING->value,
        ])) {
            throw new ModelException($oModel->lastError());
        }

        if ($oRunner === Runner::QUEUE) {
            Factory::service('Manager', \Nails\Queue\Constants::MODULE_SLUG)
                ->push(
                    new ImportTask(),
                    Factory::factory('Data', \Nails\Queue\Constants::MODULE_SLUG, $oImport->id)
                );
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Whether the runner has checked in recently enough to be believed
     *
     * There is nothing to check for the queue runner; module-queue monitors its
     * own workers, and a warning we cannot substantiate is worse than none.
     *
     * @throws FactoryException
     */
    public function isRunning(): bool
    {
        if ($this->getRunner() === Runner::QUEUE) {
            return true;
        }

        $sLastRun = appSetting('user-import-cron-last-run', Constants::MODULE_SLUG);
        if (empty($sLastRun)) {
            return false;
        }

        /** @var \DateTime $oNow */
        $oNow = Factory::factory('DateTime');

        return ($oNow->getTimestamp() - (new \DateTime($sLastRun))->getTimestamp()) <= static::RUNNING_THRESHOLD;
    }

    // --------------------------------------------------------------------------

    /**
     * Which runner will process jobs on this installation
     */
    public function getRunner(): Runner
    {
        return Components::exists(static::QUEUE_MODULE)
            ? Runner::QUEUE
            : Runner::CRON;
    }
}

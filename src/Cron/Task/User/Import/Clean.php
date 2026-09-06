<?php

/**
 * The User Import Clean Cron task
 *
 * @package  Nails\Auth
 * @category Task
 */

namespace Nails\Auth\Cron\Task\User\Import;

use Nails\Cron\Task\Base;

/**
 * Class Clean
 *
 * @package Nails\Auth\Cron\Task\User\Import
 */
class Clean extends Base
{
    /**
     * The cron expression of when to run
     *
     * @var string
     */
    const CRON_EXPRESSION = '*/15 * * * *';

    /**
     * The console command to execute
     *
     * @var string
     */
    const CONSOLE_COMMAND = 'auth:user:import:clean';

    /**
     * @var int
     */
    const MAX_PROCESSES = 1;
}

<?php

/**
 * The User Import Process Cron task
 *
 * @package  Nails\Auth
 * @category Task
 */

namespace Nails\Auth\Cron\Task\User\Import;

use Nails\Cron\Task\Base;

/**
 * Class Process
 *
 * @package Nails\Auth\Cron\Task\User\Import
 */
class Process extends Base
{
    /**
     * The cron expression of when to run
     *
     * @var string
     */
    const CRON_EXPRESSION = '* * * * *';

    /**
     * The console command to execute
     *
     * @var string
     */
    const CONSOLE_COMMAND = 'auth:user:import:process';

    /**
     * Imports are processed sequentially; a second process would only ever
     * contend for the same claim.
     *
     * @var int
     */
    const MAX_PROCESSES = 1;
}

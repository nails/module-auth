<?php

namespace Nails\Auth\Enum\User\Import;

enum Runner: string
{
    /**
     * Processed in chunks by the auth:user:import:process cron task
     */
    case CRON = 'CRON';

    /**
     * Processed in its entirety by a nails/module-queue worker
     */
    case QUEUE = 'QUEUE';
}

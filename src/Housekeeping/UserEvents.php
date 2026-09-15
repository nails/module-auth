<?php

namespace Nails\Auth\Housekeeping;

use Nails\Auth\Constants;
use Nails\Common\Model\Base as ModelBase;
use Nails\Config;
use Nails\Factory;
use Nails\Housekeeping\Routine\Base;
use Nails\Housekeeping\Routine\Context;
use Nails\Housekeeping\Routine\Result;
use Nails\Housekeeping\Traits\DeletesModelRows;

class UserEvents extends Base
{
    use DeletesModelRows {
        execute as deleteModelRows;
    }

    const LABEL           = 'User events';
    const DESCRIPTION     = 'Deletes user event log rows older than AUTH_USER_EVENT_RETENTION_DAYS';
    const CRON_EXPRESSION = '@daily';

    /**
     * Default retention in days. 0 disables deletion.
     */
    const DEFAULT_RETENTION_DAYS = 730;

    protected function model(): ModelBase
    {
        return Factory::model('UserEvent', Constants::MODULE_SLUG);
    }

    /**
     * @return array<int, mixed>
     */
    protected function where(): array
    {
        $iDays = $this->retentionDays();
        if ($iDays < 1) {
            return [['id' => 0]];
        }

        /** @var \DateTime $oNow */
        $oNow = Factory::factory('DateTime');
        $oNow->sub(new \DateInterval('P' . $iDays . 'D'));

        return [
            ['created <', $oNow->format('Y-m-d H:i:s')],
        ];
    }

    /**
     * @return string[]
     */
    protected function auditColumns(): array
    {
        return ['id', 'created_by', 'type', 'created'];
    }

    protected function optimizeAfter(): bool
    {
        return true;
    }

    public function execute(Context $oContext): Result
    {
        $iDays = $this->retentionDays();
        if ($iDays < 1) {
            $oContext
                ->writeln('User event cleanup disabled')
                ->log('DISABLED AUTH_USER_EVENT_RETENTION_DAYS=0');

            return Result::ok(0, 'User event cleanup disabled');
        }

        $oContext->writeln('Retention policy: <info>' . $iDays . ' days</info>');

        return $this->deleteModelRows($oContext);
    }

    protected function retentionDays(): int
    {
        return (int) Config::get('AUTH_USER_EVENT_RETENTION_DAYS', static::DEFAULT_RETENTION_DAYS);
    }
}

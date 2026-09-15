<?php

namespace Nails\Auth\Housekeeping;

use Nails\Auth\Constants;
use Nails\Common\Model\Base as ModelBase;
use Nails\Factory;
use Nails\Housekeeping\Routine\Base;
use Nails\Housekeeping\Traits\DeletesModelRows;

class AccessTokens extends Base
{
    use DeletesModelRows;

    const LABEL           = 'API access tokens';
    const DESCRIPTION     = 'Deletes expired user API access tokens';
    const CRON_EXPRESSION = '@daily';

    protected function model(): ModelBase
    {
        return Factory::model('UserAccessToken', Constants::MODULE_SLUG);
    }

    /**
     * @return array<int, mixed>
     */
    protected function where(): array
    {
        /** @var \DateTime $oNow */
        $oNow = Factory::factory('DateTime');

        return [
            ['expires <', $oNow->format('Y-m-d H:i:s')],
        ];
    }

    /**
     * @return string[]
     */
    protected function auditColumns(): array
    {
        return ['id', 'user_id', 'expires'];
    }

    protected function optimizeAfter(): bool
    {
        return true;
    }
}

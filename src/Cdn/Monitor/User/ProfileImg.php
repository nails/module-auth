<?php

namespace Nails\Auth\Cdn\Monitor\User;

use Nails\Auth\Constants;
use Nails\Cdn\Cdn\Monitor\ObjectIsInColumn;
use Nails\Common\Model\Base;
use Nails\Factory;

class ProfileImg extends ObjectIsInColumn
{
    protected function getModel(): Base
    {
        return Factory::model('User', Constants::MODULE_SLUG);
    }

    // --------------------------------------------------------------------------

    protected function getColumn(): string
    {
        return 'profile_img';
    }
}

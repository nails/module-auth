<?php

namespace Nails\Auth\Cdn\MetaData\SystemKey;

use Nails\Auth\Constants;
use Nails\Cdn\Interfaces\MetaData\SystemKey;

class UserImport implements SystemKey
{
    public function get(): string
    {
        return sprintf('%s:user-import', Constants::MODULE_SLUG);
    }
}

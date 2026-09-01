<?php

namespace Nails\Auth\Cdn\MetaData\SystemKey;

use Nails\Auth\Constants;
use Nails\Cdn\Interfaces\MetaData\SystemKey;

class ImportedFrom implements SystemKey
{
    public function get(): string
    {
        return sprintf('%s:imported-from', Constants::MODULE_SLUG);
    }
}

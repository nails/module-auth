<?php

namespace Nails\Auth\Admin\Permission\Settings;

use Nails\Admin\Interfaces\Permission;

class Password implements Permission
{
    public function label(): string
    {
        return 'Can update password settings';
    }

    public function group(): string
    {
        return 'Module Settings';
    }
}

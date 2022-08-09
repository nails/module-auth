<?php

namespace Nails\Auth\Admin\Permission\Settings;

use Nails\Admin\Interfaces\Permission;

class Login implements Permission
{
    public function label(): string
    {
        return 'Can update login settings';
    }

    public function group(): string
    {
        return 'Module Settings';
    }
}

<?php

namespace Nails\Auth\Admin\Permission\Settings;

use Nails\Admin\Interfaces\Permission;

class Registration implements Permission
{
    public function label(): string
    {
        return 'Can update registration settings';
    }

    public function group(): string
    {
        return 'Module Settings';
    }
}

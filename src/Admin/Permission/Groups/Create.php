<?php

namespace Nails\Auth\Admin\Permission\Groups;

use Nails\Admin\Interfaces\Permission;

class Create implements Permission
{
    public function label(): string
    {
        return 'Can create user groups';
    }

    public function group(): string
    {
        return 'User Groups';
    }
}

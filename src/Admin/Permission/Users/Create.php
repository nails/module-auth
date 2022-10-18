<?php

namespace Nails\Auth\Admin\Permission\Users;

use Nails\Admin\Interfaces\Permission;

class Create implements Permission
{
    public function label(): string
    {
        return 'Can create users';
    }

    public function group(): string
    {
        return 'User Accounts';
    }
}

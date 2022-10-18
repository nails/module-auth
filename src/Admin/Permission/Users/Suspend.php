<?php

namespace Nails\Auth\Admin\Permission\Users;

use Nails\Admin\Interfaces\Permission;

class Suspend implements Permission
{
    public function label(): string
    {
        return 'Can suspend users';
    }

    public function group(): string
    {
        return 'User Accounts';
    }
}

<?php

namespace Nails\Auth\Admin\Permission\Users;

use Nails\Admin\Interfaces\Permission;

class Browse implements Permission
{
    public function label(): string
    {
        return 'Can browse users';
    }

    public function group(): string
    {
        return 'User Accounts';
    }
}

<?php

namespace Nails\Auth\Admin\Permission\Users;

use Nails\Admin\Interfaces\Permission;

class Delete implements Permission
{
    public function label(): string
    {
        return 'Can delete users';
    }

    public function group(): string
    {
        return 'User Accounts';
    }
}

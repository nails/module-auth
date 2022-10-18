<?php

namespace Nails\Auth\Admin\Permission\Users;

use Nails\Admin\Interfaces\Permission;

class Edit implements Permission
{
    public function label(): string
    {
        return 'Can edit users';
    }

    public function group(): string
    {
        return 'User Accounts';
    }
}

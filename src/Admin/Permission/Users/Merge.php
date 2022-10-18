<?php

namespace Nails\Auth\Admin\Permission\Users;

use Nails\Admin\Interfaces\Permission;

class Merge implements Permission
{
    public function label(): string
    {
        return 'Can merge users';
    }

    public function group(): string
    {
        return 'User Accounts';
    }
}

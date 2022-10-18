<?php

namespace Nails\Auth\Admin\Permission\Users;

use Nails\Admin\Interfaces\Permission;

class LoginAs implements Permission
{
    public function label(): string
    {
        return 'Can log in as another user';
    }

    public function group(): string
    {
        return 'User Accounts';
    }
}

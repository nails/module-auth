<?php

namespace Nails\Auth\Admin\Permission\Users\Group;

use Nails\Admin\Interfaces\Permission;

class Change implements Permission
{
    public function label(): string
    {
        return 'Can change a user\'s group';
    }

    public function group(): string
    {
        return 'User Accounts';
    }
}

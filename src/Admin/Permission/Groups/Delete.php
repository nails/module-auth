<?php

namespace Nails\Auth\Admin\Permission\Groups;

use Nails\Admin\Interfaces\Permission;

class Delete implements Permission
{
    public function label(): string
    {
        return 'Can delete user groups';
    }

    public function group(): string
    {
        return 'User Groups';
    }
}

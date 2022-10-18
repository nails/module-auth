<?php

namespace Nails\Auth\Admin\Permission\Groups;

use Nails\Admin\Interfaces\Permission;

class Edit implements Permission
{
    public function label(): string
    {
        return 'Can edit user groups';
    }

    public function group(): string
    {
        return 'User Groups';
    }
}

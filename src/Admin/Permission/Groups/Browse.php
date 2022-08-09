<?php

namespace Nails\Auth\Admin\Permission\Groups;

use Nails\Admin\Interfaces\Permission;

class Browse implements Permission
{
    public function label(): string
    {
        return 'Can browse user groups';
    }

    public function group(): string
    {
        return 'User Groups';
    }
}

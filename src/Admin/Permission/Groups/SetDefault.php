<?php

namespace Nails\Auth\Admin\Permission\Groups;

use Nails\Admin\Interfaces\Permission;

class SetDefault implements Permission
{
    public function label(): string
    {
        return 'Can set the default user group';
    }

    public function group(): string
    {
        return 'User Groups';
    }
}

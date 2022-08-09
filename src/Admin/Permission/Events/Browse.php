<?php

namespace Nails\Auth\Admin\Permission\Events;

use Nails\Admin\Interfaces\Permission;

class Browse implements Permission
{
    public function label(): string
    {
        return 'Can browse user events';
    }

    public function group(): string
    {
        return 'User Events';
    }
}

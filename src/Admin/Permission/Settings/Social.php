<?php

namespace Nails\Auth\Admin\Permission\Settings;

use Nails\Admin\Interfaces\Permission;

class Social implements Permission
{
    public function label(): string
    {
        return 'Can update Social Signon settings';
    }

    public function group(): string
    {
        return 'Module Settings';
    }
}

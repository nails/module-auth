<?php

namespace Nails\Auth\Resource\User\Password;

use Nails\Common\Resource;

/**
 * Class History
 *
 * @package Nails\Auth\Resource\User\Password
 */
class History extends Resource\Entity
{
    public ?string $password        = null;
    public ?string $password_engine = null;
    public ?string $salt            = null;
}

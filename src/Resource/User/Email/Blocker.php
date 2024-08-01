<?php

namespace Nails\Auth\Resource\User\Email;

use Nails\Common\Resource;

/**
 * Class Blocker
 *
 * @package Nails\Auth\Resource\User\Email
 */
class Blocker extends Resource\Entity
{
    /** @var int */
    public $user_id;

    /** @var string */
    public $type;
}

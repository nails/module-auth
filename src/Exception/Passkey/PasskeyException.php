<?php

namespace Nails\Auth\Exception\Passkey;

use Nails\Auth\Exception\AuthException;

/**
 * Class PasskeyException
 *
 * The base of every passkey exception; catch this to handle any failure in a
 * WebAuthn ceremony without caring which stage it failed at.
 *
 * @package Nails\Auth\Exception\Passkey
 */
class PasskeyException extends AuthException
{
}

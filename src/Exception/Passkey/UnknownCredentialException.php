<?php

namespace Nails\Auth\Exception\Passkey;

/**
 * Class UnknownCredentialException
 *
 * Thrown when the asserted credential is not known, or belongs to another user.
 *
 * @package Nails\Auth\Exception\Passkey
 */
class UnknownCredentialException extends PasskeyException
{
}

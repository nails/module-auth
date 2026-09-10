<?php

namespace Nails\Auth\Exception\Passkey;

/**
 * Class InvalidResponseException
 *
 * Thrown when the client's credential payload is missing fields or is not decodable.
 *
 * @package Nails\Auth\Exception\Passkey
 */
class InvalidResponseException extends PasskeyException
{
}

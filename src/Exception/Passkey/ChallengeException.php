<?php

namespace Nails\Auth\Exception\Passkey;

/**
 * Class ChallengeException
 *
 * Thrown when the stored challenge is missing, expired, or does not belong to this ceremony.
 *
 * @package Nails\Auth\Exception\Passkey
 */
class ChallengeException extends PasskeyException
{
}

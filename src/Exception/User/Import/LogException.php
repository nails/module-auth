<?php

namespace Nails\Auth\Exception\User\Import;

use Nails\Auth\Exception\AuthException;

/**
 * Class LogException
 *
 * Raised when the log which accompanies an import cannot be built or stored.
 * Carries the reason for every stage of that process - reading the source CSV,
 * writing the log, and putting it in the CDN - so a job which cannot explain
 * itself can at least explain why.
 *
 * @package Nails\Auth\Exception\User\Import
 */
class LogException extends AuthException
{
}

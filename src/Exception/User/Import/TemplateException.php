<?php

namespace Nails\Auth\Exception\User\Import;

use Nails\Auth\Exception\AuthException;

/**
 * Class TemplateException
 *
 * Raised when the import template itself cannot work - a column the app has
 * removed, or a unique key it has added without telling the import how to look
 * it up. This is a misconfiguration of the app rather than a problem with an
 * uploaded file, so it is deliberately not a ValidationException.
 *
 * @package Nails\Auth\Exception\User\Import
 */
class TemplateException extends AuthException
{
}

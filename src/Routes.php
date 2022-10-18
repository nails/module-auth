<?php

/**
 * Generates Auth routes
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    Controller
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Auth;

use Nails\Common\Interfaces\RouteGenerator;

class Routes implements RouteGenerator
{
    /**
     * Returns an array of routes for this module
     * @return array
     */
    public static function generate(): array
    {
        return [
            'auth/password/forgotten(/(.+))?'           => 'auth/PasswordForgotten/$2',
            'auth/password/reset/(\d+)/(.+)'            => 'auth/PasswordReset/$1/$2',
            'auth/mfa/device/(\d+)/(.+)/(.+)(/(.+))?'   => 'auth/MfaDevice',
            'auth/mfa/question/(\d+)/(.+)/(.+)(/(.+))?' => 'auth/MfaQuestion',
        ];
    }
}

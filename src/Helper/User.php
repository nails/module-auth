<?php

namespace Nails\Auth\Helper;

use Nails\Common\Exception\FactoryException;
use Nails\Common\Service\Input;
use Nails\Factory;

/**
 * Class User
 *
 * @package Nails\Auth\Helper
 */
class User
{
    /**
     * Compiles the "Login As" URL
     *
     * @param string|null $sIdMd5       The MD5 hash if the user's ID
     * @param string|null $sPasswordMd5 The MD5 hash of the user's password hash
     * @param string|null $sForwardTo   Where to forward the user to after login
     * @param string|null $sReturnTo    Where to return the original user to when returning
     *
     * @return string
     * @throws FactoryException
     */
    public static function compileLoginUrl(
        ?string $sIdMd5,
        ?string $sPasswordMd5,
        ?string $sForwardTo = null,
        ?string $sReturnTo = null
    ): string {

        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        $aQuery = array_filter([
            'return_to'  => $sReturnTo ?? $oInput->server('REQUEST_URI'),
            'forward_to' => $sForwardTo,
        ]);

        return siteUrl(sprintf(
            'auth/override/login_as/%s/%s%s',
            $sIdMd5,
            $sPasswordMd5,
            !empty($aQuery)
                ? '?' . http_build_query($aQuery)
                : ''
        ));
    }
}

<?php

use Nails\Auth\Factory\AuthUrl\Login;
use Nails\Auth\Factory\AuthUrl\Register;

if (!function_exists('loginUrl')) {
    function loginUrl(?string $returnTo = ''): Login
    {
        /** @var Login $loginUrl */
        $loginUrl = \Nails\Factory::factory('AuthUrlLogin', \Nails\Auth\Constants::MODULE_SLUG, $returnTo);
        return $loginUrl;
    }
}

if (!function_exists('registerUrl')) {
    function registerUrl(?string $returnTo = ''): Register
    {
        /** @var Register $registerUrl */
        $registerUrl = \Nails\Factory::factory('AuthUrlRegister', \Nails\Auth\Constants::MODULE_SLUG, $returnTo);
        return $registerUrl;
    }
}

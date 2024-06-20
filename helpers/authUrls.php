<?php

use Nails\Auth\Factory\AuthUrl\Login;
use Nails\Auth\Factory\AuthUrl\Register;

if (!function_exists('loginUrl')) {
    function loginUrl(?string $returnTo = '', array $query = []): Login
    {
        /** @var Login $url */
        $url = \Nails\Factory::factory('AuthUrlLogin', \Nails\Auth\Constants::MODULE_SLUG, $returnTo, $query);
        return $url;
    }
}

if (!function_exists('registerUrl')) {
    function registerUrl(?string $returnTo = '', array $query = []): Register
    {
        /** @var Register $url */
        $url = \Nails\Factory::factory('AuthUrlRegister', \Nails\Auth\Constants::MODULE_SLUG, $returnTo, $query);
        return $url;
    }
}

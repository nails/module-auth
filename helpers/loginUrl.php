<?php

use Nails\Auth\Factory\AuthUrl\Login;

if (!function_exists('loginUrl')) {
    function loginUrl(bool $autoReturnTo = true): Login
    {
        /** @var Login $loginUrl */
        $loginUrl = \Nails\Factory::factory('AuthUrlLogin', \Nails\Auth\Constants::MODULE_SLUG, $autoReturnTo);
        return $loginUrl;
    }
}

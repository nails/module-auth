<?php

use Nails\Auth\Factory\AuthUrl\Register;

if (!function_exists('registerUrl')) {
    function registerUrl(bool $autoReturnTo = true): Register
    {
        /** @var Register $registerUrl */
        $registerUrl = \Nails\Factory::factory('AuthUrlRegister', \Nails\Auth\Constants::MODULE_SLUG, $autoReturnTo);
        return $registerUrl;
    }
}

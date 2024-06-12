<?php

use Nails\Auth\Factory\LoginUrl;

if (!function_exists('loginUrl')) {
    function loginUrl(bool $autoReturnTo = true): LoginUrl
    {
        /** @var LoginUrl $loginUrl */
        $loginUrl = \Nails\Factory::factory('LoginUrl', \Nails\Auth\Constants::MODULE_SLUG, $autoReturnTo);
        return $loginUrl;
    }
}

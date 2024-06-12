<?php

use Nails\Auth\Factory\RegisterUrl;

if (!function_exists('registerUrl')) {
    function registerUrl(bool $autoReturnTo = true): RegisterUrl
    {
        /** @var RegisterUrl $registerUrl */
        $registerUrl = \Nails\Factory::factory('RegisterUrl', \Nails\Auth\Constants::MODULE_SLUG, $autoReturnTo);
        return $registerUrl;
    }
}

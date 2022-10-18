<?php

use Nails\Auth\Constants;
use Nails\Auth\Model;
use Nails\Factory;

/**
 * This file provides language user helper functions
 *
 * @package     Nails
 * @subpackage  common
 * @category    Helper
 * @author      Nails Dev Team
 * @link
 */

if (!function_exists('activeUser')) {
    function activeUser(string $sKeys = '', string $sDelimiter = ' ')
    {
        /** @var Model\User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);
        return $oUserModel->activeUser($sKeys, $sDelimiter);
    }
}

if (!function_exists('isLoggedIn')) {
    function isLoggedIn(): bool
    {
        /** @var Model\User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);
        return $oUserModel->isLoggedIn();
    }
}

if (!function_exists('wasAdmin')) {
    function wasAdmin(): bool
    {
        /** @var Model\User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);
        return $oUserModel->wasAdmin();
    }
}

if (!function_exists('getAdminRecoveryData')) {
    function getAdminRecoveryData(): ?\Nails\Auth\Resource\User\AdminRecovery
    {
        /** @var Model\User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);
        return $oUserModel->getAdminRecoveryData();
    }
}

if (!function_exists('getAdminRecoveryUrl')) {
    function getAdminRecoveryUrl(): ?string
    {
        /** @var Model\User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);
        return $oUserModel->getAdminRecoveryUrl();
    }
}

if (!function_exists('unsetAdminRecoveryData')) {
    function unsetAdminRecoveryData(): Model\User
    {
        /** @var Model\User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);
        return $oUserModel->unsetAdminRecoveryData();
    }
}

if (!function_exists('createUserEvent')) {
    function createUserEvent(
        string $sType,
        $mData = null,
        int $iRef = null,
        int $iCreatedBy = null,
        string $sCreated = null
    ): int {
        /** @var \Nails\Auth\Service\User\Event $oUserEventService */
        $oUserEventService = Factory::service('UserEvent', Constants::MODULE_SLUG);
        return $oUserEventService->create($sType, $mData, $iRef, $iCreatedBy, $sCreated);
    }
}

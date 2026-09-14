<?php

/**
 * This class handles settings
 *
 * @package     Nails
 * @subpackage  module-admin
 * @category    AdminController
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Auth\Admin\Controller;

use Nails\Admin\Controller\Base;
use Nails\Admin\Factory\Nav;
use Nails\Auth\Admin\Permission;
use Nails\Common\Service\AppSetting;
use Nails\Common\Service\Database;
use Nails\Common\Service\Input;
use Nails\Factory;

/**
 * Class Settings
 *
 * @package Nails\Admin\Auth
 */
class Settings extends Base
{
    const SETTINGS_PERMISSIONS = [
        Permission\Settings\Login::class,
        Permission\Settings\Password::class,
        Permission\Settings\Registration::class,
    ];

    // --------------------------------------------------------------------------

    /**
     * Announces this controller's navGroups
     */
    public static function announce(): Nav|array|null
    {
        /** @var Nav $oNavGroup */
        $oNavGroup = Factory::factory('Nav', \Nails\Admin\Constants::MODULE_SLUG);
        $oNavGroup
            ->setLabel('Settings')
            ->setIcon('fa-wrench');

        if (userHasAnyPermission(static::SETTINGS_PERMISSIONS)) {
            $oNavGroup->addAction('Authentication');
        }

        return $oNavGroup;
    }

    // --------------------------------------------------------------------------

    /**
     * Set Site Auth settings
     *
     * @return void
     */
    public function index(): void
    {
        if (!userHasAnyPermission(static::SETTINGS_PERMISSIONS)) {
            unauthorised();
        }

        // --------------------------------------------------------------------------

        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        /** @var Database $oDb */
        $oDb = Factory::service('Database');
        /** @var AppSetting $oAppSettingService */
        $oAppSettingService = Factory::service('AppSetting');

        // --------------------------------------------------------------------------

        if ($oInput->post()) {

            $aSettings = [];

            // --------------------------------------------------------------------------

            if (userHasPermission(Permission\Settings\Registration::class)) {
                $aSettings['user_registration_enabled']         = (bool) $oInput->post('user_registration_enabled');
                $aSettings['user_registration_captcha_enabled'] = (bool) $oInput->post('user_registration_captcha_enabled');
            }

            // --------------------------------------------------------------------------

            if (userHasPermission(Permission\Settings\Login::class)) {
                $aSettings['user_login_captcha_enabled'] = (bool) $oInput->post('user_login_captcha_enabled');
            }

            // --------------------------------------------------------------------------

            if (userHasPermission(Permission\Settings\Password::class)) {
                $aSettings['user_password_reset_captcha_enabled'] = (bool) $oInput->post('user_password_reset_captcha_enabled');
            }

            // --------------------------------------------------------------------------

            if (!empty($aSettings)) {

                $oDb->transaction()->start();

                if (!$oAppSettingService->set($aSettings, 'auth')) {
                    $oDb->transaction()->rollback();
                    $this->oUserFeedback->error('There was a problem saving authentication settings.');
                } else {
                    $oDb->transaction()->commit();
                    $this->oUserFeedback->success('Authentication settings were saved.');
                }

            } else {
                $this->oUserFeedback->warning('No settings to save.');
            }
        }

        // --------------------------------------------------------------------------

        $this
            ->setData('settings', appSetting(null, 'auth', null, true))
            ->setTitles(['Settings', 'Authentication'])
            ->loadView('index');
    }
}

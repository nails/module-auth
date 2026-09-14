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

namespace Nails\Admin\Auth;

use Nails\Admin\Factory\Nav;
use Nails\Admin\Helper;
use Nails\Auth\Constants;
use Nails\Auth\Controller\BaseAdmin;
use Nails\Common\Service\AppSetting;
use Nails\Common\Service\Database;
use Nails\Common\Service\Input;
use Nails\Factory;

/**
 * Class Settings
 *
 * @package Nails\Admin\Auth
 */
class Settings extends BaseAdmin
{
    public static function announce(): Nav|array|null
    {
        /** @var Nav $oNavGroup */
        $oNavGroup = Factory::factory('Nav', \Nails\Admin\Constants::MODULE_SLUG);
        $oNavGroup
            ->setLabel('Settings')
            ->setIcon('fa-wrench');

        if (userHasPermission('admin:auth:settings:update:.*')) {
            $oNavGroup->addAction('Authentication');
        }

        return $oNavGroup;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns an array of permissions which can be configured for the user
     *
     * @return array
     */
    public static function permissions(): array
    {
        $aPermissions = parent::permissions();

        $aPermissions['update:registration'] = 'Can configure registration';
        $aPermissions['update:login']        = 'Can configure login';
        $aPermissions['update:password']     = 'Can configure password';
        $aPermissions['update:social']       = 'Can social integrations';

        return $aPermissions;
    }

    // --------------------------------------------------------------------------

    /**
     * Set Site Auth settings
     *
     * @return void
     */
    public function index(): void
    {
        if (!userHasPermission('admin:auth:settings:update:.*')) {
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

            if (userHasPermission('admin:auth:settings:update:registration')) {
                $aSettings['user_registration_enabled']         = (bool) $oInput->post('user_registration_enabled');
                $aSettings['user_registration_captcha_enabled'] = (bool) $oInput->post('user_registration_captcha_enabled');
            }

            // --------------------------------------------------------------------------

            if (userHasPermission('admin:auth:settings:update:login')) {
                $aSettings['user_login_captcha_enabled'] = (bool) $oInput->post('user_login_captcha_enabled');
            }

            // --------------------------------------------------------------------------

            if (userHasPermission('admin:auth:settings:update:password')) {
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

        //  Existing settings
        $this->data['settings'] = appSetting(null, 'auth', null, true);

        // --------------------------------------------------------------------------

        //  Set page title
        $this->data['page']->title = 'Settings &rsaquo; Authentication';

        // --------------------------------------------------------------------------

        //  Load view
        Helper::loadView('index');
    }
}

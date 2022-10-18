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
use Nails\Admin\Helper;
use Nails\Auth\Admin\Permission;
use Nails\Auth\Constants;
use Nails\Auth\Controller\BaseAdmin;
use Nails\Auth\Service\SocialSignOn;
use Nails\Common\Service\AppSetting;
use Nails\Common\Service\Asset;
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
        Permission\Settings\Social::class,
    ];

    // --------------------------------------------------------------------------

    /**
     * Announces this controller's navGroups
     *
     * @return \stdClass
     */
    public static function announce()
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

        /** @var SocialSignOn $oSocial */
        $oSocial = Factory::service('SocialSignOn', Constants::MODULE_SLUG);
        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        /** @var Asset $oAsset */
        $oAsset = Factory::service('Asset');
        /** @var Database $oDb */
        $oDb = Factory::service('Database');
        /** @var AppSetting $oAppSettingService */
        $oAppSettingService = Factory::service('AppSetting');

        // --------------------------------------------------------------------------

        $aProviders               = $oSocial->getProviders();
        $this->data['aProviders'] = $aProviders;

        // --------------------------------------------------------------------------

        if ($oInput->post()) {

            $aSettings          = [];
            $aSettingsEncrypted = [];

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

            if (userHasPermission(Permission\Settings\Social::class)) {

                /**
                 * Disable social signon, if any providers are properly enabled it'll
                 * turn itself on again.
                 */

                $aSettings['auth_social_signon_enabled'] = false;

                foreach ($aProviders as $aProvider) {

                    $aSettings['auth_social_signon_' . $aProvider['slug'] . '_enabled'] = (bool) $oInput->post('auth_social_signon_' . $aProvider['slug'] . '_enabled');

                    if ($aSettings['auth_social_signon_' . $aProvider['slug'] . '_enabled']) {

                        //  null out each key
                        if ($aProvider['fields']) {

                            foreach ($aProvider['fields'] as $key => $label) {

                                if (is_array($label) && !isset($label['label'])) {

                                    foreach ($label as $key1 => $label1) {

                                        $value = $oInput->post('auth_social_signon_' . $aProvider['slug'] . '_' . $key . '_' . $key1);

                                        if (!empty($label1['required']) && empty($value)) {
                                            $error = 'Provider "' . $aProvider['label'] . '" was enabled, but was missing required field "' . $label1['label'] . '".';
                                            break 3;
                                        }

                                        if (empty($label1['encrypted'])) {
                                            $aSettings['auth_social_signon_' . $aProvider['slug'] . '_' . $key . '_' . $key1] = $value;
                                        } else {
                                            $aSettingsEncrypted['auth_social_signon_' . $aProvider['slug'] . '_' . $key . '_' . $key1] = $value;
                                        }
                                    }

                                } else {

                                    $value = $oInput->post('auth_social_signon_' . $aProvider['slug'] . '_' . $key);

                                    if (!empty($label['required']) && empty($value)) {
                                        $error = 'Provider "' . $aProvider['label'] . '" was enabled, but was missing required field "' . $label['label'] . '".';
                                        break 2;
                                    }

                                    if (empty($label['encrypted'])) {
                                        $aSettings['auth_social_signon_' . $aProvider['slug'] . '_' . $key] = $value;
                                    } else {
                                        $aSettingsEncrypted['auth_social_signon_' . $aProvider['slug'] . '_' . $key] = $value;
                                    }
                                }
                            }
                        }

                        //  Turn on social signon
                        $aSettings['auth_social_signon_enabled'] = true;

                    } else {

                        //  null out each key
                        if ($aProvider['fields']) {

                            foreach ($aProvider['fields'] as $key => $label) {

                                /**
                                 * Secondary conditional detects an actual array fo fields rather than
                                 * just the label/required array. Design could probably be improved...
                                 **/

                                if (is_array($label) && !isset($label['label'])) {

                                    foreach ($label as $key1 => $label1) {
                                        $aSettings['auth_social_signon_' . $aProvider['slug'] . '_' . $key . '_' . $key1] = null;
                                    }

                                } else {
                                    $aSettings['auth_social_signon_' . $aProvider['slug'] . '_' . $key] = null;
                                }
                            }
                        }
                    }
                }
            }

            // --------------------------------------------------------------------------

            if (!empty($aSettings)) {

                if (empty($error)) {

                    $oDb->transaction()->start();

                    $bRollback = false;

                    if (!empty($aSettings)) {
                        if (!$oAppSettingService->set($aSettings, 'auth')) {
                            $error     = $oAppSettingService->lastError();
                            $bRollback = true;
                        }
                    }

                    if (!empty($aSettingsEncrypted)) {
                        if (!$oAppSettingService->set($aSettingsEncrypted, 'auth', null, true)) {
                            $error     = $oAppSettingService->lastError();
                            $bRollback = true;
                        }
                    }

                    if ($bRollback) {
                        $oDb->transaction()->rollback();
                        $this->oUserFeedback->error('There was a problem saving authentication settings.');

                    } else {
                        $oDb->transaction()->commit();
                        $this->oUserFeedback->success('Authentication settings were saved.');
                    }

                } else {
                    $this->oUserFeedback->success('There was a problem saving authentication settings. ' . $error);
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

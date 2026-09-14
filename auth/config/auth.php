<?php

/**
 * Auth config
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    Config
 * @author      Nails Dev Team
 * @link
 */

//  @todo (Pablo - 2017-07-09) - Rework configurations to come from appSettings() + admin panel

/**
 * Disable errors when submitting the forgotten password form
 */
$config['authForgottenPassAlwaysSucceed'] = false;

/**
 * Toggle the "remember me" functionality
 */
$config['authEnableRememberMe'] = true;

/**
 * Toggle logins via hashes functionality
 */
$config['authEnableHashedLogin'] = true;

/**
 * On login show the last seen time as a human friendly string
 */
$config['authShowNicetimeOnLogin'] = true;

/**
 * On login show the last known IP of the user
 */
$config['authShowLastIpOnLogin'] = false;

// --------------------------------------------------------------------------

/**
 * Auth sub config files
 * Load both versions, app version overrides Nails version
 */
$sAppPath   = NAILS_APP_PATH . 'application/modules/auth/config/';
$sNailsPath = NAILS_PATH . 'module-auth/auth/config/';

$aFiles = [
    'auth.twofactor.php',
];

foreach ($aFiles as $sFile) {

    if (file_exists($sNailsPath . $sFile)) {
        include $sNailsPath . $sFile;
    }

    if (file_exists($sAppPath . $sFile)) {
        include $sAppPath . $sFile;
    }
}

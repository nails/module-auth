<?php

/**
 * This file provides passkey related helper functions
 *
 * These exist so that an app which has taken over `auth/views/login/form.php` can
 * still opt into the passkey UI without copying markup out of the module.
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    Helper
 * @author      Nails Dev Team
 * @link
 */

use Nails\Auth\Constants;
use Nails\Auth\Service\Passkey;
use Nails\Common\Service\Asset;
use Nails\Factory;

if (!function_exists('passkeysEnabled')) {

    /**
     * Whether passkeys are available to this app
     */
    function passkeysEnabled(): bool
    {
        /** @var Passkey $oService */
        $oService = Factory::service('Passkey', Constants::MODULE_SLUG);

        return $oService->isEnabled();
    }
}

if (!function_exists('loadPasskeyAssets')) {

    /**
     * Loads the passkey JavaScript, if passkeys are enabled
     *
     * Safe to call more than once; the asset service de-duplicates.
     */
    function loadPasskeyAssets(): void
    {
        if (!passkeysEnabled()) {
            return;
        }

        /** @var Asset $oAsset */
        $oAsset = Factory::service('Asset');
        $oAsset->load('passkey.min.js', Constants::MODULE_SLUG, 'JS', false, true);
    }
}

if (!function_exists('passkeyLoginButton')) {

    /**
     * Returns the "Sign in with a passkey" block: a rule, the button, and its error
     * placeholder
     *
     * Place it below the password controls; a passkey is a different way in rather
     * than a variant of the password. The whole block is hidden until the JavaScript
     * establishes that the browser supports WebAuthn, so a browser which cannot use
     * it is never left with a rule and nothing beneath it.
     *
     * @param string|null $sReturnTo   Where to send the user once they are signed in
     * @param string|null $sLabel      Overrides the button's text
     * @param string      $sAttr       Additional attributes for the button
     * @param bool        $bSeparator  Whether to draw the rule above the button
     */
    function passkeyLoginButton(
        ?string $sReturnTo = null,
        ?string $sLabel = null,
        string $sAttr = 'class="btn btn--block btn--secondary"',
        bool $bSeparator = true
    ): string {

        if (!passkeysEnabled()) {
            return '';
        }

        return sprintf(
            '<div class="passkey-alternative" data-passkey-block hidden>' .
            '%s' .
            '<button type="button" data-passkey-login data-passkey-return-to="%s" %s hidden>%s</button>' .
            '<p class="form__feedback form__feedback--invalid" data-passkey-error hidden></p>' .
            '</div>',
            $bSeparator ? '<hr/>' : '',
            htmlspecialchars((string) $sReturnTo, ENT_QUOTES),
            $sAttr,
            htmlspecialchars($sLabel ?: lang('auth_login_passkey_button'), ENT_QUOTES)
        );
    }
}

if (!function_exists('passkeyRegisterButton')) {

    /**
     * Returns an "Add a passkey" button, plus its error placeholder
     *
     * @param string|null $sLabel The button's text
     * @param string      $sAttr  Additional attributes for the button
     */
    function passkeyRegisterButton(
        ?string $sLabel = null,
        string $sAttr = 'class="btn btn--primary"'
    ): string {

        if (!passkeysEnabled()) {
            return '';
        }

        return sprintf(
            '<button type="button" data-passkey-register %s hidden>%s</button>' .
            '<p class="form__feedback form__feedback--invalid" data-passkey-error hidden></p>',
            $sAttr,
            htmlspecialchars($sLabel ?: lang('auth_passkeys_add'), ENT_QUOTES)
        );
    }
}

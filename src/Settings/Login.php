<?php

namespace Nails\Auth\Settings;

use Nails\Auth\Admin\Permission;
use Nails\Auth\Constants;
use Nails\Auth\Service\Passkey;
use Nails\Common\Helper\Form;
use Nails\Common\Interfaces;
use Nails\Components\Setting;
use Nails\Factory;

/**
 * Class Login
 *
 * @package Nails\Auth\Settings
 */
class Login implements Interfaces\Component\Settings
{
    const KEY_USER_LOGIN_CAPTCHA_ENABLED = 'user_login_captcha_enabled';

    // --------------------------------------------------------------------------

    /**
     * @inheritDoc
     */
    public function getLabel(): string
    {
        return 'Auth: Login';
    }

    // --------------------------------------------------------------------------

    /**
     * @inheritDoc
     */
    public function getPermission(): string
    {
        return Permission\Settings\Login::class;
    }

    // --------------------------------------------------------------------------

    /**
     * @inheritDoc
     */
    public function get(): array
    {
        /** @var Passkey $oPasskeyService */
        $oPasskeyService = Factory::service('Passkey', Constants::MODULE_SLUG);

        /** @var Setting $oCaptcha */
        $oCaptcha = Factory::factory('ComponentSetting');
        $oCaptcha
            ->setKey(static::KEY_USER_LOGIN_CAPTCHA_ENABLED)
            ->setType(Form::FIELD_BOOLEAN)
            ->setLabel('Captcha')
            ->setFieldset('Login')
            ->setSaveFormatter(function ($mValue) {
                return (bool) $mValue;
            });

        /** @var Setting $oPasskeysEnabled */
        $oPasskeysEnabled = Factory::factory('ComponentSetting');
        $oPasskeysEnabled
            ->setKey('passkey_enabled')
            ->setLabel('Passkeys')
            ->setFieldset('Passkeys')
            ->setIsReadOnly(true)
            ->setDefault($oPasskeyService->isEnabled() ? 'Enabled' : 'Disabled')
            ->setInfo(sprintf(
                'Enable with <code>%s</code>. The Relying Party ID is derived from <code>BASE_URL</code>; override with <code>%s</code> and <code>%s</code>. Changing the Relying Party ID invalidates existing passkeys.',
                Passkey::CONFIG_ENABLED,
                Passkey::CONFIG_RP_ID,
                Passkey::CONFIG_ALLOWED_ORIGINS
            ))
            ->setInfoClass('alert alert-info');

        /** @var Setting $oPasskeyRpId */
        $oPasskeyRpId = Factory::factory('ComponentSetting');
        $oPasskeyRpId
            ->setKey('passkey_rp_id')
            ->setLabel('Relying Party ID')
            ->setFieldset('Passkeys')
            ->setIsReadOnly(true)
            ->setDefault($oPasskeyService->getRpId());

        /** @var Setting $oPasskeyOrigins */
        $oPasskeyOrigins = Factory::factory('ComponentSetting');
        $oPasskeyOrigins
            ->setKey('passkey_allowed_origins')
            ->setLabel('Permitted origins')
            ->setFieldset('Passkeys')
            ->setIsReadOnly(true)
            ->setDefault(implode(', ', $oPasskeyService->getAllowedOrigins()));

        return [
            $oCaptcha,
            $oPasskeysEnabled,
            $oPasskeyRpId,
            $oPasskeyOrigins,
        ];
    }
}

<?php

namespace Nails\Auth\Settings;

use Nails\Auth\Admin\Permission;
use Nails\Common\Helper\Form;
use Nails\Common\Interfaces;
use Nails\Components\Setting;
use Nails\Factory;

/**
 * Class PasswordReset
 *
 * @package Nails\Auth\Settings
 */
class PasswordReset implements Interfaces\Component\Settings
{
    const KEY_USER_PASSWORD_RESET_CAPTCHA_ENABLED = 'user_password_reset_captcha_enabled';

    // --------------------------------------------------------------------------

    /**
     * @inheritDoc
     */
    public function getLabel(): string
    {
        return 'Auth: Password Reset';
    }

    // --------------------------------------------------------------------------

    /**
     * @inheritDoc
     */
    public function getPermission(): string
    {
        return Permission\Settings\Password::class;
    }

    // --------------------------------------------------------------------------

    /**
     * @inheritDoc
     */
    public function get(): array
    {
        /** @var Setting $oCaptcha */
        $oCaptcha = Factory::factory('ComponentSetting');
        $oCaptcha
            ->setKey(static::KEY_USER_PASSWORD_RESET_CAPTCHA_ENABLED)
            ->setType(Form::FIELD_BOOLEAN)
            ->setLabel('Captcha')
            ->setSaveFormatter(function ($mValue) {
                return (bool) $mValue;
            });

        return [
            $oCaptcha,
        ];
    }
}

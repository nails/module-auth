<?php

namespace Nails\Auth\Settings;

use Nails\Auth\Admin\Permission;
use Nails\Common\Helper\Form;
use Nails\Common\Interfaces;
use Nails\Components\Setting;
use Nails\Factory;

/**
 * Class Registration
 *
 * @package Nails\Auth\Settings
 */
class Registration implements Interfaces\Component\Settings
{
    const KEY_USER_REGISTRATION_ENABLED         = 'user_registration_enabled';
    const KEY_USER_REGISTRATION_CAPTCHA_ENABLED = 'user_registration_captcha_enabled';

    // --------------------------------------------------------------------------

    /**
     * @inheritDoc
     */
    public function getLabel(): string
    {
        return 'Auth: Registration';
    }

    // --------------------------------------------------------------------------

    /**
     * @inheritDoc
     */
    public function getPermission(): string
    {
        return Permission\Settings\Registration::class;
    }

    // --------------------------------------------------------------------------

    /**
     * @inheritDoc
     */
    public function get(): array
    {
        /** @var Setting $oEnabled */
        $oEnabled = Factory::factory('ComponentSetting');
        $oEnabled
            ->setKey(static::KEY_USER_REGISTRATION_ENABLED)
            ->setType(Form::FIELD_BOOLEAN)
            ->setLabel('Enabled')
            ->setInfo('If not using a custom registration flow, you may enable or disable public registrations. Admin will always be able to create users.')
            ->setSaveFormatter(function ($mValue) {
                return (bool) $mValue;
            });

        /** @var Setting $oCaptcha */
        $oCaptcha = Factory::factory('ComponentSetting');
        $oCaptcha
            ->setKey(static::KEY_USER_REGISTRATION_CAPTCHA_ENABLED)
            ->setType(Form::FIELD_BOOLEAN)
            ->setLabel('Captcha')
            ->setInfo('May not apply to custom registration flow.')
            ->setSaveFormatter(function ($mValue) {
                return (bool) $mValue;
            });

        return [
            $oEnabled,
            $oCaptcha,
        ];
    }
}

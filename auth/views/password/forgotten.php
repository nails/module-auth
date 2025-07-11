<?php

use Nails\Captcha\Constants;
use Nails\Captcha\Service\Captcha;
use Nails\Common\Service\Input;
use Nails\Common\Service\View;
use Nails\Config;
use Nails\Factory;

/** @var Input $oInput */
$oInput = Factory::service('Input');
/** @var View $oView */
$oView = Factory::service('View');

?>
<div class="nails-auth forgotten-password center-screen">
    <div class="panel">
        <div class="panel__header">
            <h1 class="panel__title text-center">
                Password Reset
            </h1>
        </div>
        <div class="panel__body">
            <?php

            echo form_open('auth/password/forgotten', 'class="form"');
            $oView->load('auth/_components/alerts');

            ?>
            <p>
                <?=lang('auth_forgot_message')?>
            </p>
            <?php

            switch (Config::get('APP_NATIVE_LOGIN_USING')) {

                case 'EMAIL':

                    $sFieldLabel       = lang('form_label_email');
                    $sFieldPlaceholder = lang('auth_forgot_email_placeholder');
                    $sFieldType        = 'form_email';
                    break;

                case 'USERNAME':

                    $sFieldLabel       = lang('form_label_username');
                    $sFieldPlaceholder = lang('auth_forgot_username_placeholder');
                    $sFieldType        = 'form_input';
                    break;

                default:

                    $sFieldLabel       = lang('auth_forgot_both');
                    $sFieldPlaceholder = lang('auth_forgot_both_placeholder');
                    $sFieldType        = 'form_input';
                    break;
            }

            $sFieldKey  = 'identifier';
            $sFieldAttr = 'id="input-' . $sFieldKey . '" placeholder="' . $sFieldPlaceholder . '" class="form__control"';

            ?>
            <div class="form__group">
                <label class="form__label" for="input-<?=$sFieldKey?>"><?=$sFieldLabel?></label>
                <?=$sFieldType($sFieldKey, set_value($sFieldKey, $oInput->get('email')), $sFieldAttr)?>
                <?=form_error($sFieldKey, '<p class="form__feedback form__feedback--invalid">', '</p>')?>
            </div>
            <?php

            if (appSetting('user_password_reset_captcha_enabled', 'auth')) {
                ?>
                <div class="form__group">
                    <?php
                    /** @var Captcha $oCaptchaService */
                    $oCaptchaService = Factory::service('Captcha', Constants::MODULE_SLUG);
                    echo $oCaptchaService->generate()->getHtml();
                    ?>
                </div>
                <?php
            }

            ?>
            <div class="form__actions">
                <button type="submit" class="btn btn--block btn--primary">
                    <?=lang('auth_forgot_action_reset')?>
                </button>
                <?=anchor(loginUrl(null), 'Log In', 'class="btn btn--block btn--link"')?>
            </div>
            <?=form_close()?>
        </div>
    </div>
</div>

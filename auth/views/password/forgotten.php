<?php

use Nails\Captcha;
use Nails\Common\Service\Input;
use Nails\Common\Service\View;
use Nails\Factory;

/** @var Input $oInput */
$oInput = Factory::service('Input');
/** @var View $oView */
$oView = Factory::service('View');

?>
<div class="nails-auth forgotten-password u-center-screen">
    <div class="panel">
        <h1 class="panel__header text-center">
            Password Reset
        </h1>
        <?=form_open('auth/password/forgotten')?>
        <div class="panel__body">
            <?php

            $oView->load('auth/_components/alerts');

            ?>
            <p>
                <?=lang('auth_forgot_message')?>
            </p>
            <?php

            switch (\Nails\Config::get('APP_NATIVE_LOGIN_USING')) {

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
            $sFieldAttr = 'id="input-' . $sFieldKey . '" placeholder="' . $sFieldPlaceholder . '"';

            ?>
            <div class="form__group <?=form_error($sFieldKey) ? 'has-error' : ''?>">
                <label for="input-<?=$sFieldKey?>"><?=$sFieldLabel?></label>
                <?=$sFieldType($sFieldKey, set_value($sFieldKey, $oInput->get('email')), $sFieldAttr)?>
                <?=form_error($sFieldKey, '<p class="form__error">', '</p>')?>
            </div>
            <?php

            if (appSetting('user_password_reset_captcha_enabled', 'auth')) {

                $sFieldKey = 'g-recaptcha-response';

                ?>
                <div class="form__group <?=form_error($sFieldKey) ? 'has-error' : ''?>">
                    <?php
                    /** @var Captcha\Service\Captcha $oCaptchaService */
                    $oCaptchaService = Factory::service('Captcha', Captcha\Constants::MODULE_SLUG);
                    echo $oCaptchaService->generate()->getHtml();
                    echo form_error($sFieldKey, '<p class="form__error">', '</p>');
                    ?>
                </div>
                <?php
            }

            ?>
            <p>
                <button type="submit" class="btn btn--block btn--primary">
                    <?=lang('auth_forgot_action_reset')?>
                </button>
                <?=anchor('auth/login', 'Log In', 'class="btn btn--block btn--link"')?>
            </p>
        </div>
        <?=form_close()?>
    </div>
</div>

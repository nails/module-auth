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

$sReturnTo = $return_to ? '?return_to=' . urlencode($return_to) : '';

?>
<div class="nails-auth login container center-screen">
    <div class="panel">
        <div class="panel__header">
            <h1 class="panel__title text-center">
                Welcome
            </h1>
        </div>
        <div class="panel__body">
            <?php

            echo form_open(loginUrl($return_to), 'class="form"');
            $oView->load('auth/_components/alerts');

            if ($social_signon_enabled) {
                ?>
                <p class="text-center">
                    Sign in using your preferred social network.
                </p>
                <?php

                foreach ($social_signon_providers as $aProvider) {
                    echo anchor(
                        loginUrl(null) . '/' . $aProvider['slug'] . $sReturnTo,
                        $aProvider['label'],
                        'class="btn btn--block btn--primary"'
                    );
                }

                ?>
                <hr/>
                <p class="text-center">
                    <?php
                    switch (Config::get('APP_NATIVE_LOGIN_USING')) {
                        case 'EMAIL':
                            echo 'Or sign in using your email address and password.';
                            break;

                        case 'USERNAME':
                            echo 'Or sign in using your username and password.';
                            break;

                        default:
                            echo 'Or sign in using your email address or username and password.';
                            break;
                    }
                    ?>
                </p>
                <?php
            }

            switch (Config::get('APP_NATIVE_LOGIN_USING')) {

                case 'EMAIL':
                    $sFieldLabel       = lang('form_label_email');
                    $sFieldPlaceholder = lang('auth_login_email_placeholder');
                    $FieldType         = 'form_email';
                    break;

                case 'USERNAME':
                    $sFieldLabel       = lang('form_label_username');
                    $sFieldPlaceholder = lang('auth_login_username_placeholder');
                    $FieldType         = 'form_input';
                    break;

                default:
                    $sFieldLabel       = lang('auth_login_both');
                    $sFieldPlaceholder = lang('auth_login_both_placeholder');
                    $FieldType         = 'form_input';
                    break;
            }

            $sFieldKey   = 'identifier';
            $sFieldAttr  = 'id="input-' . $sFieldKey . '" placeholder="' . $sFieldPlaceholder . '" class="form__control"';
            $sFieldValue = set_value($sFieldKey, $oInput->get('identity'), false);

            ?>
            <div class="form__group">
                <label class="form__label" for="input-<?=$sFieldKey?>"><?=$sFieldLabel?></label>
                <?=$FieldType($sFieldKey, $sFieldValue, $sFieldAttr)?>
                <?=form_error($sFieldKey, '<p class="form__feedback form__feedback--invalid">', '</p>')?>
            </div>
            <?php

            $sFieldKey         = 'password';
            $sFieldLabel       = lang('form_label_password');
            $sFieldPlaceholder = lang('auth_login_password_placeholder');
            $sFieldAttr        = 'id="input-' . $sFieldKey . '" placeholder="' . $sFieldPlaceholder . '" class="form__control"';

            ?>
            <div class="form__group">
                <label class="form__label" for="input-<?=$sFieldKey?>"><?=$sFieldLabel?></label>
                <?=form_password($sFieldKey, set_value($sFieldKey), $sFieldAttr)?>
                <?=form_error($sFieldKey, '<p class="form__feedback form__feedback--invalid">', '</p>')?>
            </div>
            <div class="form__group form__group--checkbox-compact">
                <input type="checkbox" id="remember-me" name="remember" <?=set_checkbox('remember')?>>
                <label for="remember-me">Keep me logged in on this device</label>
            </div>
            <?php

            if (appSetting('user_login_captcha_enabled', 'auth')) {
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
                    Sign in
                </button>
                <?php

                echo anchor('auth/password/forgotten', 'Forgotten Your Password?', 'class="btn btn--block btn--link"');

                ?>
            </div>
            <?=form_close()?>
        </div>
        <?php

        if (appSetting('user_registration_enabled', 'auth')) {
            ?>
            <div class="panel__footer">
                <p class="text-center">
                    Not got an account? <?=anchor(registerUrl(null), 'Register now')?>
                </p>
            </div>
            <?php
        }

        ?>
    </div>
</div>

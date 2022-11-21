<?php

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
<div class="nails-auth login u-center-screen">
    <div class="panel">
        <h1 class="panel__header text-center">
            Welcome
        </h1>
        <?=form_open(siteUrl('auth/login' . $sReturnTo))?>
        <div class="panel__body">
            <?php

            $oView->load('auth/_components/alerts');

            if ($social_signon_enabled) {
                ?>
                <p class="text-center">
                    Sign in using your preferred social network.
                </p>
                <?php

                foreach ($social_signon_providers as $aProvider) {
                    echo anchor(
                        'auth/login/' . $aProvider['slug'] . $sReturnTo,
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
            $sFieldAttr  = 'id="input-' . $sFieldKey . '" placeholder="' . $sFieldPlaceholder . '"';
            $sFieldValue = set_value($sFieldKey, $oInput->get('identity'), false);

            ?>
            <div class="form__group <?=form_error($sFieldKey) ? 'has-error' : ''?>">
                <label for="input-<?=$sFieldKey?>"><?=$sFieldLabel?></label>
                <?=$FieldType($sFieldKey, $sFieldValue, $sFieldAttr)?>
                <?=form_error($sFieldKey, '<p class="form__error">', '</p>')?>
            </div>
            <?php

            $sFieldKey         = 'password';
            $sFieldLabel       = lang('form_label_password');
            $sFieldPlaceholder = lang('auth_login_password_placeholder');
            $sFieldAttr        = 'id="input-' . $sFieldKey . '" placeholder="' . $sFieldPlaceholder . '"';

            ?>
            <div class="form__group <?=form_error($sFieldKey) ? 'has-error' : ''?>">
                <label for="input-<?=$sFieldKey?>"><?=$sFieldLabel?></label>
                <?=form_password($sFieldKey, set_value($sFieldKey), $sFieldAttr)?>
                <?=form_error($sFieldKey, '<p class="form__error">', '</p>')?>
            </div>
            <div class="form__group form__group--checkbox">
                <div class="col-sm-offset-3 col-sm-9">
                    <label>
                        <input type="checkbox" name="remember" <?=set_checkbox('remember')?>>
                        Remember me
                    </label>
                </div>
            </div>
            <?php

            if (appSetting('user_login_captcha_enabled', 'auth')) {

                $sFieldKey = 'g-recaptcha-response';

                ?>
                <div class="form__group <?=form_error($sFieldKey) ? 'has-error' : ''?>">
                    <?php
                    /** @var \Nails\Captcha\Service\Captcha $oCaptchaService */
                    $oCaptchaService = Factory::service('Captcha', Nails\Captcha\Constants::MODULE_SLUG);
                    echo $oCaptchaService->generate()->getHtml();
                    echo form_error($sFieldKey, '<p class="form__error">', '</p>');
                    ?>
                </div>
                <?php
            }

            ?>
            <p>
                <button type="submit" class="btn btn--block btn--primary">
                    Sign in
                </button>
                <?=anchor('auth/password/forgotten', 'Forgotten Your Password?', 'class="btn btn--block btn--link"')?>
            </p>
            <?php
            if (appSetting('user_registration_enabled', 'auth')) {
                ?>
                <hr/>
                <p class="text-center">
                    Not got an account?
                </p>
                <p class="text-center">
                    <?=anchor('auth/register', 'Register now', 'class="btn btn--block"')?>
                </p>
                <?php
            }
            ?>
        </div>
        <?=form_close()?>
    </div>
</div>

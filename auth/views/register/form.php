<?php

use Nails\Common\Service\View;
use Nails\Factory;

/** @var View $oView */
$oView = Factory::service('View');

?>
<div class="nails-auth register u-center-screen">
    <div class="panel">
        <h1 class="panel__header text-center">
            Register
        </h1>
        <div class="panel__body">
            <?php

            $oView->load('auth/_components/alerts');

            if ($social_signon_enabled) {
                ?>
                <p class="text-center">
                    Register using your preferred social network.
                </p>
                <?php

                foreach ($social_signon_providers as $aProvider) {
                    echo anchor(
                        loginUrl(false) . '/' . $aProvider['slug'],
                        $aProvider['label'],
                        'class="btn btn--block btn--primary"'
                    );
                }

                ?>
                <hr />
                <p class="text-center">
                    <?php
                    switch (\Nails\Config::get('APP_NATIVE_LOGIN_USING')) {
                        case 'EMAIL':
                            echo 'Or register using your email address and password.';
                            break;

                        case 'USERNAME':
                            echo 'Or register using your username and password.';
                            break;

                        default:
                            echo 'Or register using your email address or username and password.';
                            break;
                    }
                    ?>
                </p>
                <?php
            }

            echo form_open(registerUrl(false), 'class="form form-horizontal"');

            // --------------------------------------------------------------------------

            if (\Nails\Config::get('APP_NATIVE_LOGIN_USING') === 'EMAIL' || \Nails\Config::get('APP_NATIVE_LOGIN_USING') === 'BOTH') {

                $sFieldKey         = 'email';
                $sFieldLabel       = lang('form_label_email');
                $sFieldPlaceholder = lang('auth_register_email_placeholder');
                $sFieldAttr        = 'id="input-' . $sFieldKey . '" placeholder="' . $sFieldPlaceholder . '"';

                ?>
                <div class="form__group <?=form_error($sFieldKey) ? 'has-error' : ''?>">
                    <label for="input-<?=$sFieldKey?>"><?=$sFieldLabel?></label>
                    <?=form_text($sFieldKey, set_value($sFieldKey), $sFieldAttr)?>
                    <?=form_error($sFieldKey, '<p class="form__error">', '</p>')?>
                </div>
                <?php
            }

            // --------------------------------------------------------------------------

            if (\Nails\Config::get('APP_NATIVE_LOGIN_USING') === 'USERNAME' || \Nails\Config::get('APP_NATIVE_LOGIN_USING') === 'BOTH') {

                $sFieldKey         = 'username';
                $sFieldLabel       = lang('form_label_username');
                $sFieldPlaceholder = lang('auth_register_username_placeholder');
                $sFieldAttr        = 'id="input-' . $sFieldKey . '" placeholder="' . $sFieldPlaceholder . '"';

                ?>
                <div class="form__group <?=form_error($sFieldKey) ? 'has-error' : ''?>">
                    <label for="input-<?=$sFieldKey?>"><?=$sFieldLabel?></label>
                    <?=form_text($sFieldKey, set_value($sFieldKey), $sFieldAttr)?>
                    <?=form_error($sFieldKey, '<p class="form__error">', '</p>')?>
                    <p class="form__help">
                        <small>
                            Usernames can only contain alpha numeric characters, underscores,
                            periods and dashes (no spaces).
                        </small>
                    </p>
                </div>
                <?php
            }

            // --------------------------------------------------------------------------

            $sFieldKey         = 'password';
            $sFieldLabel       = lang('form_label_password');
            $sFieldPlaceholder = lang('auth_register_password_placeholder');
            $sFieldAttr        = 'id="input-' . $sFieldKey . '" placeholder="' . $sFieldPlaceholder . '"';

            ?>
            <div class="form__group <?=form_error($sFieldKey) ? 'has-error' : ''?>">
                <label for="input-<?=$sFieldKey?>"><?=$sFieldLabel?></label>
                <?=form_password($sFieldKey, set_value($sFieldKey), $sFieldAttr)?>
                <?=form_error($sFieldKey, '<p class="form__error">', '</p>')?>
                <?php
                if (!empty($passwordRulesAsString)) {
                    ?>
                    <p class="form__help">
                        <small><?=$passwordRulesAsString?></small>
                    </p>
                    <?php
                }
                ?>
            </div>
            <?php

            // --------------------------------------------------------------------------

            $sFieldKey         = 'first_name';
            $sFieldLabel       = lang('form_label_first_name');
            $sFieldPlaceholder = lang('auth_register_first_name_placeholder');
            $sFieldAttr        = 'id="input-' . $sFieldKey . '" placeholder="' . $sFieldPlaceholder . '"';

            ?>
            <div class="form__group <?=form_error($sFieldKey) ? 'has-error' : ''?>">
                <label for="input-<?=$sFieldKey?>"><?=$sFieldLabel?></label>
                <?=form_text($sFieldKey, set_value($sFieldKey), $sFieldAttr)?>
                <?=form_error($sFieldKey, '<p class="form__error">', '</p>')?>
            </div>
            <?php

            // --------------------------------------------------------------------------

            $sFieldKey         = 'last_name';
            $sFieldLabel       = lang('form_label_last_name');
            $sFieldPlaceholder = lang('auth_register_last_name_placeholder');
            $sFieldAttr        = 'id="input-' . $sFieldKey . '" placeholder="' . $sFieldPlaceholder . '"';

            ?>
            <div class="form__group <?=form_error($sFieldKey) ? 'has-error' : ''?>">
                <label for="input-<?=$sFieldKey?>"><?=$sFieldLabel?></label>
                <?=form_text($sFieldKey, set_value($sFieldKey), $sFieldAttr)?>
                <?=form_error($sFieldKey, '<p class="form__error">', '</p>')?>
            </div>
            <?php

            if (appSetting('user_registration_captcha_enabled', 'auth')) {
                ?>
                <div class="form__group">
                    <?php
                    /** @var \Nails\Captcha\Service\Captcha $oCaptchaService */
                    $oCaptchaService = \Nails\Factory::service('Captcha', Nails\Captcha\Constants::MODULE_SLUG);
                    echo $oCaptchaService->generate()->getHtml();
                    ?>
                </div>
                <?php
            }

            ?>
            <p>
                <button type="submit" class="btn btn--block btn--primary">
                    <?=lang('action_register')?>
                </button>
            </p>
            <?=form_close()?>
            <hr />
            <p class="text-center">
                Already got an account?
            </p>
            <p>
                <?=anchor(loginUrl(false), 'Sign in now', 'class="btn btn--block"')?>
            </p>
        </div>
    </div>
</div>

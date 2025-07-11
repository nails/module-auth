<?php

use Nails\Common\Service\View;
use Nails\Factory;

/** @var View $oView */
$oView = Factory::service('View');

?>
<div class="nails-auth reset-password center-screen">
    <div class="panel">
        <div class="panel__header">
            <h1 class="panel__title text-center">
                Reset Password
            </h1>
        </div>
        <?php

        $aQuery = [];

        if ($return_to) {
            $aQuery['return_to'] = $return_to;
        }

        if ($remember) {
            $aQuery['remember'] = $remember;
        }

        $sQuery = $aQuery ? '?' . http_build_query($aQuery) : '';


        ?>
        <div class="panel__body">
            <?php

            echo form_open($resetUrl . $sQuery, 'class="form form-horizontal"');
            $oView->load('auth/_components/alerts');

            if (!empty($mfaQuestion)) {

                $sFieldKey         = 'mfaAnswer';
                $sFieldLabel       = 'Security Question';
                $sFieldPlaceholder = 'Type your answer';
                $sFieldAttr        = 'id="input-' . $sFieldKey . '" placeholder="' . $sFieldPlaceholder . '" class="form__control"';

                ?>
                <div class="form__group">
                    <label class="form__label" for="input-<?=$sFieldKey?>"><?=$sFieldLabel?></label>
                    <p>
                        <strong>
                            <?=$mfaQuestion->question?>
                        </strong>
                    </p>
                    <?=form_password($sFieldKey, set_value($sFieldKey), $sFieldAttr)?>
                    <?=form_error($sFieldKey, '<p class="form__feedback form__feedback--invalid">', '</p>')?>
                </div>
                <?php
            }

            // --------------------------------------------------------------------------

            if (!empty($mfaDevice)) {

                $sFieldKey         = 'mfaCode';
                $sFieldLabel       = 'Security Code';
                $sFieldPlaceholder = 'Type your code';
                $sFieldAttr        = 'id="input-' . $sFieldKey . '" placeholder="' . $sFieldPlaceholder . '" class="form__control"';

                ?>
                <div class="form__group">
                    <label class="form__label" for="input-<?=$sFieldKey?>"><?=$sFieldLabel?></label>
                    <?=form_input($sFieldKey, set_value($sFieldKey), $sFieldAttr)?>
                    <?=form_error($sFieldKey, '<p class="form__feedback form__feedback--invalid">', '</p>')?>
                    <p class="form__help">
                        <small>
                            Use your device to generate a single use code.
                        </small>
                    </p>
                </div>
                <?php
            }

            // --------------------------------------------------------------------------

            $sFieldKey         = 'new_password';
            $sFieldLabel       = lang('form_label_password');
            $sFieldPlaceholder = lang('auth_forgot_new_pass_placeholder');
            $sFieldAttr        = 'id="input-' . $sFieldKey . '" placeholder="' . $sFieldPlaceholder . '" class="form__control"';

            ?>
            <div class="form__group">
                <label class="form__label" for="input-<?=$sFieldKey?>"><?=$sFieldLabel?></label>
                <?=form_password($sFieldKey, set_value($sFieldKey), $sFieldAttr)?>
                <?=form_error($sFieldKey, '<p class="form__feedback form__feedback--invalid">', '</p>')?>
                <?php
                if (!empty($passwordRules)) {
                    ?>
                    <p class="form__help">
                        <small>
                            <?=$passwordRules?>
                        </small>
                    </p>
                    <?php
                }
                ?>
            </div>
            <?php

            $sFieldKey         = 'confirm_pass';
            $sFieldLabel       = lang('form_label_password_confirm');
            $sFieldPlaceholder = lang('auth_forgot_new_pass_confirm_placeholder');
            $sFieldAttr        = 'id="input-' . $sFieldKey . '" placeholder="' . $sFieldPlaceholder . '" class="form__control"';

            ?>
            <div class="form__group">
                <label class="form__label" for="input-<?=$sFieldKey?>"><?=$sFieldLabel?></label>
                <?=form_password($sFieldKey, set_value($sFieldKey), $sFieldAttr)?>
                <?=form_error($sFieldKey, '<p class="form__feedback form__feedback--invalid">', '</p>')?>
            </div>
            <div class="form__actions">
                <button type="submit" class="btn btn--block btn--primary">
                    <?=lang('auth_forgot_action_reset_continue')?>
                </button>
            </div>
            <?=form_close()?>
        </div>
    </div>
</div>

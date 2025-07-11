<?php

use Nails\Common\Service\View;
use Nails\Config;
use Nails\Factory;

/** @var View $oView */
$oView = Factory::service('View');

?>
<div class="nails-auth login social center-screen">
    <div class="panel">
        <div class="panel__header">
            <h1 class="panel__title text-center">
                Welcome
            </h1>
        </div>
        <div class="panel__body">
            <?php

            echo form_open($form_url, 'class="form"');
            $oView->load('auth/_components/alerts');

            ?>
            <p class="text-center">
                <?=lang('auth_register_extra_message')?>
            </p>
            <?php
            if (Config::get('APP_NATIVE_LOGIN_USING') == 'EMAIL' || Config::get('APP_NATIVE_LOGIN_USING') != 'USERNAME') {
                if (isset($required_data['email'])) {

                    $sFieldKey         = 'email';
                    $FieldType         = 'form_email';
                    $sFieldLabel       = lang('form_label_email');
                    $sFieldPlaceholder = lang('auth_register_email_placeholder');
                    $sDefault          = $required_data['email'];
                    $sFieldAttr        = 'id="input-' . $sFieldKey . '" placeholder="' . $sFieldPlaceholder . '" class="form__control"';

                    ?>
                    <div class="form__group">
                        <label class="form__label" for="input-<?=$sFieldKey?>"><?=$sFieldLabel?></label>
                        <?=$FieldType($sFieldKey, set_value($sFieldKey), $sFieldAttr)?>
                        <?=form_error($sFieldKey, '<p class="form__feedback form__feedback--invalid">', '</p>')?>
                    </div>
                    <?php
                }
            }

            if (Config::get('APP_NATIVE_LOGIN_USING') == 'USERNAME' || Config::get('APP_NATIVE_LOGIN_USING') != 'EMAIL') {

                if (isset($required_data['username'])) {

                    $sFieldKey         = 'username';
                    $FieldType         = 'form_input';
                    $sFieldLabel       = lang('form_label_username');
                    $sFieldPlaceholder = lang('auth_register_username_placeholder');
                    $sDefault          = $required_data['username'];
                    $sFieldAttr        = 'id="input-' . $sFieldKey . '" placeholder="' . $sFieldPlaceholder . '" class="form__control"';

                    ?>
                    <div class="form__group">
                        <label class="form__label" for="input-<?=$sFieldKey?>"><?=$sFieldLabel?></label>
                        <?=$FieldType($sFieldKey, set_value($sFieldKey), $sFieldAttr)?>
                        <?=form_error($sFieldKey, '<p class="form__feedback form__feedback--invalid">', '</p>')?>
                    </div>
                    <?php

                }

            }

            if (!$required_data['first_name'] || !$required_data['last_name']) {

                $sFieldKey         = 'first_name';
                $FieldType         = 'form_input';
                $sFieldLabel       = lang('form_label_first_name');
                $sFieldPlaceholder = lang('auth_register_first_name_placeholder');
                $sDefault          = !empty($required_data['first_name']) ? $required_data['first_name'] : '';
                $sFieldAttr        = 'id="input-' . $sFieldKey . '" placeholder="' . $sFieldPlaceholder . '" class="form__control"';

                ?>
                <div class="form__group">
                    <label class="form__label" for="input-<?=$sFieldKey?>"><?=$sFieldLabel?></label>
                    <?=$FieldType($sFieldKey, set_value($sFieldKey), $sFieldAttr)?>
                    <?=form_error($sFieldKey, '<p class="form__feedback form__feedback--invalid">', '</p>')?>
                </div>
                <?php

                // --------------------------------------------------------------------------

                $sFieldKey         = 'last_name';
                $FieldType         = 'form_input';
                $sFieldLabel       = lang('form_label_last_name');
                $sFieldPlaceholder = lang('auth_register_last_name_placeholder');
                $sDefault          = !empty($required_data['last_name']) ? $required_data['last_name'] : '';
                $sFieldAttr        = 'id="input-' . $sFieldKey . '" placeholder="' . $sFieldPlaceholder . '" class="form__control"';

                ?>
                <div class="form__group">
                    <label class="form__label" for="input-<?=$sFieldKey?>"><?=$sFieldLabel?></label>
                    <?=$FieldType($sFieldKey, set_value($sFieldKey), $sFieldAttr)?>
                    <?=form_error($sFieldKey, '<p class="form__feedback form__feedback--invalid">', '</p>')?>
                </div>
                <?php

            }
            ?>
            <div class="form__actions">
                <button type="submit" class="btn btn--block btn--primary">
                    <?=lang('action_continue')?>
                </button>
            </div>
            <?=form_close()?>
        </div>
    </div>
</div>

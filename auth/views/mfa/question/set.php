<?php

use Nails\Common\Service\View;
use Nails\Factory;

/** @var View $oView */
$oView = Factory::service('View');

$aQuery = array_filter([
    'return_to' => $return_to,
    'remember'  => $remember,
]);

$sQuery = !empty($aQuery) ? '?' . http_build_query($aQuery) : '';

?>
<div class="nails-auth mfa mfa--question mfa--question--setup center-screen">
    <div class="panel">
        <div class="panel__header">
            <h1 class="panel__title text-center">
                Set up Two Factor Authentication
            </h1>
        </div>
        <div class="panel__body">
            <?php

            echo form_open('auth/mfa/question/' . $user_id . '/' . $token['salt'] . '/' . $token['token'] . $sQuery, 'class="form"');
            $oView->load('auth/_components/alerts');

            if ($num_questions) {
                ?>
                <p>
                    <?=lang('auth_twofactor_question_set_system_body')?>
                </p>
                <?php
                if ($num_custom_questions) {
                    ?>
                    <h3>
                        <?=lang('auth_twofactor_question_set_system_legend')?>
                    </h3>
                    <?php
                }

                for ($i = 0; $i < $num_questions; $i++) {

                    $sFieldKey     = 'question[' . $i . '][question]';
                    $sFieldLabel   = 'Question ' . ($i + 1);
                    $aFieldOptions = array_merge(['Please Choose...'], $questions);

                    ?>
                    <div class="form__group">
                        <label class="form__label" for="input-<?=$sFieldKey?>"><?=$sFieldLabel?></label>
                        <?=form_dropdown($sFieldKey, $aFieldOptions, set_value($sFieldKey), 'id="input-' . $sFieldKey . '" class="form__control form__control--select"')?>
                        <?=form_error($sFieldKey, '<p class="form__feedback form__feedback--invalid">', '</p>')?>
                    </div>
                    <?php

                    $sFieldKey         = 'question[' . $i . '][answer]';
                    $sFieldLabel       = 'Answer ' . ($i + 1);
                    $sFieldPlaceholder = 'Type your answer here';
                    $sFieldAttr        = 'id="input-' . $sFieldKey . '" autocomplete="off" placeholder="' . $sFieldPlaceholder . '" class="form__control"';

                    ?>
                    <div class="form__group">
                        <label class="form__label" for="input-<?=$sFieldKey?>"><?=$sFieldLabel?></label>
                        <?=form_text($sFieldKey, set_value($sFieldKey), $sFieldAttr)?>
                        <?=form_error($sFieldKey, '<p class="form__feedback form__feedback--invalid">', '</p>')?>
                    </div>
                    <?php
                }
            }

            if ($num_custom_questions) {
                ?>
                <p>
                    <?=lang('auth_twofactor_question_set_custom_body')?>
                </p>
                <?php

                if ($num_questions) {
                    ?>
                    <h3>
                        <?=lang('auth_twofactor_question_set_custom_legend')?>
                    </h3>
                    <?php
                }

                for ($i = 0; $i < $num_custom_questions; $i++) {

                    $sFieldKey         = 'custom_question[' . $i . '][question]';
                    $sFieldLabel       = 'Question ' . ($i + 1);
                    $sFieldPlaceholder = 'Type your question here';
                    $sFieldAttr        = 'id="input-' . $sFieldKey . '" autocomplete="off" placeholder="' . $sFieldPlaceholder . '" class="form__control"';

                    ?>
                    <div class="form__group">
                        <label class="form__label" for="input-<?=$sFieldKey?>"><?=$sFieldLabel?></label>
                        <?=form_text($sFieldKey, set_value($sFieldKey), $sFieldAttr)?>
                        <?=form_error($sFieldKey, '<p class="form__feedback form__feedback--invalid">', '</p>')?>
                    </div>
                    <?php

                    $sFieldKey         = 'custom_question[' . $i . '][answer]';
                    $sFieldLabel       = 'Answer ' . ($i + 1);
                    $sFieldPlaceholder = 'Type your answer here';
                    $sFieldAttr        = 'id="input-' . $sFieldKey . '" autocomplete="off" placeholder="' . $sFieldPlaceholder . '" class="form__control"';

                    ?>
                    <div class="form__group">
                        <label class="form__label" for="input-<?=$sFieldKey?>"><?=$sFieldLabel?></label>
                        <?=form_text($sFieldKey, set_value($sFieldKey), $sFieldAttr)?>
                        <?=form_error($sFieldKey, '<p class="form__feedback form__feedback--invalid">', '</p>')?>
                    </div>
                    <?php
                }
            }
            ?>
            <div class="form__actions">
                <button type="submit" class="btn btn--block btn--primary">
                    Save questions &amp; Sign in
                </button>
            </div>
            <?=form_close()?>
        </div>
    </div>
</div>

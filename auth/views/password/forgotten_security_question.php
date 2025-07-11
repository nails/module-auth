<?php

use Nails\Common\Service\View;
use Nails\Factory;

/** @var View $oView */
$oView = Factory::service('View');

?>
<div class="container nails-module-auth password forgotten forgotten-security-question">
    <div class="panel">
        <div class="panel__header">
            <h1 class="panel__title text-center">
                Security Question
            </h1>
        </div>
        <div class="panel__body">
            <?php

            echo form_open(null, 'class="form"');
            $oView->load('auth/_components/alerts');

            ?>
            <p>
                <?=lang('auth_twofactor_answer_body')?>
            </p>
            <div class="form__group">
                <label class="form__label">
                    <?=$question->question?>
                </label>
                <?=form_password('answer', null, 'class="form__control form__control--textarea" placeholder="Type your answer here"')?>
            </div>
            <div class="form__actions">
                <button class="btn btn-lg btn-primary" type="submit">
                    <?=lang('action_continue')?>
                </button>
            </div>
            <?=form_close()?>
        </div>
    </div>
</div>

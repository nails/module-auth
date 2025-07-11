<?php

use Nails\Common\Service\View;
use Nails\Factory;

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
                <?=lang('auth_forgot_reset_ok')?>
            </p>
            <div>
                <p class="alert alert--info new-password">
                    <?=$new_password?>
                </p>
            </div>
            <p>
                <?=anchor(loginUrl(null, ['identity' => $user->identity]), lang('auth_forgot_action_proceed'), 'class="btn btn--block btn--primary"')?>
            </p>
            <?=form_close()?>
        </div>
    </div>
</div>

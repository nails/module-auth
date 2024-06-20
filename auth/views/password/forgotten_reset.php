<?php

use Nails\Common\Service\View;
use Nails\Factory;

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
        </div>
    </div>
</div>

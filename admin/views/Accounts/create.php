<?php

use Nails\Factory;

?>
<div class="group-accounts create">
    <?=form_open()?>
    <p>
        <?=lang('accounts_create_intro')?>
    </p>
    <?php

    $oView = Factory::service('View');
    $oView->load('Accounts/create/inc-basic');

    echo \Nails\Admin\Helper::floatingControls([
        'save' => ['text' => lang('accounts_create_submit')],
    ]);

    ?>
    <?=form_close()?>
</div>

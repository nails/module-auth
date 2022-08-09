<?php

use Nails\Factory;

?>
<div class="group-accounts create">
    <?php

    echo form_open();

    $oView = Factory::service('View');
    $oView->load('Accounts/create/inc-basic');

    echo \Nails\Admin\Helper::floatingControls([
        'save' => ['text' => lang('accounts_create_submit')],
    ]);

    echo form_close();

    ?>
</div>

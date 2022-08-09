<div class="group-accounts change-group">
    <?php

    use Nails\Factory;

    if (!empty($aUsers)) {
        $oInput   = Factory::service('Input');
        $sFormUrl = uri_string() . '?users=' . $oInput->get('users');
        echo form_open($sFormUrl);

        ?>
        <fieldset>
            <legend>Users to Update</legend>
            <table class="table table-striped table-hover table-bordered table-responsive mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    foreach ($aUsers as $oUser) {
                        ?>
                        <tr>
                            <td class="align-middle text-center"><?=number_format($oUser->id)?></td>
                            <?=Nails\Admin\Helper::loadUserCell($oUser)?>
                        </tr>
                        <?php
                    }
                    ?>
                </tbody>
            </table>
        </fieldset>
        <fieldset>
            <legend>New Group</legend>
            <?=form_dropdown('group_id', $aUserGroups, null, 'class="select2" style="width:100%"')?>
        </fieldset>
        <?php

        echo \Nails\Admin\Helper::floatingControls();
    }

    ?>
</div>

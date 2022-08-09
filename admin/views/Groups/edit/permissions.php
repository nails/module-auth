<?php

/**
 * @var \Nails\Admin\Factory\Permission\Group[] $aPermissions
 */

echo form_field_boolean([
    'key'        => 'is_superuser',
    'label'      => 'Is Super User',
    'info'       => '<strong>Note:</strong> Superusers are granted all current and future permissions.',
    'info_class' => 'alert alert-warning',
    'text_on'    => 'Yes',
    'text_off'   => 'No',
    'default'    => isGroupSuperUser($oItem),
    'data'       => [
        'revealer' => 'superuser',
    ],
]);

foreach ($aPermissions as $oGroup) {
    ?>
    <fieldset data-revealer="superuser" data-reveal-on="false">
        <legend>
            <?=$oGroup->getComponent()->name?>
            <div>
                <small class="fw-normal">
                    <?=$oGroup->getComponent()->description?>
                </small>
            </div>
        </legend>
        <?php

        $sTitle = '';

        foreach ($oGroup->sort()->getPermissions() as $oPermission) {

            if ($oPermission instanceof \Nails\Admin\Admin\Permission\SuperUser) {
                continue;
            }

            //  @todo (Pablo 2022-07-28) - set as checked if user has the permission

            if ($sTitle !== $oPermission->group()) {
                $sTitle = $oPermission->group();
                echo '<h2>' . $sTitle . '</h2>';
            }

            ?>
            <div class="field checkbox">
                <label>
                    <?=form_checkbox('acl[]', get_class($oPermission), groupHasPermission($oPermission, $oItem, true))?>
                    <span style="margin-left: 15px"><?=$oPermission->label()?></span>
                </label>
            </div>
            <?php
        }

        ?>
    </fieldset>
    <?php
}

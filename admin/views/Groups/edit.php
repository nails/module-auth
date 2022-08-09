<?php

use Nails\Factory;

/** @var \Nails\Common\Service\View $oView */
$oView = Factory::service('View');

echo form_open();
echo \Nails\Admin\Helper::tabs([
    [
        'label'   => 'Basic Details',
        'content' => function () use ($oView) {
            return \Nails\Admin\Helper::loadInlineView('edit/basic', [], true);
        },
    ],
    [
        'label'   => 'Password',
        'content' => function () use ($oView) {
            return \Nails\Admin\Helper::loadInlineView('edit/password', [], true);
        },
    ],
    [
        'label'   => '2FA',
        'content' => function () use ($oView) {
            return \Nails\Admin\Helper::loadInlineView('edit/2fa', [], true);
        },
    ],
    [
        'label'   => 'Permissions',
        'content' => function () use ($oView) {
            return \Nails\Admin\Helper::loadInlineView('edit/permissions', [], true);
        },
    ],
]);
echo \Nails\Admin\Helper::floatingControls();
echo form_close();

return;

/** @var \Nails\Common\Service\Input $oInput */
$oInput = Factory::service('Input');

?>
<div class="group-accounts groups edit">
    <div class="alert alert-warning">
        <strong>Please note:</strong> while the system will do its best to validate the content you set
        sometimes a valid combination can render an entire group useless (including your own). Please be
        extra careful and only change things when you know what you're doing. Remember that you won't see
        the effect of changing the permissions of a group other than your own, check that your changes
        have worked before considering the job done!
    </div>
    <hr />
    <?=form_open()?>
    <input type="hidden" name="activeTab" value="<?=set_value('activeTab')?>" id="activeTab" />
    <ul class="tabs">
        <li class="tab <?=$oInput->post('activeTab') == 'tab-basic' || !$oInput->post('activeTab') ? 'active' : ''?>">
            <a href="#" data-tab="tab-basic">Basic Details</a>
        </li>
        <li class="tab <?=$oInput->post('activeTab') == 'tab-password' ? 'active' : ''?>">
            <a href="#" data-tab="tab-password">Password</a>
        </li>
        a
        <li class="tab <?=$oInput->post('activeTab') == 'tab-2fa' ? 'active' : ''?>">
            <a href="#" data-tab="tab-2fa">2FA</a>
        </li>
        <li class="tab <?=$oInput->post('activeTab') == 'tab-permissions' ? 'active' : ''?>">
            <a href="#" data-tab="tab-permissions">Permissions</a>
        </li>
    </ul>
    <section class="tabs">
        <!--    BASICS  -->
        <div class="tab-page tab-basic <?=$oInput->post('activeTab') == 'tab-basic' || !$oInput->post('activeTab') ? 'active' : ''?>">
            <div class="fieldset">
                <?php

                echo form_field([
                    'key'         => 'label',
                    'label'       => 'Label',
                    'default'     => isset($item) ? $item->label : '',
                    'required'    => true,
                    'placeholder' => 'Type the group\'s label name here.',
                ]);

                echo form_field([
                    'key'         => 'slug',
                    'label'       => 'Slug',
                    'default'     => isset($item) ? $item->slug : '',
                    'required'    => true,
                    'placeholder' => 'Type the group\'s slug here.',
                ]);

                echo form_field([
                    'key'         => 'description',
                    'label'       => 'Description',
                    'default'     => isset($item) ? $item->description : '',
                    'required'    => true,
                    'placeholder' => 'Type the group\'s description here.',
                ]);

                echo form_field([
                    'key'         => 'default_homepage',
                    'label'       => 'Default Homepage',
                    'default'     => isset($item) ? $item->default_homepage : '',
                    'placeholder' => 'Type the group\'s homepage here.',
                    'info'        => 'This is where users are sent after login, unless a specific redirect is already in place. If not specified the user will be sent to the homepage.',
                ]);

                echo form_field([
                    'key'         => 'registration_redirect',
                    'label'       => 'Registration Redirect',
                    'default'     => isset($item) ? $item->registration_redirect : '',
                    'placeholder' => 'Redirect new registrants of this group here.',
                    'info'        => 'If not defined new registrants will be redirected to the group\'s homepage.',
                ]);

                ?>
            </div>
        </div>
        <!--    PASSWORD RULES  -->
        <div class="tab-page tab-password <?=$oInput->post('activeTab') == 'tab-password' ? 'active' : ''?>">
            <div class="fieldset">
                <?php

                echo form_field([
                    'key'         => 'pw[min]',
                    'label'       => 'Min. Length',
                    'default'     => isset($item->password_rules->min) ? $item->password_rules->min : '',
                    'required'    => false,
                    'placeholder' => 'The minimum number of characters a password must contain.',
                    'info'        => 'If this is undefined, or set to 0, then there is no minimum length',
                ]);

                echo form_field([
                    'key'         => 'pw[max]',
                    'label'       => 'Max. Length',
                    'default'     => isset($item->password_rules->max) ? $item->password_rules->max : '',
                    'required'    => false,
                    'placeholder' => 'The maximum number of characters a password must contain.',
                    'info'        => 'If this is undefined, or set to 0, then there is no maximum length',
                ]);

                echo form_field_number([
                    'key'         => 'pw[expires_after]',
                    'label'       => 'Expires After',
                    'default'     => isset($item->password_rules->expiresAfter) ? $item->password_rules->expiresAfter : '',
                    'required'    => false,
                    'placeholder' => 'The expiration policy for passwords, expressed in days',
                    'info'        => 'If this is undefined, or set to 0, then there is no expiration policy',
                ]);

                echo form_field_checkbox([
                    'key'      => 'pw[requirements][]',
                    'label'    => 'Requirements',
                    'default'  => isset($item->password_rules->requirements) ? $item->password_rules->requirements : ['symbol' => true],
                    'required' => false,
                    'options'  => [
                        [
                            'label'    => 'Must contain a symbol',
                            'value'    => 'symbol',
                            'selected' => !empty($item->password_rules->requirements->symbol),
                        ],
                        [
                            'label'    => 'Must contain a number',
                            'value'    => 'number',
                            'selected' => !empty($item->password_rules->requirements->number),
                        ],
                        [
                            'label'    => 'Must contain a lowercase letter',
                            'value'    => 'lower_alpha',
                            'selected' => !empty($item->password_rules->requirements->lower_alpha),
                        ],
                        [
                            'label'    => 'Must contain an uppercase letter',
                            'value'    => 'upper_alpha',
                            'selected' => !empty($item->password_rules->requirements->upper_alpha),
                        ],
                    ],
                ]);

                echo form_field([
                    'key'         => 'pw[banned]',
                    'label'       => 'Banned Words',
                    'default'     => isset($item->password_rules->banned) ? implode(',', $item->password_rules->banned) : '',
                    'required'    => false,
                    'placeholder' => 'A comma separated list of words which cannot be used as a password',
                ]);

                echo form_field_boolean([
                    'key'     => 'pw[block_common]',
                    'label'   => 'Disallow common passwords',
                    'default' => $item->password_rules->block_common ?? false,
                    'info'    => 'When enabled, common passwords will be rejected.',
                ]);

                ?>
            </div>
        </div>
        <!-- 2FA -->
        <div class="tab-page tab-2fa <?=$oInput->post('activeTab') == 'tab-2fa' ? 'active' : ''?>">
            <p class="alert alert-warning">
                Currently, 2FA settings are done at a code-level and apply to all users.
            </p>
        </div>
        <!--    PERMISSIONS -->
        <div class="tab-page tab-permissions <?=$oInput->post('activeTab') == 'tab-permissions' ? 'active' : ''?>">
            <p>
                For non-superuser groups you may also grant a access to the administration area by selecting which
                admin modules they have permission to access.
                <strong>It goes without saying that you should be careful with these options.</strong>
            </p>
            <p class="alert alert-warning">
                <strong>Please note:</strong> Superusers have full, unrestricted access to admin, regardless of what
                extra permissions are set.
            </p>
            <div class="fieldset">
                <?php

                //  Enable Super User status for this user group
                $aField = [
                    'key'      => 'acl[admin][superuser]',
                    'label'    => 'Is Super User',
                    'required' => false,
                    'default'  => false,
                    'id'       => 'toggleSuperuser',
                ];
                if (!empty($item->acl)) {
                    $sCheckKey         = 'admin:superuser';
                    $aField['default'] = in_array($sCheckKey, $item->acl);
                }

                echo form_field_boolean($aField);

                // --------------------------------------------------------------------------

                $sDisplay = $aField['default'] ? 'none' : 'block';

                ?>
                <div id="adminPermissions" class="permission-groups" style="display:<?=$sDisplay?>;">
                    <div class="search noOptions" id="permissionSearch">
                        <div class="search-text">
                            <?=form_input('', '', 'autocomplete="off" placeholder="Type to filter permissions"')?>
                        </div>
                    </div>
                    <?php

                    $iNumPermissions = count($aPermissions);

                    for ($i = 0; $i < $iNumPermissions; $i++) {
                        $sPermissionSlug = $aPermissions[$i]->slug;
                        ?>
                        <fieldset class="permission-group">
                            <legend><?=$aPermissions[$i]->label?></legend>
                            <table>
                                <thead>
                                    <tr>
                                        <th class="enabled text-center" width="50">
                                            <input type="checkbox" class="toggleAll">
                                        </th>
                                        <th class="permission">
                                            Permission
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php

                                    foreach ($aPermissions[$i]->permissions as $sPermission => $sLabel) {

                                        $sKey      = 'acl[admin][' . $sPermissionSlug . '][' . $sPermission . ']';
                                        $sCheckKey = 'admin:' . $sPermissionSlug . ':' . $sPermission;

                                        if (!empty($_POST)) {
                                            $bIsChecked = !empty($_POST['acl']['admin'][$sPermissionSlug][$sPermission]);
                                        } elseif (!empty($item->acl)) {
                                            $bIsChecked = in_array($sCheckKey, $item->acl);
                                        } else {
                                            $bIsChecked = false;
                                        }

                                        $sContextColor = $bIsChecked ? 'success' : 'error';

                                        ?>
                                        <tr>
                                            <td class="enabled text-center <?=$sContextColor?>">
                                                <label>
                                                    <?=form_checkbox($sKey, true, $bIsChecked)?>
                                                </label>
                                            </td>
                                            <td class="permission"><?=$sLabel?></td>
                                        </tr>
                                        <?php

                                    }

                                    ?>
                                <tbody>
                            </table>
                        </fieldset>
                        <?php
                    }

                    ?>
                </div>
            </div>
        </div>
    </section>
    <?php

    echo \Nails\Admin\Helper::floatingControls($CONFIG['FLOATING_CONFIG']);
    echo form_close();

    ?>
</div>

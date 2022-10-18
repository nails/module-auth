<?php

/** @var \Nails\Common\Service\Input $oInput */
$oInput = \Nails\Factory::service('Input');

?>
<div class="group-settings site">
    <?php

    echo form_open();
    echo \Nails\Admin\Helper::tabs(array_filter([
        !userHasPermission(\Nails\Auth\Admin\Permission\Settings\Registration::class) ? null : [
            'label'   => 'Registration',
            'content' => function () {
                echo form_field_boolean([
                    'key'     => 'user_registration_enabled',
                    'label'   => 'Enabled',
                    'default' => (bool) appSetting('user_registration_enabled', 'auth'),
                    'info'    => 'If not using a custom registration flow, you may enable or disable public registrations. Admin will always be able to create users.',
                ]);
                echo form_field_boolean([
                    'key'     => 'user_registration_captcha_enabled',
                    'label'   => 'Captcha',
                    'default' => (bool) appSetting('user_registration_captcha_enabled', 'auth'),
                    'info'    => 'May not apply to custom registration flow.',
                ]);
            },
        ],

        !userHasPermission(\Nails\Auth\Admin\Permission\Settings\Login::class) ? null : [
            'label'   => 'Login',
            'content' => function () {
                echo form_field_boolean([
                    'key'     => 'user_login_captcha_enabled',
                    'label'   => 'Captcha',
                    'default' => (bool) appSetting('user_login_captcha_enabled', 'auth'),
                ]);
            },
        ],

        !userHasPermission(\Nails\Auth\Admin\Permission\Settings\Password::class) ? null : [
            'label'   => 'Password Reset',
            'content' => function () {
                echo form_field_boolean([
                    'key'     => 'user_password_reset_captcha_enabled',
                    'label'   => 'Captcha',
                    'default' => (bool) appSetting('user_password_reset_captcha_enabled', 'auth'),
                ]);
            },
        ],

        !userHasPermission(\Nails\Auth\Admin\Permission\Groups\Edit::class) ? null : [
            'label'   => 'Security',
            'content' => function () {
                ?>
                <p>Security settings are configured on a per group basis.</p>
                <p><?=anchor(\Nails\Auth\Admin\Controller\Groups::url(), 'Edit Groups', 'class="btn btn-primary btn-xs"')?></p>
                <?php
            },
        ],

        !userHasPermission(\Nails\Auth\Admin\Permission\Settings\Social::class) && !empty($aProviders) ? null : [
            'label'   => 'Social Integration',
            'content' => function () use ($aProviders) {
                ?>
                <p>
                    With the exception of OpenID providers, each social network requires that you create an external
                    application which links your website to theirs. These external applications ensure that users are
                    logging into the proper website and allows the network to send the user back to the correct website
                    after successfully authenticating their account.
                </p>
                <p>
                    You can refer to <?=anchor('https://hybridauth.github.io/', 'Hybridauth\'s Documentation', 'target="_blank"')?>
                    for instructions on how to create these applications.
                </p>
                <div class="fieldset" id="site-settings-socialsignin">
                    <?php

                    foreach ($aProviders as $aProvider) {

                        $aField            = [];
                        $aField['key']     = 'auth_social_signon_' . $aProvider['slug'] . '_enabled';
                        $aField['label']   = $aProvider['label'];
                        $aField['default'] = (bool) appSetting($aField['key'], 'auth');

                        ?>
                        <div class="field boolean configure-provider">
                            <span class="label">
                                <?=$aField['label']?>
                            </span>
                            <span class="input">
                                <?php

                                $sSelected = set_value($aField['key'], (bool) $aField['default']);

                                echo '<div class="form-bool toggle toggle-modern"></div>';
                                echo form_checkbox($aField['key'], true, $sSelected);
                                echo $aProvider['fields'] ? '<a href="#configure-provider-' . $aProvider['slug'] . '" class="btn btn-xs btn-primary pull-right fancybox">Configure</a>' : '';
                                echo form_error($aField['key'], '<span class="error">', '</span>');

                                ?>
                            </span>
                            <div id="configure-provider-<?=$aProvider['slug']?>" class="configure-provider-fancybox" style="min-width:500px;display:none;">
                                <p style="text-align:center;">
                                    Please provide the following information. Fields marked with a * are required.
                                </p>
                                <?php

                                foreach ($aProvider['fields'] as $sKey => $sLabel) {

                                    /**
                                     * Secondary conditional detects an actual array fo fields rather than
                                     * just the label/required array. Design could probably be improved...
                                     **/

                                    if (is_array($sLabel) && !isset($sLabel['label'])) {

                                        foreach ($sLabel as $sKey1 => $sLabel1) {

                                            $aField             = [];
                                            $aField['key']      = 'auth_social_signon_' . $aProvider['slug'] . '_' . $sKey . '_' . $sKey1;
                                            $aField['label']    = $sLabel1['label'];
                                            $aField['required'] = $sLabel1['required'];
                                            $aField['default']  = appSetting($aField['key'], 'auth');

                                            echo form_field($aField);
                                        }

                                    } else {

                                        $aField             = [];
                                        $aField['key']      = 'auth_social_signon_' . $aProvider['slug'] . '_' . $sKey;
                                        $aField['label']    = $sLabel['label'];
                                        $aField['required'] = $sLabel['required'];
                                        $aField['default']  = appSetting($aField['key'], 'auth');

                                        echo form_field($aField);
                                    }
                                }

                                ?>
                            </div>
                        </div>
                        <?php
                    }

                    ?>
                </div>
                <?php
            },
        ],
    ]));
    echo \Nails\Admin\Helper::floatingControls();
    echo form_close()

    ?>
</div>

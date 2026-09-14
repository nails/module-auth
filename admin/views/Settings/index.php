<?php

use Nails\Admin\Helper;

?>
<div class="group-settings site">
    <?php

    echo form_open();
    echo Helper::tabs(array_filter([
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

                /** @var \Nails\Auth\Service\Passkey $oPasskeyService */
                $oPasskeyService = \Nails\Factory::service('Passkey', \Nails\Auth\Constants::MODULE_SLUG);

                echo form_field_boolean([
                    'key'     => 'user_login_captcha_enabled',
                    'label'   => 'Captcha',
                    'default' => (bool) appSetting('user_login_captcha_enabled', 'auth'),
                ]);

                /**
                 * Read only: passkeys are switched from app config, and the RP ID
                 * is derived from BASE_URL. Changing the RP ID invalidates every
                 * passkey already registered.
                 */

                ?>
                <div class="alert alert-info">
                    <p>
                        <strong>Passkeys:</strong>
                        <?= $oPasskeyService->isEnabled() ? 'Enabled' : 'Disabled' ?>
                    </p>
                    <p>
                        <strong>Relying Party ID:</strong>
                        <code><?=htmlspecialchars($oPasskeyService->getRpId())?></code>
                    </p>
                    <p>
                        <strong>Permitted origins:</strong>
                        <code><?=htmlspecialchars(implode(', ', $oPasskeyService->getAllowedOrigins()))?></code>
                    </p>
                    <p class="mb-0">
                        <small>
                            Enable with <code><?=\Nails\Auth\Service\Passkey::CONFIG_ENABLED?></code>.
                            The Relying Party ID is derived from <code>BASE_URL</code>;
                            override with
                            <code><?=\Nails\Auth\Service\Passkey::CONFIG_RP_ID?></code> and
                            <code><?=\Nails\Auth\Service\Passkey::CONFIG_ALLOWED_ORIGINS?></code>.
                            Changing the Relying Party ID invalidates existing passkeys.
                        </small>
                    </p>
                </div>
                <?php
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
    ]));

    echo Helper::floatingControls();
    echo form_close()

    ?>
</div>

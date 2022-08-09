<?php

echo form_field([
    'key'         => 'password',
    'label'       => 'Reset Password',
    'placeholder' => 'Reset the user\'s password by specifying a new one here',
    'info'        => implode('', [
        '<div class="alert alert-warning mb-2">',
        'The user <strong>will</strong> be informed that their password has been changed, but <strong>not</strong> what their new password is.',
        '</div>',
        '<div class="alert alert-info">',
        $sPasswordRules,
        '</div>',
    ]),
]);

echo form_field_boolean([
    'key'      => 'temp_pw',
    'label'    => 'Temporary password',
    'info'     => 'Require password update on next log in',
    'text_on'  => 'Yes',
    'text_off' => 'No',
    'default'  => $oUser->temp_pw,
]);

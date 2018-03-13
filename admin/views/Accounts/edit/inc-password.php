<fieldset id="edit-user-password">
    <legend>
        <?=lang('accounts_edit_password_legend')?>
    </legend>
    <div class="box-container">
        <?php

        //  Reset Password
        $aField                = [];
        $aField['key']         = 'password';
        $aField['label']       = lang('accounts_edit_password_field_password_label');
        $aField['default']     = '';
        $aField['required']    = false;
        $aField['placeholder'] = lang('accounts_edit_password_field_password_placeholder');

        //  PAssword rules
        $aField['info'] = '<div class="alert alert-info" style="margin:0;">';
        $aField['info'] .= lang('accounts_edit_password_field_password_tip');
        $aField['info'] .= '<br />' . $passwordRules;
        $aField['info'] .= '</div>';

        echo form_field($aField);

        // --------------------------------------------------------------------------

        //  Require password update on log in
        $aField             = [];
        $aField['key']      = 'temp_pw';
        $aField['label']    = lang('accounts_edit_password_field_temp_pw_label');
        $aField['default']  = false;
        $aField['required'] = false;

        $options = [];

        $options[] = [
            'value'    => 'TRUE',
            'label'    => lang('accounts_edit_password_field_temp_pw_yes'),
            'selected' => $user_edit->temp_pw ? true : false,
        ];

        $options[] = [
            'value'    => 'FALSE',
            'label'    => lang('accounts_edit_password_field_temp_pw_no'),
            'selected' => !$user_edit->temp_pw ? true : false,
        ];

        echo form_field_radio($aField, $options);

        ?>
    </div>
</fieldset>
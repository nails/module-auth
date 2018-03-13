<fieldset id="edit-user-basic">
    <legend>
        <?=lang('accounts_edit_basic_legend')?>
    </legend>
    <div class="box-container">
        <?php

        //  First Name
        $_field                = [];
        $_field['key']         = 'first_name';
        $_field['label']       = lang('form_label_first_name');
        $_field['default']     = $user_edit->first_name;
        $_field['required']    = true;
        $_field['placeholder'] = lang('accounts_edit_basic_field_first_placeholder');

        echo form_field($_field);

        // --------------------------------------------------------------------------

        //  Last name
        $_field                = [];
        $_field['key']         = 'last_name';
        $_field['label']       = lang('form_label_last_name');
        $_field['default']     = $user_edit->last_name;
        $_field['required']    = true;
        $_field['placeholder'] = lang('accounts_edit_basic_field_last_placeholder');

        echo form_field($_field);

        // --------------------------------------------------------------------------

        //  Username
        $_field                = [];
        $_field['key']         = 'username';
        $_field['label']       = lang('accounts_edit_basic_field_username_label');
        $_field['default']     = $user_edit->username;
        $_field['required']    = false;
        $_field['placeholder'] = lang('accounts_edit_basic_field_username_placeholder');
        $_field['info']        = 'Username can only contain alpha numeric characters, underscores, periods and dashes (no spaces).';

        echo form_field($_field);

        // --------------------------------------------------------------------------

        //  Gender
        $_field             = [];
        $_field['key']      = 'gender';
        $_field['label']    = lang('accounts_edit_basic_field_gender_label');
        $_field['default']  = $user_edit->gender;
        $_field['class']    = 'select2';
        $_field['required'] = false;

        $_options = [
            'UNDISCLOSED' => 'Undisclosed',
            'MALE'        => 'Male',
            'FEMALE'      => 'Female',
            'TRANSGENDER' => 'Transgender',
            'OTHER'       => 'Other',
        ];

        echo form_field_dropdown($_field, $_options);

        // --------------------------------------------------------------------------

        //  DOB
        $_field             = [];
        $_field['key']      = 'dob';
        $_field['label']    = lang('accounts_edit_basic_field_dob_label');
        $_field['default']  = $user_edit->dob;
        $_field['class']    = 'select2';
        $_field['required'] = false;

        echo form_field_date($_field, $_options);

        // --------------------------------------------------------------------------

        //  Timezone
        $_field             = [];
        $_field['key']      = 'timezone';
        $_field['label']    = lang('accounts_edit_basic_field_timezone_label');
        $_field['default']  = $user_edit->timezone ? $user_edit->timezone : $default_timezone;
        $_field['required'] = false;
        $_field['class']    = 'select2';

        echo form_field_dropdown($_field, $timezones, lang('accounts_edit_basic_field_timezone_tip'));

        // --------------------------------------------------------------------------

        //  Date format
        $_field             = [];
        $_field['key']      = 'datetime_format_date';
        $_field['label']    = lang('accounts_edit_basic_field_date_format_label');
        $_field['default']  = $user_edit->datetime_format_date ? $user_edit->datetime_format_date : APP_DEFAULT_DATETIME_FORMAT_DATE_SLUG;
        $_field['required'] = false;
        $_field['class']    = 'select2';

        if (count($date_formats) > 1) {

            $_options = [];

            foreach ($date_formats as $format) {

                $_options[$format->slug] = $format->label . ' (' . $format->example . ')';
            }

            echo form_field_dropdown($_field, $_options, lang('accounts_edit_basic_field_date_format_tip'));

        } else {

            echo form_hidden($_field['key'], $_field['default']);
        }

        // --------------------------------------------------------------------------

        //  Time Format
        $_field             = [];
        $_field['key']      = 'datetime_format_time';
        $_field['label']    = lang('accounts_edit_basic_field_time_format_label');
        $_field['default']  = $user_edit->datetime_format_time ? $user_edit->datetime_format_time : APP_DEFAULT_DATETIME_FORMAT_TIME_SLUG;
        $_field['required'] = false;
        $_field['class']    = 'select2';

        if (count($time_formats) > 1) {

            $_options = [];

            foreach ($time_formats as $format) {

                $_options[$format->slug] = $format->label . ' (' . $format->example . ')';
            }

            echo form_field_dropdown($_field, $_options, lang('accounts_edit_basic_field_time_format_tip'));

        } else {

            echo form_hidden($_field['key'], $_field['default']);
        }

        // --------------------------------------------------------------------------

        //  Preferred Language
        $_field             = [];
        $_field['key']      = 'language';
        $_field['label']    = lang('accounts_edit_basic_field_language_label');
        $_field['default']  = $user_edit->language ? $user_edit->language : APP_DEFAULT_LANG_CODE;
        $_field['required'] = false;
        $_field['class']    = 'select2';

        if (count($languages) > 1) {

            echo form_field_dropdown($_field, $languages, lang('accounts_edit_basic_field_language_tip'));

        } else {

            echo form_hidden($_field['key'], $_field['default']);
        }

        // --------------------------------------------------------------------------

        //  Registered IP
        $_field             = [];
        $_field['key']      = 'ip_address';
        $_field['label']    = lang('accounts_edit_basic_field_register_ip_label');
        $_field['default']  = $user_edit->ip_address;
        $_field['required'] = false;
        $_field['readonly'] = true;

        echo form_field($_field);

        // --------------------------------------------------------------------------

        //  Last IP
        $_field             = [];
        $_field['key']      = 'last_ip';
        $_field['label']    = lang('accounts_edit_basic_field_last_ip_label');
        $_field['default']  = $user_edit->last_ip;
        $_field['required'] = false;
        $_field['readonly'] = true;

        echo form_field($_field);

        // --------------------------------------------------------------------------

        //  Created On
        $_field             = [];
        $_field['key']      = 'created';
        $_field['label']    = lang('accounts_edit_basic_field_created_label');
        $_field['default']  = toUserDatetime($user_edit->created);
        $_field['required'] = false;
        $_field['readonly'] = true;

        echo form_field($_field);

        // --------------------------------------------------------------------------

        //  Created On
        $_field             = [];
        $_field['key']      = 'last_update';
        $_field['label']    = lang('accounts_edit_basic_field_modified_label');
        $_field['default']  = toUserDatetime($user_edit->last_update);
        $_field['required'] = false;
        $_field['readonly'] = true;

        echo form_field($_field);

        // --------------------------------------------------------------------------

        //  Log in count
        $_field             = [];
        $_field['key']      = 'login_count';
        $_field['label']    = lang('accounts_edit_basic_field_logincount_label');
        $_field['default']  = $user_edit->login_count ? $user_edit->login_count : lang('accounts_edit_basic_field_not_logged_in');
        $_field['required'] = false;
        $_field['readonly'] = true;

        echo form_field($_field);

        // --------------------------------------------------------------------------

        //  Last Log in
        $_field             = [];
        $_field['key']      = 'last_login';
        $_field['label']    = lang('accounts_edit_basic_field_last_login_label');
        $_field['default']  = $user_edit->last_login ? toUserDatetime($user_edit->last_login) : lang('accounts_edit_basic_field_not_logged_in');
        $_field['required'] = false;
        $_field['readonly'] = true;

        echo form_field($_field);

        // --------------------------------------------------------------------------

        //  Referral Code
        $_field             = [];
        $_field['key']      = 'referral';
        $_field['label']    = lang('accounts_edit_basic_field_referral_label');
        $_field['default']  = $user_edit->referral;
        $_field['required'] = false;
        $_field['readonly'] = true;

        echo form_field($_field);

        // --------------------------------------------------------------------------

        //  Referred by
        $_field                = [];
        $_field['key']         = 'referred_by';
        $_field['label']       = lang('accounts_edit_basic_field_referred_by_label');
        $_field['default']     = $user_edit->referred_by ? 'User ID: ' . $user_edit->referred_by : 'Not referred';
        $_field['required']    = false;
        $_field['placeholder'] = lang('accounts_edit_basic_field_referred_by_placeholder');
        $_field['readonly']    = true;

        echo form_field($_field);

        ?>
    </div>
</fieldset>
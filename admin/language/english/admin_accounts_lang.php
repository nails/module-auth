<?php

/**
 * English language strings for Auth/Admin/Accounts Controller
 *
 * @package     Nails
 * @subpackage  module-admin
 * @category    Language
 * @author      Nails Dev Team
 * @link
 */

//  Create new user
$lang['accounts_create_title']                      = 'Create New User';
$lang['accounts_create_intro']                      = 'Create a new user by completing the following basic information and clicking \'Create User\' below. You will be given the opportunity to edit the user once the basic account has been created.';
$lang['accounts_create_basic_legend']               = 'Basic Information';
$lang['accounts_create_field_group_label']          = 'User\'s Group';
$lang['accounts_create_field_group_tip']            = 'Specify to which group this user belongs';
$lang['accounts_create_field_password_tip']         = 'Leave the password field blank to have the system auto-generate a password.';
$lang['accounts_create_field_password_placeholder'] = 'The user\'s password, leave blank to auto-generate';
$lang['accounts_create_field_send_welcome_label']   = 'Send Welcome Email';
$lang['accounts_create_field_send_welcome_yes']     = '<strong>Yes</strong>, send user welcome email containing their password.';
$lang['accounts_create_field_send_welcome_no']      = '<strong>No</strong>, do not send welcome email.';
$lang['accounts_create_field_temp_pw_label']        = 'Update on log in';
$lang['accounts_create_field_temp_pw_yes']          = '<strong>Yes</strong>, require password update on first log in.';
$lang['accounts_create_field_temp_pw_no']           = '<Strong>No</strong>, do not require password update on first log in.';
$lang['accounts_create_field_first_placeholder']    = 'The user\'s first name';
$lang['accounts_create_field_last_placeholder']     = 'The user\'s surname';
$lang['accounts_create_field_email_placeholder']    = 'The user\'s email address';
$lang['accounts_create_field_username_placeholder'] = 'The user\'s username';
$lang['accounts_create_submit']                     = 'Create User';

// --------------------------------------------------------------------------

//  Edit user
$lang['accounts_edit_title'] = 'Edit User (%s)';

//  Errors
$lang['accounts_edit_error_unknown_id']  = 'Unknown User ID';
$lang['accounts_edit_error_profile_img'] = '<strong>Update Failed:</strong> There was a problem uploading the Profile Image.';
$lang['accounts_edit_error_upload']      = '<strong>Update failed:</strong> The file "%s" failed to upload.';
$lang['accounts_edit_error_noteditable'] = 'You do not have permission to perform manipulations on that user.';


$lang['accounts_edit_ok']             = 'Updated user %s';
$lang['accounts_edit_fail']           = 'Failed to update user: %s';
$lang['accounts_edit_editing_self_m'] = '<strong>Hey there handsome!</strong> You are currently editing your own account.';
$lang['accounts_edit_editing_self_f'] = '<strong>Hey there beautiful!</strong> You are currently editing your own account.';
$lang['accounts_edit_editing_self_u'] = '<strong>Hello there!</strong> You are currently editing your own account.';

$lang['accounts_edit_actions_legend'] = 'Actions';

$lang['accounts_edit_password_legend']                     = 'Password';
$lang['accounts_edit_password_field_password_label']       = 'Reset Password';
$lang['accounts_edit_password_field_password_placeholder'] = 'Reset the user\'s password by specifying a new one here';
$lang['accounts_edit_password_field_password_tip']         = 'The user WILL be informed that their password has been changed, but NOT what their new password is.';
$lang['accounts_edit_password_field_temp_pw_label']        = 'Update on next log in';
$lang['accounts_edit_password_field_temp_pw_yes']          = '<strong>Yes</strong>, require password update on next log in.';
$lang['accounts_edit_password_field_temp_pw_no']           = '<strong>No</strong>, do not require password update on next log in.';

$lang['accounts_edit_mfa_question_legend']            = 'Multi Factor Authentication: Questions';
$lang['accounts_edit_mfa_question_field_reset_label'] = 'Set new qustions on next log in';
$lang['accounts_edit_mfa_question_field_reset_yes']   = '<strong>Yes</strong>, require user to set new security questions on next log in.';
$lang['accounts_edit_mfa_question_field_reset_no']    = '<strong>No</strong>, do not require user to set new security questions on next log in.';

$lang['accounts_edit_mfa_device_legend']            = 'Multi Factor Authentication: Device';
$lang['accounts_edit_mfa_device_field_reset_label'] = 'Setup a new device on next log in';
$lang['accounts_edit_mfa_device_field_reset_yes']   = '<strong>Yes</strong>, require user to setup a new security device on next log in.';
$lang['accounts_edit_mfa_device_field_reset_no']    = '<strong>No</strong>, do not require user to setup a new security device on next log in.';

$lang['accounts_edit_basic_legend']                        = 'Basic Information';
$lang['accounts_edit_basic_field_first_placeholder']       = 'The user\'s first name';
$lang['accounts_edit_basic_field_last_placeholder']        = 'The user\'s surname';
$lang['accounts_edit_basic_field_email_placeholder']       = 'The user\'s email address';
$lang['accounts_edit_basic_field_verified_label']          = 'Email verified';
$lang['accounts_edit_basic_field_username_label']          = 'Username';
$lang['accounts_edit_basic_field_username_placeholder']    = 'The user\'s username';
$lang['accounts_edit_basic_field_gender_label']            = 'Gender';
$lang['accounts_edit_basic_field_dob_label']               = 'Date of Birth';
$lang['accounts_edit_basic_field_timezone_label']          = 'Timezone';
$lang['accounts_edit_basic_field_timezone_tip']            = 'This gives the user the ability to specify their local timezone so that dates &amp; times are correct.';
$lang['accounts_edit_basic_field_timezone_placeholder']    = 'The user\'s timezone, coming soon!';
$lang['accounts_edit_basic_field_date_format_label']       = 'Date Format';
$lang['accounts_edit_basic_field_date_format_tip']         = 'Allows the user to choose how dates are rendered.';
$lang['accounts_edit_basic_field_time_format_label']       = 'Time Format';
$lang['accounts_edit_basic_field_time_format_tip']         = 'Allows the user to choose how times are rendered.';
$lang['accounts_edit_basic_field_register_ip_label']       = 'Registration IP';
$lang['accounts_edit_basic_field_last_ip_label']           = 'Last IP';
$lang['accounts_edit_basic_field_created_label']           = 'Created';
$lang['accounts_edit_basic_field_modified_label']          = 'Modified';
$lang['accounts_edit_basic_field_logincount_label']        = 'Log in counter';
$lang['accounts_edit_basic_field_last_login_label']        = 'Last Login';
$lang['accounts_edit_basic_field_not_logged_in']           = 'Never Logged In';
$lang['accounts_edit_basic_field_referral_label']          = 'Referral Code';
$lang['accounts_edit_basic_field_referred_by_label']       = 'Referred By';
$lang['accounts_edit_basic_field_referred_by_placeholder'] = 'The user who referred this user, if any';
$lang['accounts_edit_basic_field_language_label']          = 'Language';

$lang['accounts_edit_emails_legend']           = 'Email Addresses';
$lang['accounts_edit_emails_th_email']         = 'Email Address';
$lang['accounts_edit_emails_th_primary']       = 'Primary';
$lang['accounts_edit_emails_th_verified']      = 'Verified';
$lang['accounts_edit_emails_th_date_added']    = 'Date Added';
$lang['accounts_edit_emails_th_date_verified'] = 'Date Verified';
$lang['accounts_edit_emails_td_not_verified']  = 'Not Verified';
$lang['accounts_edit_emails_td_actions']       = 'Actions';

$lang['accounts_edit_meta_legend']     = 'Meta Information';
$lang['accounts_edit_meta_noeditable'] = 'There is no editable meta information for this user.';

$lang['accounts_edit_img_legend'] = 'Profile Image';

$lang['accounts_edit_social_legend']    = 'Social Media';
$lang['accounts_edit_social_connected'] = 'Connected to %s';
$lang['accounts_edit_social_none']      = 'This user is not currently connected to any social media network';

$lang['accounts_edit_upload_legend']     = 'User Uploads';
$lang['accounts_edit_upload_nofile']     = 'No files found';
$lang['accounts_edit_upload_type_image'] = 'Images';
$lang['accounts_edit_upload_type_file']  = 'Files';

// --------------------------------------------------------------------------

//  Suspending/Unsuspending
$lang['accounts_suspend_success']   = '%s was suspended.';
$lang['accounts_suspend_error']     = 'There was a problem suspending %s.';
$lang['accounts_unsuspend_success'] = '%s was unsuspended.';
$lang['accounts_unsuspend_error']   = 'There was a problem unsuspending %s.';

// --------------------------------------------------------------------------

//  Deleting
$lang['accounts_delete_error_selfie'] = 'You can\'t delete yourself.';
$lang['accounts_delete_success']      = '<strong>See ya!</strong> User %s was deleted successfully.';
$lang['accounts_delete_error']        = 'There was a problem deleting %s.';

// --------------------------------------------------------------------------

//  Deleting profile image
$lang['accounts_delete_img_success']     = 'Profile image was deleted.';
$lang['accounts_delete_img_error']       = 'I was unable delete this user\'s profile image. The server said: "%s"';
$lang['accounts_delete_img_error_noid']  = 'I was unable to find a user by that ID.';
$lang['accounts_delete_img_error_noimg'] = '<strong>Hey!</strong> This user doesn\'t have a profile image to delete.';

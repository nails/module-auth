<?php

/**
 * Name:        Authentication - LANGUAGE
 *
 * Description: English language file for Auth module
 *
 **/

//  Page Titles
$lang['auth_title_login']                                = 'Please log in';
$lang['auth_title_register']                             = 'Register';
$lang['auth_title_forgotten_password']                   = 'Forgotten your password?';
$lang['auth_title_forgotten_password_security_question'] = 'Please answer this security question';
$lang['auth_title_reset']                                = 'Reset your password';

// --------------------------------------------------------------------------

//  Generic errors
$lang['auth_no_db']                       = 'No database is configured.';
$lang['auth_no_access_already_logged_in'] = 'Sorry, you can\'t access that page while logged in (currently logged in as %s).';
$lang['auth_no_access']                   = 'Sorry, you do not have permission to access that content.';

// --------------------------------------------------------------------------

//  Login lang strings
$lang['auth_login_message']              = 'Sign in to your %s account using your email address and password. Not got an account? <a href="%s">Click here to register</a>.';
$lang['auth_login_message_no_register']  = 'Sign in to your %s account using your email address and password.';
$lang['auth_login_email_placeholder']    = 'Your registered email ';
$lang['auth_login_username_placeholder'] = 'Your registered username';
$lang['auth_login_both']                 = 'Email/username';
$lang['auth_login_both_placeholder']     = 'Your registered email or username';
$lang['auth_login_password_placeholder'] = 'Your password';
$lang['auth_login_label_remember_me']    = 'Remember me';
$lang['auth_login_action_login']         = 'Log in';
$lang['auth_login_forgot']               = 'Forgotten your password?';
$lang['auth_login_social_message']       = 'Or, sign in using your preferred social network.';
$lang['auth_login_social_signin']        = 'Sign in with %s';

//  Messages
$lang['auth_login_ok_welcome']         = 'Welcome, %s! You last logged in %s.';
$lang['auth_login_ok_welcome_with_ip'] = 'Welcome, %s! You last logged in %s from IP address %s.';
$lang['auth_login_ok_welcome_notime']  = 'Welcome, %s! Nice to see you again.';
$lang['auth_login_captcha_fail']       = 'You failed the captcha test.';
$lang['auth_login_pw_expired']         = 'Your password has expired. You must update your password every %s days. Please choose a new password and click "Change Password &amp; Log In"';
$lang['auth_login_pw_temp']            = 'A password reset has been requested for this account. Please choose a new password and click "Change Password &amp; Log In"';

//  with_hashes() lang strings
$lang['auth_with_hashes_incomplete_creds'] = 'Incomplete login credentials.';
$lang['auth_with_hashes_autologin_fail']   = 'Auto-login failed.';

//  Override lang strings
$lang['auth_override_invalid']           = 'Sorry, the supplied credentials failed validation.';
$lang['auth_override_ok']                = 'You were successfully logged in as %s';
$lang['auth_override_return']            = 'You successfully logged back in as %s';
$lang['auth_override_fail_nopermission'] = 'You do not have permission to sign in as other users.';
$lang['auth_override_fail_cloning']      = 'You cannot sign in as this person. For security we do not allow users to sign in as themselves for a second time; doing so will cause a break in the space-time continuum. I don\'t believe you want to be responsible for that now, do you?';
$lang['auth_override_fail_superuser']    = 'You cannot sign in as this person. For security we do not allow users to sign in as superusers; doing so will cause a break in the space-time continuum. I don\'t believe you want to be responsible for that now, do you?';

//  Auth_model lang strings
$lang['auth_login_fail_missing_field']  = 'Sorry, a required field was missing.';
$lang['auth_login_fail_general']        = 'Sorry, your log in has not been successful, please try again.';
$lang['auth_login_fail_general_recent'] = 'Sorry, your log in has not been successful, please try again. You changed your password %s.';
$lang['auth_login_fail_suspended']      = 'This account has been suspended; if you feel you have received this message in error then please contact us.';
$lang['auth_login_fail_blocked']        = 'This account has been temporarily blocked due to repeated failed logins. Please wait %s minutes before trying again (each failed login resets the block). ';
$lang['auth_login_fail_social']         = 'This account was created using a social network. Either login via the appropriate button or <a href="%s">click here to set a password</a> using the Forgotten Password tool.';

//  Two-factor auth strings
$lang['auth_twofactor_token_could_not_generate'] = 'Unable to generate two factor auth token.';
$lang['auth_twofactor_token_invalid']            = 'Invalid token.';
$lang['auth_twofactor_token_expired']            = 'Token has expired.';
$lang['auth_twofactor_token_bad_ip']             = 'Invalid IP address.';
$lang['auth_twofactor_token_unverified']         = 'Sorry, there was a problem verifying your login session. As a precaution we have logged you out.';

$lang['auth_twofactor_question_set_title']         = 'Set Your Security Questions';
$lang['auth_twofactor_question_set_body']          = 'This website offers enhanced security for your account, please specify a few security questions which we\'ll use to verify your identity when you log in to the system.';
$lang['auth_twofactor_question_set_system_body']   = 'The following questions are generated by the system, please choose your preferred question and provide an answer.';
$lang['auth_twofactor_question_set_system_legend'] = 'System questions';
$lang['auth_twofactor_question_set_custom_body']   = 'Specify your own security question and answer combination. Remember to make questions hard for an attacker to guess or research (avoid information which can easily be found on public mediums, such as social networks).';
$lang['auth_twofactor_question_set_custom_legend'] = 'Custom questions';
$lang['auth_twofactor_question_set_fail']          = 'Sorry, there was a problem saving your security questions.';
$lang['auth_twofactor_question_unique']            = 'Sorry, questions must be unique. Please don\'t specify the same question more than once.';

$lang['auth_twofactor_answer_title']     = 'Security question';
$lang['auth_twofactor_answer_body']      = 'Please answer the following security question.';
$lang['auth_twofactor_answer_incorrect'] = 'Sorry, your answer was incorrect.';

// --------------------------------------------------------------------------

//  Social network connect
$lang['auth_social_already_linked']              = 'Woah there! You have already linked your %s account.';
$lang['auth_social_no_access_token']             = 'There was a problem. We could not validate your account with %s, you may be able to try again.';
$lang['auth_social_account_in_use']              = 'Sorry, the %s account you\'re currently logged into is already linked with another %s account.';
$lang['auth_social_email_in_use']                = 'You\'ve been here before? We noticed that the email associated with your %1$s account is already registered with %2$s. In order to use %1$s to sign in you\'ll need to link your accounts via your Settings page. Log in below using your email address and we\'ll get you started.';
$lang['auth_social_email_in_use_no_settings']    = 'You\'ve been here before? We noticed that the email associated with your %1$s account is already registered with %2$s. Please sign in using your email address and password. <a href="%3$s">Forgotten your password</a>?';
$lang['auth_social_linked_ok']                   = 'Success, your %s account is now linked.';
$lang['auth_social_linked_fail']                 = 'Sorry,, we failed to link your %s account.';
$lang['auth_social_register_ok']                 = 'Hi, %s! Your account has been set up and is ready to be used.';
$lang['auth_social_register_disabled']           = 'Sorry, new registrations are not permitted.';
$lang['auth_social_disconnect_ok']               = 'Success! Your %s account was successfully disconnected.';
$lang['auth_social_no_disconnect_fail']          = 'Sorry, there was a problem disconnecting your %s account.';
$lang['auth_social_no_disconnect_not_linked']    = 'Sorry, your account is not currently linked to a %s account.';
$lang['auth_social_no_disconnect_not_logged_in'] = 'Sorry, you must be logged in to disconnect your %s account.';

// --------------------------------------------------------------------------

//  Logout lang strings
$lang['auth_logout_successful'] = 'Goodbye, %s! You have been logged out successfully.';

// --------------------------------------------------------------------------

//  Register lang strings
$lang['auth_register_email_is_unique']        = 'This email is already registered. Have you <a href="%s">forgotten your password</a>?';
$lang['auth_register_username_is_unique']     = 'This username is already registered. Have you <a href="%s">forgotten your password</a>?';
$lang['auth_register_identity_is_unique']     = 'This is already registered. Have you <a href="%s">forgotten your password</a>?';
$lang['auth_register_flashdata_welcome']      = 'Welcome, %s!';
$lang['auth_register_message']                = 'To register with %s simply complete the following form.';
$lang['auth_register_first_name_placeholder'] = 'Your first name';
$lang['auth_register_last_name_placeholder']  = 'Your surname';
$lang['auth_register_email_placeholder']      = 'A valid email address';
$lang['auth_register_username_placeholder']   = 'Your desired username';
$lang['auth_register_password_placeholder']   = 'Choose a password';
$lang['auth_register_label_accept_tc']        = 'I accept the <a href="%s">T&amp;C\'s</a>';

$lang['auth_register_social_message']  = 'Or, to save time, register using your preferred social network.';
$lang['auth_register_social_signin']   = 'Sign in with %s';
$lang['auth_register_social_register'] = 'Register with %s';

# Captcha
$lang['auth_register_captcha_fail'] = 'You failed the captcha test.';

//  Extra info
$lang['auth_register_extra_message'] = 'In order to complete setting up your account we need a little more information.';

//  Wait for activation email
$lang['auth_register_wait_message']      = 'An email with a link to verify your email address has been sent to:';
$lang['auth_register_wait_next_title']   = 'What to do next';
$lang['auth_register_wait_next_message'] = 'Check your e-mail (including spam folders) and click on the link to activate your account! It can take up to 60 seconds to receive your activation e-mail. If you have not received it, use the link below.';
$lang['auth_register_wait_help_title']   = 'Help! I didn\'t receive an e-mail';
$lang['auth_register_wait_help_message'] = 'If you haven\'t received your activation e-mail after a few moments, you can <a href="%s">send it again</a>.';

//  Resend activation
$lang['auth_register_resend_invalid']        = 'Sorry, invalid credentials were supplied. Unable to resend activation email.';
$lang['auth_register_resend_already_active'] = 'Account already active, please <a href="%s">try logging in</a>.';
$lang['auth_register_resend_message']        = 'An email with a link to verify your email has been re-sent to: %s';
$lang['auth_register_resend_next_title']     = 'What to do next';
$lang['auth_register_resend_next_message']   = 'Check your email (including spam folders) and click on the link to verify your email address. It can sometimes take a while to receive your verification email.';

//  Extra info lang strings
$lang['auth_register_extra_title'] = 'Almost there!';

// --------------------------------------------------------------------------

//  Forgotten Password
$lang['auth_forgot_message']                      = 'Please enter your registered email address so we can send you an email with a link which you can use to reset your password.';
$lang['auth_forgot_new_pass_placeholder']         = 'Type a new password';
$lang['auth_forgot_new_pass_confirm_placeholder'] = 'Confirm your new password';
$lang['auth_forgot_email_placeholder']            = 'Your registered email address';
$lang['auth_forgot_username_placeholder']         = 'Your registered username';
$lang['auth_forgot_both']                         = 'Email or username';
$lang['auth_forgot_both_placeholder']             = 'Your registered email or username';
$lang['auth_forgot_action_reset']                 = 'Reset password';
$lang['auth_forgot_action_reset_continue']        = 'Change Password &amp; Log In';
$lang['auth_forgot_success']                      = 'We have sent you an email with further guidance. Please check your junk and spam folders.';
$lang['auth_forgot_email_fail']                   = 'Sorry, there was a problem sending the email with your reset link. Please try again.';
$lang['auth_forgot_email_fail_no_email']          = 'Sorry, there was a problem sending the email with your reset link. Your account has no email address associated with it, please contact us for assistance.';
$lang['auth_forgot_email_fail_no_id']             = 'Sorry, there was a problem sending the email with your reset link. Please try again.';
$lang['auth_forgot_code_not_set_email']           = 'Sorry, we were unable to generate a password reset link for the email address %s.';
$lang['auth_forgot_code_not_set_username']        = 'Sorry, we were unable to generate a password reset link for the username %s.';
$lang['auth_forgot_code_not_set']                 = 'Sorry, we were unable to generate a password reset link for that account.';
$lang['auth_forgot_expired_code']                 = 'Sorry, the password reset link you are using has expired. You will need to request to reset your password again.';
$lang['auth_forgot_invalid_code']                 = 'Sorry, you are using an invalid or expired password reset link.';
$lang['auth_forgot_reset_badlogin']               = 'Sorry, something when wrong and you were not logged in. Please try <a href="%s">logging in with your new password</a>.';
$lang['auth_forgot_reset_badupdate']              = 'Sorry, there were errors. %s';
$lang['auth_forgot_reminder']                     = 'In case you forgot, your temporary password is %s. You won\'t be shown this message again.';
$lang['auth_forgot_reset_ok']                     = 'Please log in using this temporary password:';
$lang['auth_forgot_action_proceed']               = 'Proceed to log in';

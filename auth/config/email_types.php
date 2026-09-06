<?php

/**
 * This config file defines email types for this module.
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    Config
 * @author      Nails Dev Team
 * @link
 */

use Nails\Auth\Constants;

$config['email_types'] = [
    (object) [
        'slug'            => 'forgotten_password',
        'name'            => 'Auth: Forgotten Password',
        'description'     => 'Email which is sent when a user requests a password reset.',
        'template_header' => '',
        'template_body'   => 'auth/email/forgotten_password',
        'template_footer' => '',
        'default_subject' => 'Reset your Password',
        'can_unsubscribe' => false,
        'factory'         => Constants::MODULE_SLUG . '::EmailForgottenPassword',
    ],
    (object) [
        'slug'            => 'new_user',
        'name'            => 'Auth: Welcome Email (Generic)',
        'description'     => 'Email sent to new users when they register on site, or when an administrator creates a new user account.',
        'template_header' => '',
        'template_body'   => 'auth/email/new_user',
        'template_footer' => '',
        'default_subject' => 'Welcome',
        'can_unsubscribe' => false,
        'factory'         => Constants::MODULE_SLUG . '::EmailNewUser',
    ],
    (object) [
        'slug'            => 'password_updated',
        'name'            => 'Auth: Password Updated',
        'description'     => 'Email sent to users when their password is updated, regardless of who updated it.',
        'template_header' => '',
        'template_body'   => 'auth/email/password_updated',
        'template_footer' => '',
        'default_subject' => 'Your Password Has Been Updated',
        'can_unsubscribe' => false,
        'factory'         => Constants::MODULE_SLUG . '::EmailPasswordUpdated',
    ],
    (object) [
        'slug'            => 'user_import_complete',
        'name'            => 'Auth: User Import Complete',
        'description'     => 'Email sent to the administrator who requested a user import when it finishes.',
        'template_header' => '',
        'template_body'   => 'auth/email/user_import_complete',
        'template_footer' => '',
        'default_subject' => 'Your user import has finished',
        'can_unsubscribe' => false,
        'factory'         => Constants::MODULE_SLUG . '::EmailUserImportComplete',
    ],
    (object) [
        'slug'            => 'user_import_failed',
        'name'            => 'Auth: User Import Failed',
        'description'     => 'Email sent to the administrator who requested a user import when it is rejected.',
        'template_header' => '',
        'template_body'   => 'auth/email/user_import_failed',
        'template_footer' => '',
        'default_subject' => 'Your user import was rejected',
        'can_unsubscribe' => false,
        'factory'         => Constants::MODULE_SLUG . '::EmailUserImportFailed',
    ],
    (object) [
        'slug'            => 'verify_email',
        'name'            => 'Auth: Verify Email (Generic)',
        'description'     => 'Email sent with a verification code',
        'template_header' => '',
        'template_body'   => 'auth/email/verify_email',
        'template_footer' => '',
        'default_subject' => 'Please verify your email',
        'can_unsubscribe' => false,
        'factory'         => Constants::MODULE_SLUG . '::EmailVerifyEmail',
    ],
];

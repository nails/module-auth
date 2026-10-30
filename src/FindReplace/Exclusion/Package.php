<?php

namespace Nails\Auth\FindReplace\Exclusion;

use Nails\FindReplace\Interfaces\Exclusion;

class Package implements Exclusion
{
    public function hidden(): array
    {
        return [
            //  Account details, not content; `first_name`, `last_name` and `salutation` remain searchable.
            '/^' . preg_quote(NAILS_DB_PREFIX, '/') . 'user\.(?!first_name$|last_name$|salutation$)/',
            NAILS_DB_PREFIX . 'user_email.code',
            NAILS_DB_PREFIX . 'user_email.email',
            NAILS_DB_PREFIX . 'user_group.acl',
            NAILS_DB_PREFIX . 'user_group.password_rules',
            NAILS_DB_PREFIX . 'user_password_history',
            NAILS_DB_PREFIX . 'user_passkey',
            NAILS_DB_PREFIX . 'user_auth_access_token',
            NAILS_DB_PREFIX . 'user_event',
            NAILS_DB_PREFIX . 'user_import',
            NAILS_DB_PREFIX . 'user_import_item',
            NAILS_DB_PREFIX . 'user_email_blocker',
        ];
    }

    public function readOnly(): array
    {
        return [
            NAILS_DB_PREFIX . 'user_group.default_homepage',
            NAILS_DB_PREFIX . 'user_group.registration_redirect',
        ];
    }
}

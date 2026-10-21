<?php

namespace Nails\Auth\FindReplace\Exclusion;

use Nails\FindReplace\Interfaces\Exclusion;

class Package implements Exclusion
{
    public function hidden(): array
    {
        return [
            NAILS_DB_PREFIX . 'user.password',
            NAILS_DB_PREFIX . 'user.password_md5',
            NAILS_DB_PREFIX . 'user.password_engine',
            NAILS_DB_PREFIX . 'user.salt',
            NAILS_DB_PREFIX . 'user.forgotten_password_code',
            NAILS_DB_PREFIX . 'user.remember_code',
            NAILS_DB_PREFIX . 'user.id_md5',
            NAILS_DB_PREFIX . 'user.user_acl',
            NAILS_DB_PREFIX . 'user_email.code',
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
            NAILS_DB_PREFIX . 'user.username',
            NAILS_DB_PREFIX . 'user.ip_address',
            NAILS_DB_PREFIX . 'user.last_ip',
            NAILS_DB_PREFIX . 'user.referral',
            NAILS_DB_PREFIX . 'user.timezone',
            NAILS_DB_PREFIX . 'user.language',
            NAILS_DB_PREFIX . 'user.datetime_format_date',
            NAILS_DB_PREFIX . 'user.datetime_format_time',
            NAILS_DB_PREFIX . 'user_email.email',
            NAILS_DB_PREFIX . 'user_group.default_homepage',
            NAILS_DB_PREFIX . 'user_group.registration_redirect',
        ];
    }
}

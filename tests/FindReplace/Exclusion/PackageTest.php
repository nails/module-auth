<?php

namespace Tests\Auth\FindReplace\Exclusion;

use Nails\Auth\FindReplace\Exclusion\Package;
use Nails\FindReplace\Exclusion\Pattern;
use PHPUnit\Framework\TestCase;

class PackageTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('NAILS_DB_PREFIX')) {
            define('NAILS_DB_PREFIX', 'nails_');
        }
    }

    public function testHidden(): void
    {
        self::assertSame(
            [
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
            ],
            (new Package())->hidden()
        );
    }

    public function testReadOnly(): void
    {
        self::assertSame(
            [
                NAILS_DB_PREFIX . 'user_group.default_homepage',
                NAILS_DB_PREFIX . 'user_group.registration_redirect',
            ],
            (new Package())->readOnly()
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('userColumnProvider')]
    public function testUserTableHiddenExceptAccountDetails(string $column, bool $expectHidden): void
    {
        $sEntry = (new Package())->hidden()[0];

        self::assertSame(
            $expectHidden,
            Pattern::covers($sEntry, NAILS_DB_PREFIX . 'user', $column)
        );
    }

    public static function userColumnProvider(): array
    {
        return [
            'password is hidden'      => ['password', true],
            'username is hidden'      => ['username', true],
            'ip_address is hidden'    => ['ip_address', true],
            'first_name is visible'   => ['first_name', false],
            'last_name is visible'    => ['last_name', false],
            'salutation is visible'   => ['salutation', false],
        ];
    }
}

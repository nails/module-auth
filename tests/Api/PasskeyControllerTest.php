<?php

namespace Tests\Auth\Api;

use Nails\Auth\Api\Controller\Passkey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The two endpoints a logged out visitor needs are the only ones which may be
 * reached without a session; everything else manages an existing account.
 *
 * @covers \Nails\Auth\Api\Controller\Passkey
 */
class PasskeyControllerTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function publicMethods(): array
    {
        return [
            'challenge'          => ['challenge'],
            'assert'             => ['assert'],
            'challenge, cased'   => ['Challenge'],
            'assert, cased'      => ['ASSERT'],
        ];
    }

    // --------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function protectedMethods(): array
    {
        return [
            'index'    => ['index'],
            'register' => ['register'],
            'attest'   => ['attest'],
            'rename'   => ['rename'],
            'revoke'   => ['revoke'],
            'anything else' => ['somethingUnknown'],
            'no method given' => [''],
        ];
    }

    // --------------------------------------------------------------------------

    #[DataProvider('publicMethods')]
    public function test_the_login_endpoints_are_reachable_when_logged_out(string $sMethod): void
    {
        self::assertTrue(Passkey::isAuthenticated('POST', $sMethod));
    }

    // --------------------------------------------------------------------------

    /**
     * These tests run without a session, so isLoggedIn() is false; anything which is
     * not on the public list must therefore be refused.
     */
    #[DataProvider('protectedMethods')]
    public function test_the_management_endpoints_are_refused_when_logged_out(string $sMethod): void
    {
        self::assertFalse(Passkey::isAuthenticated('POST', $sMethod));
    }

    // --------------------------------------------------------------------------

    public function test_the_public_list_is_exactly_the_two_login_endpoints(): void
    {
        self::assertSame(['challenge', 'assert'], Passkey::PUBLIC_METHODS);
    }
}

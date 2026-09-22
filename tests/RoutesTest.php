<?php

namespace Tests\Auth;

use Nails\Auth\Routes;
use PHPUnit\Framework\TestCase;

/**
 * Passkeys add two controllers, `auth/passkeys` and the API controller. Neither
 * needs a route: CI maps `auth/<controller>` directly, and the API router has its
 * own dispatch. This pins that no route was added by accident, because a stray
 * pattern here would shadow the existing auth URLs.
 *
 * @covers \Nails\Auth\Routes
 */
class RoutesTest extends TestCase
{
    public function test_the_generated_routes_are_unchanged(): void
    {
        self::assertSame(
            [
                'auth/override/login_as/(.+)/(.+)' => 'auth/sessionOverride/login_as',
                'auth/password/forgotten(/(.+))?'  => 'auth/PasswordForgotten/$2',
                'auth/password/reset/(\d+)/(.+)'   => 'auth/PasswordReset/$1/$2',
            ],
            Routes::generate()
        );
    }

    // --------------------------------------------------------------------------

    public function test_no_route_shadows_the_passkey_urls(): void
    {
        foreach (array_keys(Routes::generate()) as $sPattern) {
            self::assertSame(
                0,
                preg_match('#^' . $sPattern . '$#', 'auth/passkeys'),
                sprintf('The route "%s" would capture auth/passkeys', $sPattern)
            );
        }
    }
}

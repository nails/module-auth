<?php

namespace Tests\Auth\Service;

use Nails\Auth\Constants;
use Nails\Auth\Service\Authentication;
use Nails\Factory;
use PHPUnit\Framework\TestCase;

/**
 * The login-method signal is what lets the MFA module recognise that a user-verified
 * passkey login has already proved who the user is, and skip the challenge.
 *
 * @covers \Nails\Auth\Service\Authentication
 */
class AuthenticationLoginMethodTest extends TestCase
{
    private Authentication $oService;

    // --------------------------------------------------------------------------

    protected function setUp(): void
    {
        /** @var Authentication $oService */
        $oService       = Factory::service('Authentication', Constants::MODULE_SLUG);
        $this->oService = $oService;

        $this->oService->clearLoginMethod();
    }

    // --------------------------------------------------------------------------

    protected function tearDown(): void
    {
        $this->oService->clearLoginMethod();
    }

    // --------------------------------------------------------------------------

    public function test_there_is_no_signal_until_a_login_records_one(): void
    {
        self::assertNull($this->oService->getLoginMethod());
        self::assertFalse($this->oService->isLoginUserVerified());
    }

    // --------------------------------------------------------------------------

    public function test_a_recorded_signal_reads_back_intact(): void
    {
        $iBefore = time();

        $this->oService->recordLoginMethod(Authentication::LOGIN_METHOD_PASSKEY, 42, true);

        $oSignal = $this->oService->getLoginMethod();

        self::assertNotNull($oSignal);
        self::assertSame(Authentication::LOGIN_METHOD_PASSKEY, $oSignal->method);
        self::assertSame(42, $oSignal->user_id);
        self::assertTrue($oSignal->user_verified);
        self::assertGreaterThanOrEqual($iBefore, $oSignal->at);
    }

    // --------------------------------------------------------------------------

    /**
     * A password login is never user-verified in the WebAuthn sense, so it must not
     * be mistaken for one which can stand in for a second factor.
     */
    public function test_a_password_login_is_not_recorded_as_user_verified(): void
    {
        $this->oService->recordLoginMethod(Authentication::LOGIN_METHOD_PASSWORD, 42);

        self::assertSame(
            Authentication::LOGIN_METHOD_PASSWORD,
            $this->oService->getLoginMethod()->method
        );
        self::assertFalse($this->oService->isLoginUserVerified());
    }

    // --------------------------------------------------------------------------

    public function test_a_user_verified_signal_is_reported_as_such(): void
    {
        $this->oService->recordLoginMethod(Authentication::LOGIN_METHOD_PASSKEY, 7, true);

        self::assertTrue($this->oService->isLoginUserVerified());
    }

    // --------------------------------------------------------------------------

    public function test_recording_a_second_login_replaces_the_first(): void
    {
        $this->oService->recordLoginMethod(Authentication::LOGIN_METHOD_PASSKEY, 7, true);
        $this->oService->recordLoginMethod(Authentication::LOGIN_METHOD_PASSWORD, 8);

        $oSignal = $this->oService->getLoginMethod();

        self::assertSame(Authentication::LOGIN_METHOD_PASSWORD, $oSignal->method);
        self::assertSame(8, $oSignal->user_id);
        self::assertFalse($oSignal->user_verified);
    }

    // --------------------------------------------------------------------------

    public function test_clearing_the_signal_removes_it(): void
    {
        $this->oService->recordLoginMethod(Authentication::LOGIN_METHOD_PASSKEY, 7, true);
        $this->oService->clearLoginMethod();

        self::assertNull($this->oService->getLoginMethod());
        self::assertFalse($this->oService->isLoginUserVerified());
    }
}

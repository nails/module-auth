<?php

namespace Tests\Auth\Service;

use Nails\Auth\Constants;
use Nails\Auth\Exception\Passkey\InvalidResponseException;
use Nails\Auth\Exception\Passkey\OriginNotAllowedException;
use Nails\Auth\Exception\Passkey\VerificationFailedException;
use Nails\Auth\Resource\User;
use Nails\Auth\Service\Passkey;
use Nails\Config;
use Nails\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Auth\Stub\WebAuthnFixture;

/**
 * Exercises the real WebAuthn verification paths using a synthesised P-256
 * authenticator, so nothing here needs a browser, a device, or a database.
 *
 * @covers \Nails\Auth\Service\Passkey
 */
class PasskeyTest extends TestCase
{
    private const RP_ID  = 'example.com';
    private const ORIGIN = 'https://example.com';

    // --------------------------------------------------------------------------

    private Passkey $oService;

    /** @var array<string, mixed> */
    private array $aConfig = [];

    // --------------------------------------------------------------------------

    protected function setUp(): void
    {
        /** @var Passkey $oService */
        $oService       = Factory::service('Passkey', Constants::MODULE_SLUG);
        $this->oService = $oService;

        foreach ([
            'BASE_URL',
            'SECURE_BASE_URL',
            'APP_NAME',
            Passkey::CONFIG_ENABLED,
            Passkey::CONFIG_RP_ID,
            Passkey::CONFIG_ALLOWED_ORIGINS,
            Passkey::CONFIG_AUTHENTICATORS,
        ] as $sKey) {
            $this->aConfig[$sKey] = Config::get($sKey);
        }

        Config::set('BASE_URL', self::ORIGIN . '/');
        Config::set('SECURE_BASE_URL', self::ORIGIN . '/');
        Config::set('APP_NAME', 'Test App');
        Config::set(Passkey::CONFIG_ENABLED, null);
        Config::set(Passkey::CONFIG_RP_ID, null);
        Config::set(Passkey::CONFIG_ALLOWED_ORIGINS, null);
        Config::set(Passkey::CONFIG_AUTHENTICATORS, null);
    }

    // --------------------------------------------------------------------------

    protected function tearDown(): void
    {
        foreach ($this->aConfig as $sKey => $mValue) {
            Config::set($sKey, $mValue);
        }
    }

    // --------------------------------------------------------------------------

    private function user(int $iId = 1): User
    {
        return new User((object) [
            'id'         => $iId,
            'email'      => 'user' . $iId . '@example.com',
            'username'   => 'user' . $iId,
            'first_name' => 'Test',
            'last_name'  => 'User',
            'name'       => 'Test User',
        ]);
    }

    // --------------------------------------------------------------------------
    //  Configuration
    // --------------------------------------------------------------------------

    public function test_passkeys_are_disabled_by_default(): void
    {
        self::assertFalse($this->oService->isEnabled());
    }

    // --------------------------------------------------------------------------

    public function test_passkeys_can_be_enabled_by_config(): void
    {
        Config::set(Passkey::CONFIG_ENABLED, true);

        self::assertTrue($this->oService->isEnabled());
    }

    // --------------------------------------------------------------------------

    public function test_the_rp_id_defaults_to_the_host_of_the_base_url(): void
    {
        self::assertSame(self::RP_ID, $this->oService->getRpId());
    }

    // --------------------------------------------------------------------------

    public function test_the_rp_id_can_be_overridden_by_config(): void
    {
        Config::set(Passkey::CONFIG_RP_ID, 'Sub.Example.Com');

        //  Lowercased: the RP ID is compared against a host, which is case insensitive
        self::assertSame('sub.example.com', $this->oService->getRpId());
    }

    // --------------------------------------------------------------------------

    public function test_the_rp_name_falls_back_to_the_rp_id(): void
    {
        self::assertSame('Test App', $this->oService->getRpName());

        Config::set('APP_NAME', '');

        self::assertSame(self::RP_ID, $this->oService->getRpName());
    }

    // --------------------------------------------------------------------------

    public function test_the_allowed_origins_cover_both_base_urls_without_duplicates(): void
    {
        Config::set('SECURE_BASE_URL', 'https://secure.example.com/');

        self::assertSame(
            ['https://example.com', 'https://secure.example.com'],
            $this->oService->getAllowedOrigins()
        );
    }

    // --------------------------------------------------------------------------

    public function test_the_allowed_origins_can_be_extended_by_config(): void
    {
        Config::set(Passkey::CONFIG_ALLOWED_ORIGINS, ['https://app.example.com:8443/somewhere']);

        //  Reduced to an origin: a path cannot form part of one
        self::assertContains('https://app.example.com:8443', $this->oService->getAllowedOrigins());
    }

    // --------------------------------------------------------------------------

    public function test_a_default_port_is_dropped_when_normalising_an_origin(): void
    {
        Config::set('BASE_URL', 'https://example.com:443/');

        self::assertSame(['https://example.com'], $this->oService->getAllowedOrigins());
    }

    // --------------------------------------------------------------------------

    /**
     * The library's own origin check is a suffix match on the host alone, which a
     * lookalike domain satisfies; this is the check that actually protects us.
     */
    public function test_a_lookalike_host_is_not_an_allowed_origin(): void
    {
        $this->expectException(OriginNotAllowedException::class);

        $this->oService->assertOriginAllowed('https://evil-example.com');
    }

    // --------------------------------------------------------------------------

    public function test_a_subdomain_of_an_allowed_origin_is_not_itself_allowed(): void
    {
        $this->expectException(OriginNotAllowedException::class);

        $this->oService->assertOriginAllowed('https://evil.example.com');
    }

    // --------------------------------------------------------------------------

    public function test_a_downgraded_scheme_is_not_an_allowed_origin(): void
    {
        $this->expectException(OriginNotAllowedException::class);

        $this->oService->assertOriginAllowed('http://example.com');
    }

    // --------------------------------------------------------------------------

    public function test_the_apps_own_origin_is_allowed(): void
    {
        $this->oService->assertOriginAllowed(self::ORIGIN);

        self::assertTrue(true);
    }

    // --------------------------------------------------------------------------
    //  User handles
    // --------------------------------------------------------------------------

    public function test_a_user_handle_is_stable_for_a_user(): void
    {
        self::assertSame(
            $this->oService->deriveUserHandle($this->user(7)),
            $this->oService->deriveUserHandle($this->user(7))
        );
    }

    // --------------------------------------------------------------------------

    public function test_a_user_handle_differs_between_users(): void
    {
        self::assertNotSame(
            $this->oService->deriveUserHandle($this->user(7)),
            $this->oService->deriveUserHandle($this->user(8))
        );
    }

    // --------------------------------------------------------------------------

    public function test_a_user_handle_is_thirty_two_bytes(): void
    {
        $sHandle = $this->oService->deriveUserHandle($this->user());

        self::assertSame(32, strlen($this->oService->base64UrlDecode($sHandle)));

        //  It must also fit the column it is stored in
        self::assertLessThanOrEqual(64, strlen($sHandle));
    }

    // --------------------------------------------------------------------------
    //  Ceremony options
    // --------------------------------------------------------------------------

    public function test_registration_options_describe_the_relying_party_and_the_user(): void
    {
        $oResult  = $this->oService->buildRegistrationOptions($this->user());
        $oOptions = $oResult->options;

        self::assertSame(self::RP_ID, $oOptions->rp->id);
        self::assertSame('Test App', $oOptions->rp->name);
        self::assertSame('user1@example.com', $oOptions->user->name);
        self::assertSame('Test User', $oOptions->user->displayName);
        self::assertSame(
            $this->oService->deriveUserHandle($this->user()),
            $oOptions->user->id->jsonSerialize()
        );
    }

    // --------------------------------------------------------------------------

    public function test_registration_options_ask_for_a_discoverable_credential_without_attestation(): void
    {
        $oOptions = $this->oService->buildRegistrationOptions($this->user())->options;

        self::assertSame('preferred', $oOptions->authenticatorSelection->residentKey);
        self::assertSame('preferred', $oOptions->authenticatorSelection->userVerification);
        self::assertSame('none', $oOptions->attestation);
        self::assertTrue($oOptions->extensions->credProps);
        self::assertSame(Passkey::TIMEOUT * 1000, $oOptions->timeout);
    }

    // --------------------------------------------------------------------------

    public function test_registration_options_exclude_the_credentials_already_registered(): void
    {
        $sExisting = $this->oService->base64UrlEncode('an-existing-credential');

        $oOptions = $this->oService->buildRegistrationOptions($this->user(), [$sExisting])->options;

        self::assertCount(1, $oOptions->excludeCredentials);
        self::assertSame($sExisting, $oOptions->excludeCredentials[0]->id->jsonSerialize());
    }

    // --------------------------------------------------------------------------

    public function test_each_ceremony_mints_a_fresh_challenge(): void
    {
        self::assertNotSame(
            $this->oService->buildRegistrationOptions($this->user())->challenge,
            $this->oService->buildRegistrationOptions($this->user())->challenge
        );
    }

    // --------------------------------------------------------------------------

    public function test_authentication_options_without_an_allow_list_are_discoverable(): void
    {
        $oOptions = $this->oService->buildAuthenticationOptions()->options;

        self::assertObjectNotHasProperty('allowCredentials', $oOptions);
        self::assertSame('required', $oOptions->userVerification);
        self::assertSame(self::RP_ID, $oOptions->rpId);
    }

    // --------------------------------------------------------------------------

    public function test_authentication_options_carry_the_allow_list_they_are_given(): void
    {
        $sId = $this->oService->base64UrlEncode('a-credential');

        $oOptions = $this->oService
            ->buildAuthenticationOptions([$sId], Passkey::UV_PREFERRED)
            ->options;

        self::assertCount(1, $oOptions->allowCredentials);
        self::assertSame($sId, $oOptions->allowCredentials[0]->id->jsonSerialize());
        self::assertSame('preferred', $oOptions->userVerification);
    }

    // --------------------------------------------------------------------------
    //  Registration verification
    // --------------------------------------------------------------------------

    public function test_a_genuine_registration_is_accepted_and_described(): void
    {
        $oFixture   = new WebAuthnFixture(null, hex2bin('0102030405060708090a0b0c0d0e0f10'));
        $sChallenge = $this->oService->buildRegistrationOptions($this->user())->challenge;

        $oResult = $this->oService->verifyRegistration(
            $oFixture->createRegistrationResponse(
                $sChallenge,
                self::ORIGIN,
                self::RP_ID,
                WebAuthnFixture::FLAG_USER_PRESENT
                | WebAuthnFixture::FLAG_USER_VERIFIED
                | WebAuthnFixture::FLAG_BACKUP_ELIGIBLE
                | WebAuthnFixture::FLAG_BACKED_UP
                | WebAuthnFixture::FLAG_ATTESTED_DATA
            ),
            $sChallenge
        );

        self::assertSame($oFixture->getCredentialIdBase64Url(), $oResult->credential_id);
        self::assertSame(trim($oFixture->getPublicKeyPem()), trim($oResult->public_key));
        self::assertSame('none', $oResult->attestation_format);
        self::assertSame('01020304-0506-0708-090a-0b0c0d0e0f10', $oResult->aaguid);
        self::assertSame(['internal', 'hybrid'], $oResult->transports);
        self::assertTrue($oResult->is_discoverable);
        self::assertTrue($oResult->is_backup_eligible);
        self::assertTrue($oResult->is_backed_up);
        self::assertTrue($oResult->user_verified);
    }

    // --------------------------------------------------------------------------

    /**
     * `fmt: none` authenticators zero the AAGUID out; that is "withheld", not an ID
     * made of zeroes, so it is stored as unknown.
     */
    public function test_a_withheld_aaguid_is_recorded_as_unknown(): void
    {
        $oFixture   = new WebAuthnFixture();
        $sChallenge = $this->oService->buildRegistrationOptions($this->user())->challenge;

        $oResult = $this->oService->verifyRegistration(
            $oFixture->createRegistrationResponse($sChallenge, self::ORIGIN, self::RP_ID),
            $sChallenge
        );

        self::assertNull($oResult->aaguid);
    }

    // --------------------------------------------------------------------------

    public function test_a_registration_answering_a_different_challenge_is_rejected(): void
    {
        $oFixture = new WebAuthnFixture();

        $sAnswered = $this->oService->buildRegistrationOptions($this->user())->challenge;
        $sExpected = $this->oService->buildRegistrationOptions($this->user())->challenge;

        $this->expectException(VerificationFailedException::class);

        $this->oService->verifyRegistration(
            $oFixture->createRegistrationResponse($sAnswered, self::ORIGIN, self::RP_ID),
            $sExpected
        );
    }

    // --------------------------------------------------------------------------

    public function test_a_registration_from_another_origin_is_rejected(): void
    {
        $oFixture   = new WebAuthnFixture();
        $sChallenge = $this->oService->buildRegistrationOptions($this->user())->challenge;

        $this->expectException(OriginNotAllowedException::class);

        $this->oService->verifyRegistration(
            $oFixture->createRegistrationResponse($sChallenge, 'https://evil.example.net', self::RP_ID),
            $sChallenge
        );
    }

    // --------------------------------------------------------------------------

    public function test_an_assertion_presented_as_a_registration_is_rejected(): void
    {
        $oFixture   = new WebAuthnFixture();
        $sChallenge = $this->oService->buildRegistrationOptions($this->user())->challenge;

        $aResponse = $oFixture->createRegistrationResponse(
            $sChallenge,
            self::ORIGIN,
            self::RP_ID,
            WebAuthnFixture::FLAG_USER_PRESENT | WebAuthnFixture::FLAG_ATTESTED_DATA,
            0,
            ['type' => 'webauthn.get']
        );

        $this->expectException(VerificationFailedException::class);

        $this->oService->verifyRegistration($aResponse, $sChallenge);
    }

    // --------------------------------------------------------------------------

    public function test_a_registration_for_a_different_relying_party_is_rejected(): void
    {
        $oFixture   = new WebAuthnFixture();
        $sChallenge = $this->oService->buildRegistrationOptions($this->user())->challenge;

        $this->expectException(VerificationFailedException::class);

        $this->oService->verifyRegistration(
            $oFixture->createRegistrationResponse($sChallenge, self::ORIGIN, 'someone-else.example.com'),
            $sChallenge
        );
    }

    // --------------------------------------------------------------------------

    public function test_a_registration_without_user_presence_is_rejected(): void
    {
        $oFixture   = new WebAuthnFixture();
        $sChallenge = $this->oService->buildRegistrationOptions($this->user())->challenge;

        $this->expectException(VerificationFailedException::class);

        $this->oService->verifyRegistration(
            $oFixture->createRegistrationResponse(
                $sChallenge,
                self::ORIGIN,
                self::RP_ID,
                WebAuthnFixture::FLAG_ATTESTED_DATA
            ),
            $sChallenge
        );
    }

    // --------------------------------------------------------------------------
    //  Authentication verification
    // --------------------------------------------------------------------------

    /**
     * @return array{0: WebAuthnFixture, 1: string, 2: string}
     */
    private function registered(): array
    {
        $oFixture   = new WebAuthnFixture();
        $sChallenge = $this->oService->buildRegistrationOptions($this->user())->challenge;

        $oResult = $this->oService->verifyRegistration(
            $oFixture->createRegistrationResponse($sChallenge, self::ORIGIN, self::RP_ID),
            $sChallenge
        );

        return [$oFixture, $oResult->public_key, $this->oService->deriveUserHandle($this->user())];
    }

    // --------------------------------------------------------------------------

    public function test_a_genuine_assertion_is_accepted_and_reports_its_counter(): void
    {
        [$oFixture, $sPem, $sHandle] = $this->registered();

        $sChallenge = $this->oService->buildAuthenticationOptions()->challenge;

        $iCount = $this->oService->verifyAuthentication(
            $oFixture->createAuthenticationResponse(
                $sChallenge,
                self::ORIGIN,
                self::RP_ID,
                WebAuthnFixture::FLAG_USER_PRESENT | WebAuthnFixture::FLAG_USER_VERIFIED,
                42,
                $sHandle
            ),
            $sChallenge,
            $sPem,
            0,
            $sHandle,
            true
        );

        self::assertSame(42, $iCount);
    }

    // --------------------------------------------------------------------------

    public function test_a_forged_signature_is_rejected(): void
    {
        [$oFixture, $sPem, $sHandle] = $this->registered();

        $sChallenge = $this->oService->buildAuthenticationOptions()->challenge;
        $aResponse  = $oFixture->createAuthenticationResponse($sChallenge, self::ORIGIN, self::RP_ID);

        //  Flip a byte of the signature; everything else stays genuine
        $sSignature                        = $this->oService->base64UrlDecode($aResponse['response']['signature']);
        $sSignature[10]                    = chr(ord($sSignature[10]) ^ 0xFF);
        $aResponse['response']['signature'] = $this->oService->base64UrlEncode($sSignature);

        $this->expectException(VerificationFailedException::class);

        $this->oService->verifyAuthentication($aResponse, $sChallenge, $sPem, 0, $sHandle, true);
    }

    // --------------------------------------------------------------------------

    public function test_an_assertion_signed_by_another_key_is_rejected(): void
    {
        [, $sPem, $sHandle] = $this->registered();

        $oImposter  = new WebAuthnFixture();
        $sChallenge = $this->oService->buildAuthenticationOptions()->challenge;

        $this->expectException(VerificationFailedException::class);

        $this->oService->verifyAuthentication(
            $oImposter->createAuthenticationResponse($sChallenge, self::ORIGIN, self::RP_ID),
            $sChallenge,
            $sPem,
            0,
            $sHandle,
            true
        );
    }

    // --------------------------------------------------------------------------

    /**
     * A counter which has not moved is how a cloned authenticator gives itself away,
     * and is also what a replayed assertion looks like.
     */
    public function test_a_stale_signature_counter_is_rejected(): void
    {
        [$oFixture, $sPem, $sHandle] = $this->registered();

        $sChallenge = $this->oService->buildAuthenticationOptions()->challenge;

        $this->expectException(VerificationFailedException::class);

        $this->oService->verifyAuthentication(
            $oFixture->createAuthenticationResponse(
                $sChallenge,
                self::ORIGIN,
                self::RP_ID,
                WebAuthnFixture::FLAG_USER_PRESENT | WebAuthnFixture::FLAG_USER_VERIFIED,
                5,
                $sHandle
            ),
            $sChallenge,
            $sPem,
            5,
            $sHandle,
            true
        );
    }

    // --------------------------------------------------------------------------

    /**
     * Plenty of platform authenticators never implement a counter and always report
     * zero; those must keep working rather than looking permanently stale.
     */
    public function test_an_authenticator_which_keeps_no_counter_still_authenticates(): void
    {
        [$oFixture, $sPem, $sHandle] = $this->registered();

        $sChallenge = $this->oService->buildAuthenticationOptions()->challenge;

        $iCount = $this->oService->verifyAuthentication(
            $oFixture->createAuthenticationResponse(
                $sChallenge,
                self::ORIGIN,
                self::RP_ID,
                WebAuthnFixture::FLAG_USER_PRESENT | WebAuthnFixture::FLAG_USER_VERIFIED,
                0,
                $sHandle
            ),
            $sChallenge,
            $sPem,
            0,
            $sHandle,
            true
        );

        self::assertSame(0, $iCount);
    }

    // --------------------------------------------------------------------------

    public function test_an_unverified_assertion_is_rejected_when_verification_is_required(): void
    {
        [$oFixture, $sPem, $sHandle] = $this->registered();

        $sChallenge = $this->oService->buildAuthenticationOptions()->challenge;

        $aResponse = $oFixture->createAuthenticationResponse(
            $sChallenge,
            self::ORIGIN,
            self::RP_ID,
            WebAuthnFixture::FLAG_USER_PRESENT,
            1,
            $sHandle
        );

        $this->expectException(VerificationFailedException::class);

        $this->oService->verifyAuthentication($aResponse, $sChallenge, $sPem, 0, $sHandle, true);
    }

    // --------------------------------------------------------------------------

    public function test_an_unverified_assertion_is_accepted_as_a_second_factor(): void
    {
        [$oFixture, $sPem, $sHandle] = $this->registered();

        $sChallenge = $this->oService->buildAuthenticationOptions()->challenge;

        $iCount = $this->oService->verifyAuthentication(
            $oFixture->createAuthenticationResponse(
                $sChallenge,
                self::ORIGIN,
                self::RP_ID,
                WebAuthnFixture::FLAG_USER_PRESENT,
                3,
                $sHandle
            ),
            $sChallenge,
            $sPem,
            0,
            $sHandle,
            false
        );

        self::assertSame(3, $iCount);
    }

    // --------------------------------------------------------------------------

    /**
     * The library does not read the user handle back, so this check lives in the
     * service; without it a discoverable login could present somebody else's handle.
     */
    public function test_an_assertion_carrying_another_users_handle_is_rejected(): void
    {
        [$oFixture, $sPem, $sHandle] = $this->registered();

        $sChallenge = $this->oService->buildAuthenticationOptions()->challenge;

        $aResponse = $oFixture->createAuthenticationResponse(
            $sChallenge,
            self::ORIGIN,
            self::RP_ID,
            WebAuthnFixture::FLAG_USER_PRESENT | WebAuthnFixture::FLAG_USER_VERIFIED,
            1,
            $this->oService->deriveUserHandle($this->user(99))
        );

        $this->expectException(VerificationFailedException::class);

        $this->oService->verifyAuthentication($aResponse, $sChallenge, $sPem, 0, $sHandle, true);
    }

    // --------------------------------------------------------------------------

    public function test_an_assertion_without_a_user_handle_is_accepted(): void
    {
        [$oFixture, $sPem, $sHandle] = $this->registered();

        $sChallenge = $this->oService->buildAuthenticationOptions()->challenge;

        $iCount = $this->oService->verifyAuthentication(
            $oFixture->createAuthenticationResponse(
                $sChallenge,
                self::ORIGIN,
                self::RP_ID,
                WebAuthnFixture::FLAG_USER_PRESENT | WebAuthnFixture::FLAG_USER_VERIFIED,
                1,
                null
            ),
            $sChallenge,
            $sPem,
            0,
            $sHandle,
            true
        );

        self::assertSame(1, $iCount);
    }

    // --------------------------------------------------------------------------

    public function test_an_assertion_answering_a_different_challenge_is_rejected(): void
    {
        [$oFixture, $sPem, $sHandle] = $this->registered();

        $sAnswered = $this->oService->buildAuthenticationOptions()->challenge;
        $sExpected = $this->oService->buildAuthenticationOptions()->challenge;

        $this->expectException(VerificationFailedException::class);

        $this->oService->verifyAuthentication(
            $oFixture->createAuthenticationResponse($sAnswered, self::ORIGIN, self::RP_ID),
            $sExpected,
            $sPem,
            0,
            $sHandle,
            true
        );
    }

    // --------------------------------------------------------------------------
    //  Malformed input
    // --------------------------------------------------------------------------

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function malformedResponses(): array
    {
        return [
            'no response at all'      => [[]],
            'response is not an array' => [['response' => 'nonsense']],
            'missing client data'     => [['response' => ['signature' => 'AAAA', 'authenticatorData' => 'AAAA']]],
            'missing signature'       => [['response' => ['clientDataJSON' => 'AAAA', 'authenticatorData' => 'AAAA']]],
            'empty client data'       => [['response' => ['clientDataJSON' => '', 'authenticatorData' => 'AAAA', 'signature' => 'AAAA']]],
        ];
    }

    // --------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $aResponse
     */
    #[DataProvider('malformedResponses')]
    public function test_a_malformed_assertion_is_reported_as_such(array $aResponse): void
    {
        $this->expectException(InvalidResponseException::class);

        $this->oService->verifyAuthentication($aResponse, 'AAAA', 'not-a-key', 0, 'handle', true);
    }

    // --------------------------------------------------------------------------

    public function test_client_data_which_is_not_json_is_reported_as_malformed(): void
    {
        $this->expectException(InvalidResponseException::class);

        $this->oService->verifyAuthentication(
            [
                'response' => [
                    'clientDataJSON'    => $this->oService->base64UrlEncode('not json at all'),
                    'authenticatorData' => 'AAAA',
                    'signature'         => 'AAAA',
                ],
            ],
            'AAAA',
            'not-a-key',
            0,
            'handle',
            true
        );
    }

    // --------------------------------------------------------------------------

    public function test_a_malformed_registration_is_reported_as_such(): void
    {
        $this->expectException(InvalidResponseException::class);

        $this->oService->verifyRegistration(['response' => ['clientDataJSON' => 'AAAA']], 'AAAA');
    }

    // --------------------------------------------------------------------------
    //  Describing an authenticator
    // --------------------------------------------------------------------------

    public function test_a_known_aaguid_is_named(): void
    {
        //  Verified against a live 1Password registration
        self::assertSame(
            '1Password',
            $this->oService->getAuthenticatorName('bada5566-a7aa-401f-bd96-45619a55120d')
        );
    }

    // --------------------------------------------------------------------------

    public function test_aaguid_matching_ignores_case(): void
    {
        self::assertSame(
            '1Password',
            $this->oService->getAuthenticatorName('BADA5566-A7AA-401F-BD96-45619A55120D')
        );
    }

    // --------------------------------------------------------------------------

    public function test_an_unknown_or_absent_aaguid_is_not_named(): void
    {
        self::assertNull($this->oService->getAuthenticatorName('00000000-0000-0000-0000-000000000000'));
        self::assertNull($this->oService->getAuthenticatorName(null));
        self::assertNull($this->oService->getAuthenticatorName(''));
    }

    // --------------------------------------------------------------------------

    /**
     * The register is maintained by hand, so an app must be able to name an
     * authenticator we have not heard of without waiting for a release.
     */
    public function test_an_app_can_name_an_authenticator_by_config(): void
    {
        $sAaguid = '11111111-2222-3333-4444-555555555555';

        self::assertNull($this->oService->getAuthenticatorName($sAaguid));

        Config::set(Passkey::CONFIG_AUTHENTICATORS, [$sAaguid => 'Acme Key']);

        self::assertSame('Acme Key', $this->oService->getAuthenticatorName($sAaguid));
    }

    // --------------------------------------------------------------------------

    public function test_an_app_can_override_a_listed_authenticator(): void
    {
        Config::set(Passkey::CONFIG_AUTHENTICATORS, [
            'bada5566-a7aa-401f-bd96-45619a55120d' => 'Our Password Manager',
        ]);

        self::assertSame(
            'Our Password Manager',
            $this->oService->getAuthenticatorName('bada5566-a7aa-401f-bd96-45619a55120d')
        );
    }

    // --------------------------------------------------------------------------

    // --------------------------------------------------------------------------
    //  Encoding
    // --------------------------------------------------------------------------

    public function test_base64url_survives_a_round_trip_of_arbitrary_bytes(): void
    {
        $sBinary = random_bytes(64);

        self::assertSame(
            $sBinary,
            $this->oService->base64UrlDecode($this->oService->base64UrlEncode($sBinary))
        );
    }

    // --------------------------------------------------------------------------

    public function test_base64url_encoding_is_url_safe_and_unpadded(): void
    {
        $sEncoded = $this->oService->base64UrlEncode(hex2bin('fbff00'));

        self::assertStringNotContainsString('+', $sEncoded);
        self::assertStringNotContainsString('/', $sEncoded);
        self::assertStringNotContainsString('=', $sEncoded);
    }
}

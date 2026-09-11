<?php

/**
 * This class provides WebAuthn passkey functionality
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    service
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Auth\Service;

use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\WebAuthnException;
use Nails\Auth\Constants;
use Nails\Auth\Exception\Passkey\ChallengeException;
use Nails\Auth\Exception\Passkey\CredentialExistsException;
use Nails\Auth\Exception\Passkey\InvalidResponseException;
use Nails\Auth\Exception\Passkey\NotEnabledException;
use Nails\Auth\Exception\Passkey\OriginNotAllowedException;
use Nails\Auth\Exception\Passkey\PasskeyException;
use Nails\Auth\Exception\Passkey\UnknownCredentialException;
use Nails\Auth\Exception\Passkey\VerificationFailedException;
use Nails\Auth\Model\User\Passkey as PasskeyModel;
use Nails\Auth\Resource;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\ModelException;
use Nails\Common\Service\Cookie;
use Nails\Common\Service\Input;
use Nails\Common\Service\Session;
use Nails\Config;
use Nails\Factory;
use Nails\Functions;
use stdClass;

/**
 * Class Passkey
 *
 * Every call into the underlying WebAuthn library is made from this class, and only
 * from getWebAuthn() and the build*()/verify*() methods, so that a change of library
 * is confined to this file.
 *
 * @package Nails\Auth\Service
 */
class Passkey
{
    /**
     * The app setting, in the `auth` group, which turns passkeys on
     */
    const SETTING_ENABLED = 'passkeys_enabled';

    /**
     * The group the above setting belongs to
     */
    const SETTING_GROUP = 'auth';

    /**
     * Config override for the Relying Party ID; defaults to the host of BASE_URL
     */
    const CONFIG_RP_ID = 'AUTH_PASSKEY_RP_ID';

    /**
     * Config override supplying additional permitted origins, as an array
     */
    const CONFIG_ALLOWED_ORIGINS = 'AUTH_PASSKEY_ALLOWED_ORIGINS';

    /**
     * How long, in seconds, a minted challenge remains usable
     */
    const CHALLENGE_TTL = 300;

    /**
     * How long, in seconds, the authenticator is given to answer
     */
    const TIMEOUT = 60;

    /**
     * The session key the pending challenge is held under
     */
    const SESSION_KEY_CHALLENGE = 'auth-passkey-challenge';

    /**
     * Ceremony purposes; a challenge minted for one cannot be spent on the other
     */
    const PURPOSE_REGISTRATION   = 'registration';
    const PURPOSE_AUTHENTICATION = 'authentication';

    /**
     * User verification requirements
     */
    const UV_REQUIRED  = 'required';
    const UV_PREFERRED = 'preferred';

    /**
     * The only attestation format which is accepted; see J1
     */
    const ATTESTATION_FORMAT_NONE = 'none';

    /**
     * The prefix which namespaces the HMAC used to derive a user handle
     */
    const USER_HANDLE_PREFIX = 'nails-passkey-user-handle:';

    /**
     * Config key supplying additional, or replacement, AAGUID names
     */
    const CONFIG_AUTHENTICATORS = 'AUTH_PASSKEY_AUTHENTICATORS';

    /**
     * Names for the authenticators users are most likely to have.
     *
     * An AAGUID identifies the *model* of authenticator, so this is what lets the
     * management page tell two passkeys apart when they share a label. The list is
     * maintained by hand from the community AAGUID register; anything missing simply
     * falls back to a description of the transports, and an app can add its own with
     * the config key above.
     *
     * @var array<string, string>
     */
    const AUTHENTICATORS = [
        //  Verified against a live registration
        'bada5566-a7aa-401f-bd96-45619a55120d' => '1Password',
        //  From the community AAGUID register
        'fbfc3007-154e-4ecc-8c0b-6e020557d7bd' => 'iCloud Keychain',
        'adce0002-35bc-c60a-648b-0b25f1f05503' => 'Chrome on Mac',
        'ea9b8d66-4d01-1d21-3ce4-b6b48cb575d4' => 'Google Password Manager',
        'd548826e-79b4-db40-a3d8-11116f7e8349' => 'Bitwarden',
        '531126d6-e717-415c-9320-3d9aa6981239' => 'Dashlane',
        '08987058-cadc-4b81-b6e1-30de50dcbe96' => 'Windows Hello',
        '9ddd1817-af5a-4672-a2b9-3e3dd95000a9' => 'Windows Hello',
        '6028b017-b1d4-4c02-b4b3-afcdafc96bb2' => 'Windows Hello',
        'cb69481e-8ff7-4039-93ec-0a2729a154a8' => 'YubiKey 5',
        'ee882879-721c-4913-9775-3dfcce97072a' => 'YubiKey 5',
        'fa2b99dc-9e39-4257-8f92-4a30d23c4118' => 'YubiKey 5 NFC',
        '2fc0579f-8113-47ea-b116-bb5a8db9202a' => 'YubiKey 5 NFC',
    ];

    /**
     * The cookie which suppresses the post-login nudge
     *
     * A cookie rather than user meta because the capability being nudged towards
     * belongs to the browser, not to the account.
     */
    const COOKIE_NUDGE     = 'nails-passkey-nudge';
    const COOKIE_NUDGE_TTL = 31536000;

    /**
     * Set once this session has been offered a passkey, so it is only offered once
     */
    const SESSION_KEY_NUDGED = 'auth-passkey-nudged';

    /**
     * Where to send the user once they have answered the nudge
     */
    const SESSION_KEY_NUDGE_RETURN = 'auth-passkey-nudge-return';

    // --------------------------------------------------------------------------
    //  Configuration
    // --------------------------------------------------------------------------

    /**
     * Whether passkeys are available to this app
     *
     * @throws FactoryException
     */
    public function isEnabled(): bool
    {
        return (bool) appSetting(static::SETTING_ENABLED, static::SETTING_GROUP)
            && extension_loaded('openssl');
    }

    // --------------------------------------------------------------------------

    /**
     * Throws if passkeys are not enabled
     *
     * @throws FactoryException
     * @throws NotEnabledException
     */
    public function assertEnabled(): void
    {
        if (!$this->isEnabled()) {
            throw new NotEnabledException('Passkeys are not enabled.');
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the Relying Party ID: the config override, else the host of BASE_URL
     *
     * A single host per app is assumed; the RP ID must be the site's registrable
     * domain or a suffix of it, and cannot include a scheme, port, or path.
     */
    public function getRpId(): string
    {
        $sConfigured = Config::get(static::CONFIG_RP_ID);
        if (is_string($sConfigured) && trim($sConfigured) !== '') {
            return strtolower(trim($sConfigured));
        }

        return $this->extractHost((string) Config::get('BASE_URL')) ?? 'localhost';
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the Relying Party name shown by the authenticator
     */
    public function getRpName(): string
    {
        $sAppName = Config::get('APP_NAME');

        return is_string($sAppName) && trim($sAppName) !== ''
            ? trim($sAppName)
            : $this->getRpId();
    }

    // --------------------------------------------------------------------------

    /**
     * Returns every origin a ceremony may legitimately be performed from
     *
     * @return string[]
     */
    public function getAllowedOrigins(): array
    {
        $aOrigins = [
            $this->extractOrigin((string) Config::get('BASE_URL')),
            $this->extractOrigin((string) Config::get('SECURE_BASE_URL')),
        ];

        $mConfigured = Config::get(static::CONFIG_ALLOWED_ORIGINS);
        if (is_string($mConfigured)) {
            $mConfigured = [$mConfigured];
        }

        if (is_array($mConfigured)) {
            foreach ($mConfigured as $mOrigin) {
                if (is_string($mOrigin)) {
                    $aOrigins[] = $this->extractOrigin($mOrigin);
                }
            }
        }

        return array_values(array_unique(array_filter($aOrigins)));
    }

    // --------------------------------------------------------------------------

    /**
     * Rejects an origin which is not one of the app's own
     *
     * The library performs a suffix match on the host alone; this is an exact match
     * on the whole origin, so a lookalike host or a downgraded scheme is refused.
     *
     * @throws OriginNotAllowedException
     */
    public function assertOriginAllowed(string $sOrigin): void
    {
        $sNormalised = $this->extractOrigin($sOrigin);

        if ($sNormalised === null || !in_array($sNormalised, $this->getAllowedOrigins(), true)) {
            throw new OriginNotAllowedException(
                sprintf('"%s" is not a permitted origin.', $sOrigin)
            );
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Derives the opaque, stable user handle presented to the authenticator
     *
     * Deriving it rather than storing it keeps the `user` table untouched; see J3.
     * The handle is written to each passkey row at registration, so verification
     * still succeeds for existing credentials after a PRIVATE_KEY rotation.
     */
    public function deriveUserHandle(Resource\User $oUser): string
    {
        return $this->base64UrlEncode(
            hash_hmac(
                'sha256',
                static::USER_HANDLE_PREFIX . $oUser->id,
                (string) Config::get('PRIVATE_KEY'),
                true
            )
        );
    }

    /**
     * Names the model of authenticator a passkey lives in, if it is a known one
     *
     * @return string|null Null when the authenticator did not say, or is not listed
     */
    public function getAuthenticatorName(?string $sAaguid): ?string
    {
        if (empty($sAaguid)) {
            return null;
        }

        $aConfigured = Config::get(static::CONFIG_AUTHENTICATORS);
        $aKnown      = array_merge(
            static::AUTHENTICATORS,
            is_array($aConfigured) ? $aConfigured : []
        );

        $sName = $aKnown[strtolower($sAaguid)] ?? null;

        return is_string($sName) && $sName !== '' ? $sName : null;
    }


    // --------------------------------------------------------------------------
    //  Ceremony construction and verification; no database, no session
    // --------------------------------------------------------------------------

    /**
     * The single point at which the WebAuthn library is constructed
     *
     * A new instance is returned every time: the library mints and caches one
     * challenge per instance, so sharing one would re-issue a spent challenge.
     *
     * @throws PasskeyException
     */
    public function getWebAuthn(): WebAuthn
    {
        try {

            return new WebAuthn(
                $this->getRpName(),
                $this->getRpId(),
                [static::ATTESTATION_FORMAT_NONE],
                true
            );

        } catch (WebAuthnException $e) {
            throw new PasskeyException(
                'Failed to initialise WebAuthn: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Builds the options for a registration ceremony
     *
     * @param string[] $aExcludeCredentialIds base64url credential IDs already registered
     *
     * @return stdClass&object{options: stdClass, challenge: string}
     * @throws PasskeyException
     */
    public function buildRegistrationOptions(Resource\User $oUser, array $aExcludeCredentialIds = []): stdClass
    {
        $oWebAuthn = $this->getWebAuthn();

        try {

            $oArgs = $oWebAuthn->getCreateArgs(
                $this->base64UrlDecode($this->deriveUserHandle($oUser)),
                $this->getUserName($oUser),
                $this->getUserDisplayName($oUser),
                static::TIMEOUT,
                'preferred',
                static::UV_PREFERRED,
                null,
                array_map(
                    fn(string $sId): string => $this->base64UrlDecode($sId),
                    array_values($aExcludeCredentialIds)
                )
            );

        } catch (WebAuthnException $e) {
            throw new PasskeyException($e->getMessage(), $e->getCode(), $e);
        }

        //  Ask the browser whether the credential ended up discoverable
        $oArgs->publicKey->extensions->credProps = true;

        return (object) [
            'options'   => $oArgs->publicKey,
            'challenge' => $this->base64UrlEncode($oWebAuthn->getChallenge()->getBinaryString()),
        ];
    }

    // --------------------------------------------------------------------------

    /**
     * Builds the options for an authentication ceremony
     *
     * An empty allow-list produces a discoverable ("passwordless") request.
     *
     * @param string[] $aAllowCredentialIds base64url credential IDs
     *
     * @return stdClass&object{options: stdClass, challenge: string}
     * @throws PasskeyException
     */
    public function buildAuthenticationOptions(
        array $aAllowCredentialIds = [],
        string $sUserVerification = self::UV_REQUIRED
    ): stdClass {

        $oWebAuthn = $this->getWebAuthn();

        try {

            $oArgs = $oWebAuthn->getGetArgs(
                array_map(
                    fn(string $sId): string => $this->base64UrlDecode($sId),
                    array_values($aAllowCredentialIds)
                ),
                static::TIMEOUT,
                true,
                true,
                true,
                true,
                true,
                $sUserVerification
            );

        } catch (WebAuthnException $e) {
            throw new PasskeyException($e->getMessage(), $e->getCode(), $e);
        }

        return (object) [
            'options'   => $oArgs->publicKey,
            'challenge' => $this->base64UrlEncode($oWebAuthn->getChallenge()->getBinaryString()),
        ];
    }

    // --------------------------------------------------------------------------

    /**
     * Verifies a registration response and returns the data to store
     *
     * @param array<string, mixed> $aClientResponse PublicKeyCredential.toJSON() output
     *
     * @return stdClass&object{credential_id: string, public_key: string, sign_count: int, aaguid: string|null,
     *                          attestation_format: string|null, transports: string[], is_discoverable: bool|null,
     *                          is_backup_eligible: bool, is_backed_up: bool, user_present: bool,
     *                          user_verified: bool}
     * @throws InvalidResponseException
     * @throws OriginNotAllowedException
     * @throws PasskeyException
     * @throws VerificationFailedException
     */
    public function verifyRegistration(array $aClientResponse, string $sChallenge): stdClass
    {
        $aResponse         = $this->extractResponse($aClientResponse);
        $sClientDataJson   = $this->requireBinary($aResponse, 'clientDataJSON');
        $sAttestationObject = $this->requireBinary($aResponse, 'attestationObject');

        $this->assertOriginAllowed(
            $this->readOriginFromClientData($sClientDataJson)
        );

        $oWebAuthn = $this->getWebAuthn();

        try {

            $oData = $oWebAuthn->processCreate(
                $sClientDataJson,
                $sAttestationObject,
                $this->base64UrlDecode($sChallenge),
                false,
                true,
                false,
                false
            );

        } catch (WebAuthnException $e) {
            throw new VerificationFailedException($e->getMessage(), $e->getCode(), $e);
        }

        return (object) [
            'credential_id'      => $this->base64UrlEncode($oData->credentialId),
            'public_key'         => (string) $oData->credentialPublicKey,
            'sign_count'         => (int) ($oData->signatureCounter ?? 0),
            'aaguid'             => $this->formatAaguid((string) $oData->AAGUID),
            'attestation_format' => $oData->attestationFormat ? (string) $oData->attestationFormat : null,
            'transports'         => $this->extractTransports($aResponse),
            'is_discoverable'    => $this->extractIsDiscoverable($aClientResponse),
            'is_backup_eligible' => (bool) $oData->isBackupEligible,
            'is_backed_up'       => (bool) $oData->isBackedUp,
            'user_present'       => (bool) $oData->userPresent,
            'user_verified'      => (bool) $oData->userVerified,
        ];
    }

    // --------------------------------------------------------------------------

    /**
     * Verifies an authentication response and returns the authenticator's sign count
     *
     * Returns 0 for authenticators which do not maintain a counter; the caller must
     * only persist a count which has grown.
     *
     * @param array<string, mixed> $aClientResponse PublicKeyCredential.toJSON() output
     *
     * @throws InvalidResponseException
     * @throws OriginNotAllowedException
     * @throws PasskeyException
     * @throws VerificationFailedException
     */
    public function verifyAuthentication(
        array $aClientResponse,
        string $sChallenge,
        string $sPublicKeyPem,
        int $iPrevSignCount,
        string $sExpectedUserHandle,
        bool $bRequireUserVerification
    ): int {

        $aResponse         = $this->extractResponse($aClientResponse);
        $sClientDataJson   = $this->requireBinary($aResponse, 'clientDataJSON');
        $sAuthenticatorData = $this->requireBinary($aResponse, 'authenticatorData');
        $sSignature        = $this->requireBinary($aResponse, 'signature');

        $this->assertOriginAllowed(
            $this->readOriginFromClientData($sClientDataJson)
        );

        /**
         * The library does not read the user handle back, so it is checked here: a
         * handle which is present but belongs to somebody else must not authenticate.
         */
        $sUserHandle = $aResponse['userHandle'] ?? null;
        if (is_string($sUserHandle) && $sUserHandle !== '') {
            if (!hash_equals($sExpectedUserHandle, $sUserHandle)) {
                throw new VerificationFailedException('The credential belongs to a different user.');
            }
        }

        $oWebAuthn = $this->getWebAuthn();

        try {

            $oWebAuthn->processGet(
                $sClientDataJson,
                $sAuthenticatorData,
                $sSignature,
                $sPublicKeyPem,
                $this->base64UrlDecode($sChallenge),
                $iPrevSignCount,
                $bRequireUserVerification,
                true
            );

        } catch (WebAuthnException $e) {
            throw new VerificationFailedException($e->getMessage(), $e->getCode(), $e);
        }

        return $oWebAuthn->getSignatureCounter() ?? 0;
    }

    // --------------------------------------------------------------------------
    //  Orchestration; these touch the database and the session
    // --------------------------------------------------------------------------

    /**
     * Mints registration options for a user, excluding the passkeys they already have
     *
     * @return stdClass&object{options: stdClass, challenge: string}
     * @throws FactoryException
     * @throws ModelException
     * @throws NotEnabledException
     * @throws PasskeyException
     */
    public function createRegistrationOptions(Resource\User $oUser): stdClass
    {
        $this->assertEnabled();

        $aExisting = array_map(
            fn(Resource\User\Passkey $oPasskey): string => $oPasskey->credential_id,
            $this->getModel()->getByUserId((int) $oUser->id)
        );

        $oOptions = $this->buildRegistrationOptions($oUser, $aExisting);

        $this->rememberChallenge(
            static::PURPOSE_REGISTRATION,
            $oOptions->challenge,
            (int) $oUser->id
        );

        return $oOptions;
    }

    // --------------------------------------------------------------------------

    /**
     * Verifies a registration response and stores the resulting passkey
     *
     * @param array<string, mixed> $aClientResponse
     *
     * @throws CredentialExistsException
     * @throws FactoryException
     * @throws InvalidResponseException
     * @throws ModelException
     * @throws NotEnabledException
     * @throws OriginNotAllowedException
     * @throws PasskeyException
     * @throws VerificationFailedException
     */
    public function completeRegistration(
        Resource\User $oUser,
        array $aClientResponse,
        string $sChallenge,
        ?string $sLabel = null
    ): Resource\User\Passkey {

        $this->assertEnabled();

        $oModel  = $this->getModel();
        $oResult = $this->verifyRegistration($aClientResponse, $sChallenge);

        if ($oModel->getByCredentialId($oResult->credential_id)) {
            throw new CredentialExistsException('This passkey is already registered.');
        }

        /** @var Resource\User\Passkey|false $oPasskey */
        $oPasskey = $oModel->create(
            [
                'user_id'            => (int) $oUser->id,
                'label'              => $this->normaliseLabel(
                    trim((string) $sLabel) !== ''
                        ? $sLabel
                        //  Naming it after the authenticator beats a list of "Passkey"
                        : $this->getAuthenticatorName($oResult->aaguid)
                ),
                'credential_id'      => $oResult->credential_id,
                'public_key'         => $oResult->public_key,
                'sign_count'         => $oResult->sign_count,
                'aaguid'             => $oResult->aaguid,
                'attestation_format' => $oResult->attestation_format,
                'transports'         => $oResult->transports ? json_encode($oResult->transports) : null,
                'is_discoverable'    => $oResult->is_discoverable,
                'is_backup_eligible' => $oResult->is_backup_eligible,
                'is_backed_up'       => $oResult->is_backed_up,
                'user_handle'        => $this->deriveUserHandle($oUser),
            ],
            true
        );

        if (empty($oPasskey)) {
            throw new PasskeyException('Failed to save the passkey.');
        }

        createUserEvent(
            'did_add_passkey',
            ['passkey_id' => $oPasskey->id, 'label' => $oPasskey->label],
            null,
            (int) $oUser->id
        );

        return $oPasskey;
    }

    // --------------------------------------------------------------------------

    /**
     * Mints authentication options
     *
     * Passing no user produces a discoverable request, which is what the passwordless
     * button and the conditional-UI autofill both use.
     *
     * @return stdClass&object{options: stdClass, challenge: string}
     * @throws FactoryException
     * @throws ModelException
     * @throws NotEnabledException
     * @throws PasskeyException
     */
    public function createAuthenticationOptions(
        ?Resource\User $oUser = null,
        string $sUserVerification = self::UV_REQUIRED
    ): stdClass {

        $this->assertEnabled();

        $aAllow = $oUser
            ? array_map(
                fn(Resource\User\Passkey $oPasskey): string => $oPasskey->credential_id,
                $this->getModel()->getByUserId((int) $oUser->id)
            )
            : [];

        $oOptions = $this->buildAuthenticationOptions($aAllow, $sUserVerification);

        $this->rememberChallenge(
            static::PURPOSE_AUTHENTICATION,
            $oOptions->challenge,
            $oUser ? (int) $oUser->id : null
        );

        return $oOptions;
    }

    // --------------------------------------------------------------------------

    /**
     * Looks up the passkey an assertion refers to
     *
     * @param array<string, mixed> $aClientResponse
     *
     * @throws FactoryException
     * @throws ModelException
     */
    public function findByAssertion(array $aClientResponse): ?Resource\User\Passkey
    {
        $sId = $aClientResponse['rawId'] ?? $aClientResponse['id'] ?? null;

        return is_string($sId)
            ? $this->getModel()->getByCredentialId($sId)
            : null;
    }

    // --------------------------------------------------------------------------

    /**
     * Verifies an assertion against a stored passkey
     *
     * @param array<string, mixed> $aClientResponse
     *
     * @throws FactoryException
     * @throws InvalidResponseException
     * @throws ModelException
     * @throws NotEnabledException
     * @throws OriginNotAllowedException
     * @throws PasskeyException
     * @throws UnknownCredentialException
     * @throws VerificationFailedException
     */
    public function completeAuthentication(
        array $aClientResponse,
        string $sChallenge,
        ?Resource\User $oRestrictToUser = null,
        bool $bRequireUserVerification = true
    ): Resource\User\Passkey {

        $this->assertEnabled();

        $oPasskey = $this->findByAssertion($aClientResponse);

        if (empty($oPasskey)) {
            throw new UnknownCredentialException('Unrecognised passkey.');

        } elseif ($oRestrictToUser && (int) $oPasskey->user_id !== (int) $oRestrictToUser->id) {
            throw new UnknownCredentialException('Unrecognised passkey.');
        }

        $iSignCount = $this->verifyAuthentication(
            $aClientResponse,
            $sChallenge,
            $oPasskey->getPublicKeyPem(),
            $oPasskey->sign_count,
            $oPasskey->user_handle,
            $bRequireUserVerification
        );

        /** @var Input $oInput */
        $oInput = Factory::service('Input');

        /**
         * Authenticators which do not keep a counter always report zero; writing that
         * back would be indistinguishable from a clone, so only a count which grew is
         * persisted. The timestamp is recorded either way.
         */
        $this->getModel()->recordUse(
            (int) $oPasskey->id,
            max($iSignCount, $oPasskey->sign_count),
            (string) $oInput->ipAddress()
        );

        return $oPasskey;
    }

    // --------------------------------------------------------------------------

    /**
     * Renames a passkey
     *
     * @throws FactoryException
     * @throws ModelException
     */
    public function rename(Resource\User\Passkey $oPasskey, string $sLabel): bool
    {
        return $this->getModel()->update((int) $oPasskey->id, [
            'label' => $this->normaliseLabel($sLabel),
        ]);
    }

    // --------------------------------------------------------------------------

    /**
     * Removes a passkey
     *
     * @param array<string, mixed> $aEventData additional context to log against the event
     *
     * @throws FactoryException
     * @throws ModelException
     */
    public function revoke(Resource\User\Passkey $oPasskey, array $aEventData = []): bool
    {
        $bResult = $this->getModel()->delete((int) $oPasskey->id);

        if ($bResult) {
            createUserEvent(
                'did_remove_passkey',
                array_merge(
                    ['passkey_id' => $oPasskey->id, 'label' => $oPasskey->label],
                    $aEventData
                ),
                null,
                (int) $oPasskey->user_id
            );
        }

        return $bResult;
    }

    // --------------------------------------------------------------------------
    //  Adoption nudge
    // --------------------------------------------------------------------------

    /**
     * Stops this browser being nudged again
     *
     * @throws FactoryException
     */
    public function setNudgeDismissed(): void
    {
        /** @var Cookie $oCookie */
        $oCookie = Factory::service('Cookie');
        $oCookie->write(
            static::COOKIE_NUDGE,
            'dismissed',
            static::COOKIE_NUDGE_TTL,
            '/',
            '',
            Functions::isPageSecure(),
            true,
            'Lax'
        );
    }

    // --------------------------------------------------------------------------

    /**
     * Whether this browser has already been nudged away
     *
     * @throws FactoryException
     */
    public function isNudgeDismissed(): bool
    {
        /** @var Cookie $oCookie */
        $oCookie = Factory::service('Cookie');

        return !empty($oCookie->read(static::COOKIE_NUDGE));
    }

    /**
     * Records that this session has been offered a passkey, and where to return to
     *
     * @throws FactoryException
     */
    public function markNudged(string $sReturnTo): void
    {
        /** @var Session $oSession */
        $oSession = Factory::service('Session');
        $oSession
            ->setUserData(static::SESSION_KEY_NUDGED, true)
            ->setUserData(static::SESSION_KEY_NUDGE_RETURN, $sReturnTo);
    }

    // --------------------------------------------------------------------------

    /**
     * Whether this session has already been offered a passkey
     *
     * @throws FactoryException
     */
    public function hasBeenNudged(): bool
    {
        /** @var Session $oSession */
        $oSession = Factory::service('Session');

        return !empty($oSession->getUserData(static::SESSION_KEY_NUDGED));
    }

    // --------------------------------------------------------------------------

    /**
     * Reads, and forgets, where the nudge should return the user to
     *
     * @throws FactoryException
     */
    public function consumeNudgeReturn(): ?string
    {
        /** @var Session $oSession */
        $oSession = Factory::service('Session');

        $mReturnTo = $oSession->getUserData(static::SESSION_KEY_NUDGE_RETURN);
        $oSession->unsetUserData(static::SESSION_KEY_NUDGE_RETURN);

        return is_string($mReturnTo) && $mReturnTo !== '' ? $mReturnTo : null;
    }

    // --------------------------------------------------------------------------
    //  Challenge store
    // --------------------------------------------------------------------------

    /**
     * Remembers the challenge for the ceremony's second request
     *
     * Ordinary session data rather than flash data: the ceremony spans two requests
     * and the flash would be gone by the time the response comes back.
     *
     * @throws FactoryException
     */
    public function rememberChallenge(string $sPurpose, string $sChallenge, ?int $iUserId): void
    {
        /** @var Session $oSession */
        $oSession = Factory::service('Session');
        $oSession->setUserData(static::SESSION_KEY_CHALLENGE, (object) [
            'purpose'   => $sPurpose,
            'challenge' => $sChallenge,
            'user_id'   => $iUserId,
            'at'        => time(),
        ]);
    }

    // --------------------------------------------------------------------------

    /**
     * Spends the stored challenge, removing it whether or not it turns out to be valid
     *
     * @throws ChallengeException
     * @throws FactoryException
     */
    public function consumeChallenge(string $sPurpose, ?int $iUserId): string
    {
        /** @var Session $oSession */
        $oSession = Factory::service('Session');

        $oStored = $oSession->getUserData(static::SESSION_KEY_CHALLENGE);
        $oSession->unsetUserData(static::SESSION_KEY_CHALLENGE);

        if (!is_object($oStored) || !isset($oStored->challenge, $oStored->purpose, $oStored->at)) {
            throw new ChallengeException('No passkey challenge is in progress; please try again.');

        } elseif ($oStored->purpose !== $sPurpose) {
            throw new ChallengeException('The passkey challenge was issued for something else.');

        } elseif (($oStored->user_id ?? null) !== $iUserId) {
            throw new ChallengeException('The passkey challenge was issued for a different user.');

        } elseif ((time() - (int) $oStored->at) > static::CHALLENGE_TTL) {
            throw new ChallengeException('The passkey challenge has expired; please try again.');
        }

        return (string) $oStored->challenge;
    }

    // --------------------------------------------------------------------------
    //  Encoding
    // --------------------------------------------------------------------------

    /**
     * Encodes binary as base64url, without padding
     */
    public function base64UrlEncode(string $sBinary): string
    {
        return rtrim(strtr(base64_encode($sBinary), '+/', '-_'), '=');
    }

    // --------------------------------------------------------------------------

    /**
     * Decodes base64url, tolerating missing padding
     */
    public function base64UrlDecode(string $sEncoded): string
    {
        return (string) base64_decode(
            str_pad(strtr($sEncoded, '-_', '+/'), (int) (ceil(strlen($sEncoded) / 4) * 4), '='),
            false
        );
    }

    // --------------------------------------------------------------------------
    //  Internals
    // --------------------------------------------------------------------------

    /**
     * @throws FactoryException
     */
    protected function getModel(): PasskeyModel
    {
        /** @var PasskeyModel $oModel */
        $oModel = Factory::model('UserPasskey', Constants::MODULE_SLUG);

        return $oModel;
    }

    // --------------------------------------------------------------------------

    /**
     * Pulls the `response` object out of a client payload
     *
     * @param array<string, mixed> $aClientResponse
     *
     * @return array<string, mixed>
     * @throws InvalidResponseException
     */
    protected function extractResponse(array $aClientResponse): array
    {
        $mResponse = $aClientResponse['response'] ?? null;

        if (is_object($mResponse)) {
            $mResponse = (array) $mResponse;
        }

        if (!is_array($mResponse)) {
            throw new InvalidResponseException('The passkey response is missing or malformed.');
        }

        return $mResponse;
    }

    // --------------------------------------------------------------------------

    /**
     * Reads and decodes a required base64url field
     *
     * @param array<string, mixed> $aResponse
     *
     * @throws InvalidResponseException
     */
    protected function requireBinary(array $aResponse, string $sKey): string
    {
        $mValue = $aResponse[$sKey] ?? null;

        if (!is_string($mValue) || $mValue === '') {
            throw new InvalidResponseException(
                sprintf('The passkey response is missing "%s".', $sKey)
            );
        }

        $sDecoded = $this->base64UrlDecode($mValue);

        if ($sDecoded === '') {
            throw new InvalidResponseException(
                sprintf('The passkey response field "%s" is not valid base64url.', $sKey)
            );
        }

        return $sDecoded;
    }

    // --------------------------------------------------------------------------

    /**
     * Reads the origin out of the client data
     *
     * @throws InvalidResponseException
     */
    protected function readOriginFromClientData(string $sClientDataJson): string
    {
        $mClientData = json_decode($sClientDataJson);

        if (!is_object($mClientData) || !isset($mClientData->origin) || !is_string($mClientData->origin)) {
            throw new InvalidResponseException('The passkey client data is malformed.');
        }

        return $mClientData->origin;
    }

    // --------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $aResponse
     *
     * @return string[]
     */
    protected function extractTransports(array $aResponse): array
    {
        $mTransports = $aResponse['transports'] ?? null;

        return is_array($mTransports)
            ? array_values(array_filter($mTransports, 'is_string'))
            : [];
    }

    // --------------------------------------------------------------------------

    /**
     * Reads credProps.rk; null when the browser did not say either way
     *
     * @param array<string, mixed> $aClientResponse
     */
    protected function extractIsDiscoverable(array $aClientResponse): ?bool
    {
        $mExtensions = $aClientResponse['clientExtensionResults'] ?? null;

        if (is_object($mExtensions)) {
            $mExtensions = (array) $mExtensions;
        }

        if (!is_array($mExtensions)) {
            return null;
        }

        $mCredProps = $mExtensions['credProps'] ?? null;

        if (is_object($mCredProps)) {
            $mCredProps = (array) $mCredProps;
        }

        if (!is_array($mCredProps) || !array_key_exists('rk', $mCredProps)) {
            return null;
        }

        return (bool) $mCredProps['rk'];
    }

    // --------------------------------------------------------------------------

    /**
     * Formats a raw 16 byte AAGUID as a UUID; null when the authenticator withheld it
     */
    protected function formatAaguid(string $sBinary): ?string
    {
        if (strlen($sBinary) !== 16 || trim($sBinary, "\x00") === '') {
            return null;
        }

        $sHex = bin2hex($sBinary);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($sHex, 0, 8),
            substr($sHex, 8, 4),
            substr($sHex, 12, 4),
            substr($sHex, 16, 4),
            substr($sHex, 20, 12)
        );
    }

    // --------------------------------------------------------------------------

    /**
     * Normalises a user-supplied label, falling back to a generic one
     */
    protected function normaliseLabel(?string $sLabel): string
    {
        $sLabel = trim((string) $sLabel);

        if ($sLabel === '') {
            return 'Passkey';
        }

        return mb_substr($sLabel, 0, 100);
    }

    // --------------------------------------------------------------------------

    /**
     * The name the authenticator shows for the account
     */
    protected function getUserName(Resource\User $oUser): string
    {
        $sName = (string) ($oUser->email ?: $oUser->username ?: $oUser->id);

        return mb_substr($sName, 0, 64);
    }

    // --------------------------------------------------------------------------

    /**
     * The human-friendly name the authenticator shows for the account
     */
    protected function getUserDisplayName(Resource\User $oUser): string
    {
        $sName = trim((string) $oUser->name);

        if ($sName === '') {
            $sName = trim(sprintf('%s %s', $oUser->first_name, $oUser->last_name));
        }

        return mb_substr($sName !== '' ? $sName : $this->getUserName($oUser), 0, 64);
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the lowercase host of a URL
     */
    protected function extractHost(string $sUrl): ?string
    {
        $sHost = parse_url($sUrl, PHP_URL_HOST);

        return is_string($sHost) && $sHost !== '' ? strtolower($sHost) : null;
    }

    // --------------------------------------------------------------------------

    /**
     * Reduces a URL to its origin: scheme://host[:port], with the default port dropped
     */
    protected function extractOrigin(string $sUrl): ?string
    {
        $sUrl = trim($sUrl);

        if ($sUrl === '') {
            return null;
        }

        $aParts  = parse_url($sUrl);
        $sScheme = isset($aParts['scheme']) ? strtolower($aParts['scheme']) : null;
        $sHost   = isset($aParts['host']) ? strtolower($aParts['host']) : null;

        if (empty($sScheme) || empty($sHost)) {
            return null;
        }

        $iPort      = $aParts['port'] ?? null;
        $bIsDefault = ($sScheme === 'https' && $iPort === 443) || ($sScheme === 'http' && $iPort === 80);

        return $sScheme . '://' . $sHost . ($iPort && !$bIsDefault ? ':' . $iPort : '');
    }
}

<?php

namespace Tests\Auth\Stub;

use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * Synthesises the output of a real authenticator, so that the verification paths in
 * the WebAuthn library run for real without a browser, a device, or a network.
 *
 * A P-256 keypair is generated per instance; assertions are signed with it, so a
 * signature which verifies here verifies for the same reason a genuine one does.
 */
final class WebAuthnFixture
{
    /**
     * Authenticator data flags
     * https://www.w3.org/TR/webauthn-2/#flags
     */
    public const FLAG_USER_PRESENT     = 0x01;
    public const FLAG_USER_VERIFIED    = 0x04;
    public const FLAG_BACKUP_ELIGIBLE  = 0x08;
    public const FLAG_BACKED_UP        = 0x10;
    public const FLAG_ATTESTED_DATA    = 0x40;

    private const COSE_KTY = 1;
    private const COSE_ALG = 3;
    private const COSE_CRV = -1;
    private const COSE_X   = -2;
    private const COSE_Y   = -3;

    private const KTY_EC2   = 2;
    private const ALG_ES256 = -7;
    private const CRV_P256  = 1;

    // --------------------------------------------------------------------------

    private OpenSSLAsymmetricKey $oKey;

    private string $sCredentialId;

    private string $sAaguid;

    // --------------------------------------------------------------------------

    public function __construct(?string $sCredentialId = null, ?string $sAaguid = null)
    {
        $oKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
        ]);

        if ($oKey === false) {
            throw new RuntimeException('Failed to generate a P-256 keypair: ' . openssl_error_string());
        }

        $this->oKey          = $oKey;
        $this->sCredentialId = $sCredentialId ?? random_bytes(32);
        $this->sAaguid       = $sAaguid ?? str_repeat("\x00", 16);
    }

    // --------------------------------------------------------------------------

    public function getCredentialId(): string
    {
        return $this->sCredentialId;
    }

    // --------------------------------------------------------------------------

    public function getCredentialIdBase64Url(): string
    {
        return self::base64UrlEncode($this->sCredentialId);
    }

    // --------------------------------------------------------------------------

    /**
     * The credential's public key, in the PEM form the library hands back
     */
    public function getPublicKeyPem(): string
    {
        $aDetails = openssl_pkey_get_details($this->oKey);

        if ($aDetails === false || !isset($aDetails['key'])) {
            throw new RuntimeException('Failed to read the public key.');
        }

        return $aDetails['key'];
    }

    // --------------------------------------------------------------------------

    /**
     * Builds a registration response, as PublicKeyCredential.toJSON() would render it
     *
     * @param array<string, mixed> $aOverrides
     *
     * @return array<string, mixed>
     */
    public function createRegistrationResponse(
        string $sChallengeB64,
        string $sOrigin,
        string $sRpId,
        int $iFlags = self::FLAG_USER_PRESENT | self::FLAG_ATTESTED_DATA,
        int $iSignCount = 0,
        array $aOverrides = []
    ): array {

        $sClientDataJson = $this->buildClientDataJson('webauthn.create', $sChallengeB64, $sOrigin, $aOverrides);

        $sAuthData = $this->buildAuthenticatorData($sRpId, $iFlags, $iSignCount, true);

        $sAttestationObject = Cbor::encodeMap([
            'fmt'      => 'none',
            'attStmt'  => Cbor::map([]),
            'authData' => Cbor::bytes($sAuthData),
        ]);

        return [
            'id'                      => $this->getCredentialIdBase64Url(),
            'rawId'                   => $this->getCredentialIdBase64Url(),
            'type'                    => 'public-key',
            'authenticatorAttachment' => 'platform',
            'clientExtensionResults'  => ['credProps' => ['rk' => true]],
            'response'                => [
                'clientDataJSON'    => self::base64UrlEncode($sClientDataJson),
                'attestationObject' => self::base64UrlEncode($sAttestationObject),
                'transports'        => ['internal', 'hybrid'],
            ],
        ];
    }

    // --------------------------------------------------------------------------

    /**
     * Builds an authentication response, signed with this fixture's private key
     *
     * @param array<string, mixed> $aOverrides
     *
     * @return array<string, mixed>
     */
    public function createAuthenticationResponse(
        string $sChallengeB64,
        string $sOrigin,
        string $sRpId,
        int $iFlags = self::FLAG_USER_PRESENT | self::FLAG_USER_VERIFIED,
        int $iSignCount = 1,
        ?string $sUserHandle = null,
        array $aOverrides = []
    ): array {

        $sClientDataJson = $this->buildClientDataJson('webauthn.get', $sChallengeB64, $sOrigin, $aOverrides);

        $sAuthData = $this->buildAuthenticatorData($sRpId, $iFlags, $iSignCount, false);

        //  Exactly what the spec signs: authenticatorData || SHA-256(clientDataJSON)
        $sSignature = $this->sign($sAuthData . hash('sha256', $sClientDataJson, true));

        return [
            'id'                      => $this->getCredentialIdBase64Url(),
            'rawId'                   => $this->getCredentialIdBase64Url(),
            'type'                    => 'public-key',
            'authenticatorAttachment' => 'platform',
            'clientExtensionResults'  => [],
            'response'                => [
                'clientDataJSON'    => self::base64UrlEncode($sClientDataJson),
                'authenticatorData' => self::base64UrlEncode($sAuthData),
                'signature'         => self::base64UrlEncode($sSignature),
                'userHandle'        => $sUserHandle,
            ],
        ];
    }

    // --------------------------------------------------------------------------

    /**
     * Signs data with the fixture's private key, as the authenticator would
     */
    public function sign(string $sData): string
    {
        $sSignature = '';

        if (!openssl_sign($sData, $sSignature, $this->oKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Failed to sign: ' . openssl_error_string());
        }

        return $sSignature;
    }

    // --------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $aOverrides
     */
    private function buildClientDataJson(
        string $sType,
        string $sChallengeB64,
        string $sOrigin,
        array $aOverrides = []
    ): string {

        return (string) json_encode(array_merge([
            'type'        => $sType,
            'challenge'   => $sChallengeB64,
            'origin'      => $sOrigin,
            'crossOrigin' => false,
        ], $aOverrides));
    }

    // --------------------------------------------------------------------------

    /**
     * Assembles authenticator data
     *
     * https://www.w3.org/TR/webauthn-2/#sctn-authenticator-data
     */
    private function buildAuthenticatorData(
        string $sRpId,
        int $iFlags,
        int $iSignCount,
        bool $bIncludeAttestedData
    ): string {

        $sOut = hash('sha256', $sRpId, true)
            . chr($iFlags)
            . pack('N', $iSignCount);

        if ($bIncludeAttestedData) {
            $sOut .= $this->sAaguid
                . pack('n', strlen($this->sCredentialId))
                . $this->sCredentialId
                . $this->buildCoseKey();
        }

        return $sOut;
    }

    // --------------------------------------------------------------------------

    /**
     * Encodes the public key as a COSE_Key, the form the authenticator reports
     */
    private function buildCoseKey(): string
    {
        $aDetails = openssl_pkey_get_details($this->oKey);

        if ($aDetails === false || !isset($aDetails['ec']['x'], $aDetails['ec']['y'])) {
            throw new RuntimeException('Failed to read the EC coordinates.');
        }

        return Cbor::encodeMap([
            self::COSE_KTY => self::KTY_EC2,
            self::COSE_ALG => self::ALG_ES256,
            self::COSE_CRV => self::CRV_P256,
            //  Coordinates are fixed width; OpenSSL drops leading zero bytes
            self::COSE_X   => Cbor::bytes(str_pad($aDetails['ec']['x'], 32, "\x00", STR_PAD_LEFT)),
            self::COSE_Y   => Cbor::bytes(str_pad($aDetails['ec']['y'], 32, "\x00", STR_PAD_LEFT)),
        ]);
    }

    // --------------------------------------------------------------------------

    public static function base64UrlEncode(string $sBinary): string
    {
        return rtrim(strtr(base64_encode($sBinary), '+/', '-_'), '=');
    }
}

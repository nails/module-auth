<?php

namespace Nails\Auth\Resource\User;

use Nails\Auth\Constants;
use Nails\Auth\Resource\User;
use Nails\Auth\Service\Passkey as Service;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\ModelException;
use Nails\Common\Resource;
use Nails\Factory;

/**
 * Class Passkey
 *
 * A WebAuthn credential registered against a user.
 *
 * @package Nails\Auth\Resource\User
 */
class Passkey extends Resource\Entity
{
    public ?int               $user_id            = null;
    public ?User              $user               = null;
    public string             $label              = '';
    public string             $credential_id      = '';
    public string             $public_key         = '';
    public int                $sign_count         = 0;
    public ?string            $aaguid             = null;
    public ?string            $attestation_format = null;
    public ?string            $transports         = null;
    public ?bool              $is_discoverable    = null;
    public bool               $is_backup_eligible = false;
    public bool               $is_backed_up       = false;
    public string             $user_handle        = '';
    public ?Resource\DateTime $last_used          = null;
    public ?string            $last_used_ip       = null;

    // --------------------------------------------------------------------------

    /**
     * Returns the user this passkey belongs to, fetching them if necessary
     *
     * @throws FactoryException
     * @throws ModelException
     */
    public function user(): ?User
    {
        if (empty($this->user) && !empty($this->user_id)) {

            /** @var \Nails\Auth\Model\User $oModel */
            $oModel = Factory::model('User', Constants::MODULE_SLUG);
            /** @var User|null $oUser */
            $oUser = $oModel->getById($this->user_id);

            $this->user = $oUser;
        }

        return $this->user;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the transports the authenticator reported at registration
     *
     * @return string[]
     */
    public function getTransports(): array
    {
        if (empty($this->transports)) {
            return [];
        }

        $aTransports = json_decode($this->transports, true);

        return is_array($aTransports)
            ? array_values(array_filter($aTransports, 'is_string'))
            : [];
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the credential's public key in PEM format
     */
    public function getPublicKeyPem(): string
    {
        return $this->public_key;
    }

    // --------------------------------------------------------------------------

    /**
     * Names the model of authenticator this passkey lives in, if it is a known one
     *
     * @throws FactoryException
     */
    public function getAuthenticatorName(): ?string
    {
        /** @var Service $oService */
        $oService = Factory::service('Passkey', Constants::MODULE_SLUG);

        return $oService->getAuthenticatorName($this->aaguid);
    }

}

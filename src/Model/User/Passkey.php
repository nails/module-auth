<?php

namespace Nails\Auth\Model\User;

use Nails\Auth\Constants;
use Nails\Auth\Resource;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\ModelException;
use Nails\Common\Helper\Model\Where;
use Nails\Common\Model\Base;
use Nails\Factory;

/**
 * Class Passkey
 *
 * @package Nails\Auth\Model\User
 */
class Passkey extends Base
{
    const TABLE             = NAILS_DB_PREFIX . 'user_passkey';
    const RESOURCE_NAME     = 'UserPasskey';
    const RESOURCE_PROVIDER = Constants::MODULE_SLUG;

    // --------------------------------------------------------------------------

    /**
     * @throws ModelException
     */
    public function __construct()
    {
        parent::__construct();
        $this
            ->hasOne('user', 'User', Constants::MODULE_SLUG);
    }

    // --------------------------------------------------------------------------

    /**
     * Returns all the passkeys registered by a user, oldest first
     *
     * @return Resource\User\Passkey[]
     * @throws FactoryException
     * @throws ModelException
     */
    public function getByUserId(int $iUserId): array
    {
        /** @var Resource\User\Passkey[] $aPasskeys */
        $aPasskeys = $this->getAll([
            new Where('user_id', $iUserId),
        ]);

        return $aPasskeys;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns a passkey by its base64url encoded credential ID
     *
     * @throws FactoryException
     * @throws ModelException
     */
    public function getByCredentialId(string $sCredentialId): ?Resource\User\Passkey
    {
        if ($sCredentialId === '') {
            return null;
        }

        /** @var Resource\User\Passkey|null $oPasskey */
        $oPasskey = $this->getAll([
            new Where('credential_id', $sCredentialId),
        ])[0] ?? null;

        return $oPasskey;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns how many passkeys a user has registered
     *
     * @throws FactoryException
     * @throws ModelException
     */
    public function countForUser(int $iUserId): int
    {
        return $this->countAll([
            new Where('user_id', $iUserId),
        ]);
    }

    // --------------------------------------------------------------------------

    /**
     * Records a successful use of a passkey
     *
     * @throws FactoryException
     * @throws ModelException
     */
    public function recordUse(int $iId, int $iSignCount, string $sIp): bool
    {
        /** @var \DateTime $oNow */
        $oNow = Factory::factory('DateTime');

        return $this->update($iId, [
            'sign_count'   => $iSignCount,
            'last_used'    => $oNow->format('Y-m-d H:i:s'),
            'last_used_ip' => $sIp ?: null,
        ]);
    }
}

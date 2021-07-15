<?php

/**
 * This model contains all methods for interacting with user groups.
 *
 * @package    Nails
 * @subpackage module-auth
 * @category   Model
 * @author     Nails Dev Team
 */

namespace Nails\Auth\Model\User;

use DateInterval;
use Nails\Auth\Constants;
use Nails\Common\Model\Base;
use Nails\Common\Service\Database;
use Nails\Config;
use Nails\Factory;

/**
 * Class AccessToken
 *
 * @package Nails\Auth\Model\User
 */
class AccessToken extends Base
{
    /**
     * The table this model represents
     *
     * @var string
     */
    const TABLE = NAILS_DB_PREFIX . 'user_auth_access_token';

    /**
     * The name of the resource to use (as passed to \Nails\Factory::resource())
     *
     * @var string
     */
    const RESOURCE_NAME = 'UserAccessToken';

    /**
     * The provider of the resource to use (as passed to \Nails\Factory::resource())
     *
     * @var string
     */
    const RESOURCE_PROVIDER = Constants::MODULE_SLUG;

    /**
     * Define how long the default expiration should be for access tokens; this should
     * be a value accepted by the DateInterval constructor.
     *
     * @var string
     */
    const TOKEN_EXPIRE = 'P6M';

    /**
     * Token template, defines the structure of the token, in a way acceptable to the
     * generateToken() function
     *
     * @var string
     */
    const TOKEN_MASK = 'AAAA-AAAA-AAAA-AAAA-AAAA-AAAA-AAAA-AAAA/AAA-AAAA-AAA';

    // --------------------------------------------------------------------------

    /**
     * Define a key => value array of scope handlers for checking whether a specified
     * user can request a certain scope. This should be an array of callables.
     *
     * @var array
     */
    protected $aScopeHandler = [];

    // --------------------------------------------------------------------------

    /**
     * Creates a new access token
     *
     * @param array $aData         The data to create the access token with
     * @param bool  $bReturnObject Whether to return the object, or the token
     *
     * @return mixed false on failure, stdClass on success
     */
    public function create(array $aData = [], $bReturnObject = false)
    {
        //  User ID is a required field
        if (empty($aData['user_id'])) {
            $this->setError('A user ID must be supplied.');
            return false;
        }

        // --------------------------------------------------------------------------

        //  Scope is a required field
        if (!empty($this->aScopeHandler) && empty($aData['scope'])) {
            $this->setError('A scope must be supplied.');
            return false;
        }

        // --------------------------------------------------------------------------

        //  If not specified, generate an expiration date based on the defaults
        if (!isset($aData['expires']) && !empty(static::TOKEN_EXPIRE)) {
            /** @var \DateTime $oNow */
            $oNow = Factory::factory('DateTime');
            $oNow->add(new DateInterval(static::TOKEN_EXPIRE));
            $aData['expires'] = $oNow->format('Y-m-d H:i:s');
        }

        // --------------------------------------------------------------------------

        if (!empty($this->aScopeHandler)) {

            /**
             * For each scope requested, test the user is permitted to request it.
             */
            $aData['scope'] = explode(',', $aData['scope']);
            $aData['scope'] = array_map('trim', $aData['scope']);
            $aData['scope'] = array_unique($aData['scope']);

            asort($aData['scope']);

            foreach ($aData['scope'] as $sScope) {

                if (empty($this->aScopeHandler[$sScope])) {

                    $this->setError(sprintf(
                        '"%s" is not a valid token scope.',
                        $sScope
                    ));
                    return false;

                } elseif (!s_callable($this->aScopeHandler[$sScope])) {

                    $this->setError(sprintf(
                        'Handler for "%s" is not a valid token scope callback.',
                        $sScope
                    ));
                    return false;

                } elseif (!call_user_func($this->aScopeHandler[$sScope], $aData['user_id'], $sScope)) {

                    $this->setError(sprintf(
                        'No permission to request a token with scope "%s".',
                        $sScope
                    ));
                    return false;
                }
            }

            $aData['scope'] = implode(',', $aData['scope']);
        }

        // --------------------------------------------------------------------------

        //  Generate a new token
        /** @var Database $oDb */
        $oDb = Factory::service('Database');
        do {

            $sToken         = strtoupper(generateToken(static::TOKEN_MASK));
            $aData['token'] = $this->hashToken($sToken);
            $oDb->where('token', $aData['token']);

        } while ($oDb->count_all_results($this->getTableName()));

        // --------------------------------------------------------------------------

        $mResult = parent::create($aData, $bReturnObject);
        if (!$mResult) {
            return false;

        } elseif ($bReturnObject) {
            //  Overwrite the encoded token with the unencoded version
            $mResult->token = $sToken;
        }

        return $mResult;
    }

    // --------------------------------------------------------------------------

    /**
     * Hashes the token string
     *
     * @param string $sToken The token to hash
     *
     * @return string
     */
    protected function hashToken(string $sToken): string
    {
        return hash('sha256', $sToken . Config::get('PRIVATE_KEY'));
    }

    // --------------------------------------------------------------------------

    /**
     * Revoke an access token for a user
     *
     * @param integer $iUserId The ID of the user the token belongs to
     * @param mixed   $mToken  The token object, or a token ID
     *
     * @return bool
     */
    public function revoke($iUserId, $mToken)
    {
        if (is_string($mToken)) {
            $oToken = $this->getByToken($mToken);
        } else {
            $oToken = $mToken;
        }

        if ($oToken) {

            if ($oToken->user_id === $iUserId) {
                return $this->delete($oToken->id);
            } else {
                $this->setError('Not authorised to revoke that token.');
                return false;
            }

        } else {
            return false;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Prevent update of access tokens
     *
     * @param int   $iId   The accessToken's ID
     * @param array $aData Data to update the access token with
     *
     * @return bool
     */
    public function update($iId, array $aData = []): bool
    {
        $this->setError('Access tokens cannot be amended once created.');
        return false;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns a token by it's token
     *
     * @param string $sToken The token to return
     * @param array  $aData  Data to pass to getAll()
     *
     * @return mixed         false on failure, stdClass on success
     */
    public function getByToken($sToken, array $aData = [])
    {
        return parent::getByToken($this->hashToken($sToken), $aData);
    }

    // --------------------------------------------------------------------------

    /**
     * Returns a token by it's token, but only if valid (i.e not expired)
     *
     * @param string $sToken The token to return
     *
     * @return mixed         false on failure, stdClass on success
     */
    public function getByValidToken($sToken, array $aData = [])
    {
        if (!isset($aData['where'])) {
            $aData['where'] = [];
        }

        $aData['where'][] = '(expires IS NULL OR expires > NOW())';

        return $this->getByToken($sToken, $aData);
    }

    // --------------------------------------------------------------------------

    /**
     * Determines whether a token has a given scope
     *
     * @param mixed  $mToken The token object, or a token ID
     * @param string $sScope The scope to check
     *
     * @return bool
     */
    public function hasScope($mToken, $sScope): bool
    {
        if (is_numeric($mToken)) {
            /** @var \Nails\Auth\Resource\User\AccessToken $oToken */
            $oToken = $this->getById($mToken);

        } elseif ($mToken instanceof \Nails\Auth\Resource\User\AccessToken) {
            $oToken = $mToken;

        } else {
            return false;
        }

        return $oToken->hasScope($sScope);
    }
}

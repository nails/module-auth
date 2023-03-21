<?php

/**
 * This class provides social signon capability and an abstraction to social media APIs
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    Library
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Auth\Service;

use Hybridauth\Adapter\AdapterInterface;
use Hybridauth\Hybridauth;
use Nails\Auth\Constants;
use Nails\Auth\Model\User;
use Nails\Common\Exception\NailsException;
use Nails\Common\Service\Database;
use Nails\Common\Service\ErrorHandler;
use Nails\Common\Service\Logger;
use Nails\Common\Service\Session;
use Nails\Common\Traits\Caching;
use Nails\Common\Traits\ErrorHandling;
use Nails\Config;
use Nails\Environment;
use Nails\Factory;

/**
 * Class SocialSignOn
 *
 * @package Nails\Auth\Service
 */
class SocialSignOn
{
    use ErrorHandling;
    use Caching;

    // --------------------------------------------------------------------------

    const TABLE = NAILS_DB_PREFIX . 'user_social';

    // --------------------------------------------------------------------------

    /**
     * @var Database
     */
    protected $oDb;

    /**
     * @var User
     */
    protected $oUserModel;

    /**
     * @var array
     */
    protected $aProviders;

    /**
     * @var Hybridauth
     */
    protected $oHybridAuth;

    // --------------------------------------------------------------------------

    /**
     * SocialSignOn constructor.
     */
    public function __construct()
    {
        /** @var \CI_Controller $oCi */
        $oCi = get_instance();
        /** @var Session $oSession */
        $oSession = Factory::service('Session');
        /** @var Database oDb */
        $this->oDb = Factory::service('Database');
        /** @var User oUserModel */
        $this->oUserModel = Factory::model('User', Constants::MODULE_SLUG);

        // --------------------------------------------------------------------------

        /**
         * Pablo (2019-07-17):
         * This hack forces Sessions to fully build, so that when HybridAuth attempts to create/join the
         * session there is one to join – otherwise we get conflicts.
         */
        $oSession
            ->setUserData('temp')
            ->unsetUserData('temp');

        // --------------------------------------------------------------------------

        $this->aProviders = ['all' => [], 'enabled' => [], 'disabled' => []];

        //  Set up Providers
        $oCi->config->load('auth/auth');
        $aConfig = $oCi->config->item('auth_social_signon_providers');

        if (is_array($aConfig) && !empty($aConfig)) {

            foreach ($aConfig as $aProvider) {

                $this->aProviders['all'][strtolower($aProvider['slug'])] = $aProvider;

                if (appSetting('auth_social_signon_' . $aProvider['slug'] . '_enabled', 'auth')) {
                    $this->aProviders['enabled'][strtolower($aProvider['slug'])] = $aProvider;
                } else {
                    $this->aProviders['disabled'][strtolower($aProvider['slug'])] = $aProvider;
                }
            }

        } else {
            throw new NailsException(
                'No providers for HybridAuth have been specified or the configuration array is empty.'
            );
        }

        // --------------------------------------------------------------------------

        //  Set up Hybrid Auth
        /** @var Logger $oLogger */
        $oLogger = Factory::service('Logger');

        $aConfig = [
            'callback'   => current_url(),
            'providers'  => [],
            'debug_mode' => Environment::not(Environment::ENV_PROD),
            'debug_file' => $oLogger->getDir() . $oLogger->getFile(),
        ];

        foreach ($this->aProviders['enabled'] as $aProvider) {

            $aTemp            = [];
            $aTemp['enabled'] = true;

            if ($aProvider['fields']) {

                foreach ($aProvider['fields'] as $key => $label) {

                    if (is_array($label) && !isset($label['label'])) {

                        $aTemp[$key] = [];

                        foreach ($label as $key1 => $label1) {
                            $aTemp[$key][$key1] = appSetting(
                                'auth_social_signon_' . $aProvider['slug'] . '_' . $key . '_' . $key1,
                                'auth'
                            );
                        }

                    } else {
                        $aTemp[$key] = appSetting('auth_social_signon_' . $aProvider['slug'] . '_' . $key, 'auth');
                    }
                }

                if (!empty($aProvider['wrapper'])) {
                    $aTemp['wrapper'] = $aProvider['wrapper'];
                }
            }

            $aConfig['providers'][$aProvider['class']] = $aTemp;
        }

        try {

            $this->oHybridAuth = new Hybridauth($aConfig);

        } catch (\Exception $e) {

            /**
             * An exception occurred during instantiation, this is probably a result
             * of the user denying the authentication request. If we reinit the Hybridauth
             * things work again, but it's as if nothing ever happened and the user may be
             * redirected back to the authentication screen (which is annoying). The
             * alternative is to bail out with an error; this informs the user that something
             * *did* happened but results in an unfriendly error screen and potential drop off.
             */

            switch ($oCi->config->item('auth_social_signon_init_fail_behaviour')) {

                case 'reinit':
                    $this->oHybridAuth = new Hybridauth($aConfig);
                    break;

                case 'error':
                default:
                    ErrorHandler::halt($e->getMessage());
                    break;
            }
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Determines whether social sign on is enabled or not
     *
     * @return boolean
     */
    public function isEnabled()
    {
        return appSetting('auth_social_signon_enabled', 'auth') && $this->getProviders('ENABLED');
    }

    // --------------------------------------------------------------------------

    /**
     * Returns a list of providers, optionally filtered by availability
     *
     * @param string $status The filter to apply
     *
     * @return array
     */
    public function getProviders($status = null)
    {
        if ($status == 'ENABLED') {
            return $this->aProviders['enabled'];
        } elseif ($status == 'DISABLED') {
            return $this->aProviders['disabled'];
        } else {
            return $this->aProviders['all'];
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the details of a particular provider
     *
     * @param string $provider The provider to return
     *
     * @return mixed            Array on success, false on failure
     */
    public function getProvider($provider)
    {
        if (isset($this->aProviders['all'][strtolower($provider)])) {
            return $this->aProviders['all'][strtolower($provider)];
        } else {
            return false;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the correct casing for a provider
     *
     * @param string $sProvider The provider to return
     *
     * @return mixed            String on success, null on failure
     */
    protected function getProviderClass($sProvider)
    {
        $aProviders = $this->getProviders();
        return isset($aProviders[strtolower($sProvider)]['class']) ? $aProviders[strtolower($sProvider)]['class'] : null;
    }

    // --------------------------------------------------------------------------

    /**
     * Determines whether a provider is valid and enabled
     *
     * @param string $sProvider The provider to check
     *
     * @return boolean
     */
    public function isValidProvider($sProvider)
    {
        return !empty($this->aProviders['enabled'][strtolower($sProvider)]);
    }

    // --------------------------------------------------------------------------

    /**
     * Authenticates a user using Hybrid Auth's authenticate method
     *
     * @param string $sProvider The provider to authenticate against
     *
     * @return AdapterInterface|false
     */
    public function authenticate($sProvider)
    {
        try {
            $sProvider = $this->getProviderClass($sProvider);
            return $this->oHybridAuth->authenticate($sProvider);
        } catch (\Exception $e) {
            $this->setError('Provider Error: ' . $e->getMessage());
            return false;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the user's profile for a particular provider.
     *
     * @param string $sProvider The name of the provider.
     *
     * @return \Hybridauth\User\Profile
     */
    public function getUserProfile($sProvider)
    {
        $oAdapter = $this->authenticate($sProvider);

        try {
            return $oAdapter->getUserProfile();
        } catch (\Exception $e) {
            $this->setError('Provider Error: ' . $e->getMessage());
            return false;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the user's contacts for a particular provider.
     *
     * @param string $sProvider The name of the provider.
     *
     * @return \Hybridauth\User\Contact
     */
    public function getUserContacts($sProvider)
    {
        $oAdapter = $this->authenticate($sProvider);

        try {
            return $oAdapter->getUserContacts();
        } catch (\Exception $e) {
            $this->setError('Provider Error: ' . $e->getMessage());
            return false;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the user's pages for a particular provider.
     *
     * @param string $sProvider The name of the provider.
     *
     * @return array
     */
    public function getUserPages($sProvider)
    {
        $oAdapter = $this->authenticate($sProvider);

        try {
            return $oAdapter->getUserPages();
        } catch (\Exception $e) {
            $this->setError('Provider Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Returns the user's activity for a particular provider.
     *
     * @param string $sProvider The name of the provider.
     *
     * @return array
     */
    public function getUserActivity($sProvider)
    {
        $oAdapter = $this->authenticate($sProvider);

        try {
            return $oAdapter->getUserActivity();
        } catch (\Exception $e) {
            $this->setError('Provider Error: ' . $e->getMessage());
            return false;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Logs out all providers
     *
     * @return void
     */
    public function logout()
    {
        $this->oHybridAuth->disconnectAllAdapters();
    }

    // --------------------------------------------------------------------------

    /**
     * Fetches a local user profile via a provider and provider ID
     *
     * @param string $provider   The provider to use
     * @param string $identifier The provider's user ID
     *
     * @return mixed               stdClass on success, false on failure
     */
    public function getUserByProviderId($provider, $identifier)
    {
        $this->oDb->select('user_id');
        $this->oDb->where('provider', $provider);
        $this->oDb->where('identifier', $identifier);

        $oUser = $this->oDb->get(static::TABLE)->row();

        if (empty($oUser)) {
            return false;
        }

        return $this->oUserModel->getById($oUser->user_id);
    }

    // --------------------------------------------------------------------------

    /**
     * Saves the social session data to the user's account
     *
     * @param mixed $user_id   The User's ID (if null, then the active user ID is used)
     * @param array $providers The providers to save
     *
     * @return boolean
     */
    public function saveSession($user_id = null, array $providers = [])
    {
        if (empty($user_id)) {
            $user_id = activeUser('id');
        }

        if (empty($user_id)) {
            $this->setError('Must specify which user ID\'s session to save.');
            return false;
        }

        $oUser = $this->oUserModel->getById($user_id);

        if (!$oUser) {
            $this->setError('Invalid User ID');
            return false;
        }

        // --------------------------------------------------------------------------

        $aSave            = [];
        $aProviderClasses = arrayExtractProperty($providers, 'class');
        $aAdapters        = $this->oHybridAuth->getConnectedAdapters();
        foreach ($aAdapters as $sProvider => $oAdapter) {

            if (empty($providers) || in_array($sProvider, $aProviderClasses)) {
                $aToken            = $oAdapter->getAccessToken();
                $aSave[$sProvider] = [
                    'identifier' => $oAdapter->getUserProfile()->identifier,
                    'token'      => [
                        'token'      => getFromArray('access_token', $aToken),
                        'expires_at' => getFromArray('expires_at', $aToken),
                    ],
                ];
            }
        }

        // --------------------------------------------------------------------------

        /**
         * Get the user's existing data, so we know whether we're inserting or
         * updating their data
         */

        $this->oDb->where('user_id', $user_id);
        $aExisting = $this->oDb->get(static::TABLE)->result();
        $aExists   = [];

        foreach ($aExisting as $existing) {
            $aExists[$existing->provider] = $existing->id;
        }

        // --------------------------------------------------------------------------

        //  Save data
        $this->oDb->transaction()->start();

        $oNow = Factory::factory('DateTime');

        foreach ($aSave as $sProvider => $aSaveData) {

            if (isset($aExists[$sProvider])) {

                //  Update
                $aData = [
                    'identifier'   => $aSaveData['identifier'],
                    'session_data' => json_encode($aSaveData['token']),
                    'modified'     => $oNow->format('Y-m-d H:i:s'),
                ];

                $this->oDb->set($aData);
                $this->oDb->where('id', $aExists[$sProvider]);
                $this->oDb->update(static::TABLE);

            } else {

                //  Insert
                $aData = [
                    'user_id'      => (int) $user_id,
                    'provider'     => $sProvider,
                    'identifier'   => $aSaveData['identifier'],
                    'session_data' => json_encode($aSaveData['token']),
                    'created'      => $oNow->format('Y-m-d H:i:s'),
                    'modified'     => $oNow->format('Y-m-d H:i:s'),
                ];

                $this->oDb->set($aData);
                $this->oDb->insert(static::TABLE);
            }
        }

        // --------------------------------------------------------------------------

        if ($this->oDb->transaction()->status() === false) {
            $this->oDb->transaction()->rollback();
            return false;
        } else {
            $this->oDb->transaction()->commit();
            return true;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Restores a user's social session
     *
     * @param mixed $user_id The User's ID (if null, then the active user ID is used)
     *
     * @return $this
     */
    public function restoreSession($user_id = null)
    {
        if (empty($user_id)) {
            $user_id = activeUser('id');
        }

        if (empty($user_id)) {
            $this->setError('Must specify which user ID\'s session to restore.');
            return false;
        }

        $oUser = $this->oUserModel->getById($user_id);

        if (!$oUser) {
            $this->setError('Invalid User ID');
            return false;
        }

        // --------------------------------------------------------------------------

        //  Clean slate
        $this->logout();

        // --------------------------------------------------------------------------

        $this->oDb->where('user_id', $oUser->id);
        $aSessions = $this->oDb->get(static::TABLE)->result();
        $aRestore  = [];

        foreach ($aSessions as $oSession) {
            $aSessionData = json_decode((string) $oSession->session_data, JSON_OBJECT_AS_ARRAY);
            $oAdapter     = $this->oHybridAuth->getAdapter($oSession->provider);
            $oAdapter->setAccessToken(['access_token' => getFromArray('token', $aSessionData)]);
        }

        return $this;
    }

    // --------------------------------------------------------------------------

    /**
     * Determines whether the active user is connected with $provider
     *
     * @param string $sProvider the provider to test for
     *
     * @return boolean
     */
    public function isConnectedWith($sProvider)
    {
        $sProvider = $this->getProviderClass($sProvider);
        return $this->oHybridAuth->isConnectedWith($sProvider);
    }

    // --------------------------------------------------------------------------

    /**
     * Returns an array of connected providers (for the active user)
     *
     * @return array
     */
    public function getConnectedProviders()
    {
        return $this->oHybridAuth->getConnectedProviders();
    }

    // --------------------------------------------------------------------------

    /**
     * Abstraction to a provider's API
     *
     * @param string $sProvider The provider whose API you wish to call
     * @param string $call      The API call
     *
     * @return mixed
     */
    public function api($sProvider, $sUrl = '', $sMethod = 'GET', $aParameters = [], $aHeaders = [])
    {
        if (!$this->isConnectedWith($sProvider)) {
            $this->setError('Not connected with provider "' . $sProvider . '"');
            return false;
        }

        try {

            $sProvider = $this->getProviderClass($sProvider);
            $oProvider = $this->oHybridAuth->getAdapter($sProvider);
            return $oProvider->apiRequest(
                $sUrl,
                $sMethod,
                $aParameters,
                $aHeaders
            );

        } catch (\Exception $e) {
            $this->setError('Provider Error: ' . $e->getMessage());
            return false;
        }
    }
}

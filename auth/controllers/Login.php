<?php

/**
 * User login facility
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    Controller
 * @author      Nails Dev Team
 * @link
 */

use Nails\Auth\Constants;
use Nails\Auth\Controller\Base;
use Nails\Auth\Exception\AuthException;
use Nails\Auth\Exception\Login\NoUserException;
use Nails\Auth\Exception\Login\RequiresMfaException;
use Nails\Auth\Exception\Login\RequiresPasswordResetExpiredException;
use Nails\Auth\Exception\Login\RequiresPasswordResetTempException;
use Nails\Auth\Model\User\Group;
use Nails\Auth\Model\User\Password;
use Nails\Auth\Resource;
use Nails\Auth\Service\Authentication;
use Nails\Auth\Service\SocialSignOn;
use Nails\Cdn\Service\Cdn;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\ValidationException;
use Nails\Common\Service\Config;
use Nails\Common\Service\FileCache;
use Nails\Common\Service\FormValidation;
use Nails\Common\Service\Input;
use Nails\Common\Service\Logger;
use Nails\Common\Service\Output;
use Nails\Common\Service\Uri;
use Nails\Factory;

/**
 * Class Login
 */
class Login extends Base
{
    /**
     * Login constructor.
     *
     * @throws FactoryException
     */
    public function __construct()
    {
        parent::__construct();

        /** @var Input $oInput */
        $oInput = Factory::service('Input');

        $sReturnTo = $oInput->get('return_to');

        if ($sReturnTo) {

            $sReturnTo = preg_match('#^https?:/#', $sReturnTo) ? $sReturnTo : siteUrl($sReturnTo);
            $aReturnTo = parse_url($sReturnTo);

            //  urlencode the query if there is one
            if (!empty($aReturnTo['query'])) {
                //  Break it apart and glue it together (urlencoded)
                parse_str($aReturnTo['query'], $aQuery);
                $aReturnTo['query'] = http_build_query($aQuery);
            }

            if (empty($aReturnTo['host']) && siteUrl() === '/') {
                $this->data['return_to'] = [
                    !empty($aReturnTo['path']) ? $aReturnTo['path'] : '',
                    !empty($aReturnTo['query']) ? '?' . $aReturnTo['query'] : '',
                ];
            } else {
                $this->data['return_to'] = [
                    !empty($aReturnTo['scheme']) ? $aReturnTo['scheme'] . '://' : 'http://',
                    !empty($aReturnTo['host']) ? $aReturnTo['host'] : siteUrl(),
                    !empty($aReturnTo['port']) ? ':' . $aReturnTo['port'] : '',
                    !empty($aReturnTo['path']) ? $aReturnTo['path'] : '',
                    !empty($aReturnTo['query']) ? '?' . $aReturnTo['query'] : '',
                ];
            }

        } else {
            $this->data['return_to'] = [];
        }

        $this->data['return_to'] = implode('', $this->data['return_to']);

        // --------------------------------------------------------------------------

        //  Specify a default title for this page
        $this->oMetaData
            ->setTitles([lang('auth_title_login')])
            ->setDescription('Log in to your ' . \Nails\Config::get('APP_NAME') . ' account');
    }

    // --------------------------------------------------------------------------

    /**
     * Validate data and log the user in.
     *
     * @throws FactoryException
     **/
    public function index()
    {
        if (isLoggedIn()) {
            redirect($this->data['return_to'] ?: activeUser()->group()->default_homepage);
        }

        // --------------------------------------------------------------------------

        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        /** @var \App\Auth\Model\User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);
        /** @var FormValidation $oFormValidation */
        $oFormValidation = Factory::service('FormValidation');
        /** @var Authentication $oAuthService */
        $oAuthService = Factory::service('Authentication', Constants::MODULE_SLUG);
        /** @var SocialSignOn $oSocial */
        $oSocial = Factory::service('SocialSignOn', Constants::MODULE_SLUG);
        /** @var \Nails\Captcha\Service\Captcha $oCaptchaService */
        $oCaptchaService = Factory::service('Captcha', Nails\Captcha\Constants::MODULE_SLUG);

        // --------------------------------------------------------------------------

        if ($oInput->post()) {

            try {

                $oFormValidation
                    ->buildValidator([
                        'identifier'           => array_values(array_filter([
                            \Nails\Config::get('APP_NATIVE_LOGIN_USING') === 'EMAIL' ? [
                                $oFormValidation::RULE_REQUIRED,
                                $oFormValidation::RULE_VALID_EMAIL,
                            ] : null,
                            \Nails\Config::get('APP_NATIVE_LOGIN_USING') === 'USERNAME' ? [$oFormValidation::RULE_REQUIRED] : null,
                            \Nails\Config::get('APP_NATIVE_LOGIN_USING') === 'BOTH' ? [$oFormValidation::RULE_REQUIRED] : null,
                        ]))[0],
                        'password'             => [$oFormValidation::RULE_REQUIRED],
                        'remember'             => [],
                        'g-recaptcha-response' => [
                            function ($sToken) use ($oCaptchaService) {
                                if (appSetting('user_login_captcha_enabled', 'auth')) {
                                    if (!$oCaptchaService->verify($sToken)) {
                                        throw new ValidationException(
                                            lang('auth_login_captcha_fail')
                                        );
                                    }
                                }
                            },
                        ],
                    ])
                    ->run();

                $bRemember = (bool) $oInput->post('remember');

                $oUser = $oUserModel->getByIdentifier(trim($oInput->post('identifier')));

                $oAuthService->loginWithCredentials(
                    $oUser,
                    $oInput->post('password'),
                    $bRemember
                );

                $this->handleLogin($oUser, $bRemember);

            } catch (ValidationException $e) {
                $this->oUserFeedback->error($e->getMessage());

            } catch (NoUserException $e) {
                $this->oUserFeedback->error($e->getMessage());

            } catch (RequiresMfaException $e) {
                $this->handleMfa($oUser);

            } catch (RequiresPasswordResetTempException $e) {
                $this->handlePasswordReset($oUser, $bRemember, 'TEMP');

            } catch (RequiresPasswordResetExpiredException $e) {
                $this->handlePasswordReset($oUser, $bRemember, 'EXPIRED');

            } catch (AuthException $e) {
                $this->oUserFeedback->error($e->getMessage());
            }
        }

        // --------------------------------------------------------------------------

        $this->data['social_signon_enabled']   = $oSocial->isEnabled();
        $this->data['social_signon_providers'] = $oSocial->getProviders('ENABLED');

        // --------------------------------------------------------------------------

        $this->loadStyles(\Nails\Config::get('NAILS_APP_PATH') . 'application/modules/auth/views/login/form.php');

        //  Re-boot captcha as loadStyles clears everything
        if (appSetting('user_login_captcha_enabled', 'auth')) {
            $oCaptchaService->boot();
        }

        Factory::service('View')
            ->load([
                'structure/header/blank',
                'auth/login/form',
                'structure/footer/blank',
            ]);
    }

    // --------------------------------------------------------------------------

    /**
     * Handles the next stage of login after successfully authenticating
     *
     * @param Resource\User $oUser     The user who is logging in
     * @param bool          $bRemember Whether to set the rememberMe cookie or not
     * @param string        $sProvider Which provider authenticated the login
     *
     * @throws FactoryException
     */
    protected function handleLogin(Resource\User $oUser, bool $bRemember = false, string $sProvider = 'native'): void
    {
        /** @var Config $oConfig */
        $oConfig = Factory::service('Config');
        /** @var Password $oUserPasswordModel */
        $oUserPasswordModel = Factory::model('UserPassword', Constants::MODULE_SLUG);
        /** @var Authentication $oAuthService */
        $oAuthService = Factory::service('Authentication', Constants::MODULE_SLUG);

        if (!empty($oUser->temp_pw)) {

            $this->handlePasswordReset($oUser, $bRemember, 'TEMP');

        } elseif ($oUserPasswordModel->isExpired($oUser->id)) {

            $this->handlePasswordReset($oUser, $bRemember, 'EXPIRED');

        } elseif ($oConfig->item('authTwoFactorMode')) {

            $this->handleMfa($oUser);

        } else {

            //  Finally! Send this user on their merry way...

            if ($oUser->last_login) {

                $lastLogin = $oConfig->item('authShowNicetimeOnLogin') ? niceTime(strtotime($oUser->last_login)) : toUserDatetime($oUser->last_login);

                if ($oConfig->item('authShowLastIpOnLogin')) {
                    $this->oUserFeedback->success(lang('auth_login_ok_welcome_with_ip', [
                        $oUser->first_name,
                        $lastLogin,
                        $oUser->last_ip,
                    ]));
                } else {
                    $this->oUserFeedback->success(lang('auth_login_ok_welcome', [$oUser->first_name, $lastLogin]));
                }

            } else {
                $this->oUserFeedback->success(lang('auth_login_ok_welcome_notime', [$oUser->first_name]));
            }

            $sRedirectUrl = $this->data['return_to'] ? $this->data['return_to'] : $oUser->group_homepage;

            // --------------------------------------------------------------------------

            //  Generate an event for this log in
            createUserEvent('did_log_in', ['provider' => $sProvider]);

            // --------------------------------------------------------------------------

            redirect($sRedirectUrl);
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Handle MFA redirect
     *
     * @param Resource\User $oUser     The user who requires MFA
     * @param bool          $bRemember Whether to set the rememberMe cookie or not
     *
     * @throws AuthException
     * @throws FactoryException
     *
     * @todo (Pablo - 2019-12-10) - Verify this still works
     */
    protected function handleMfa(Resource\User $oUser, bool $bRemember = false): void
    {
        /** @var Authentication $oAuthService */
        $oAuthService = Factory::service('Authentication', Constants::MODULE_SLUG);
        /** @var Config $oConfig */
        $oConfig = Factory::service('Config');

        $aTwoFactorToken = $oAuthService->mfaTokenGenerate($oUser->id);

        if (!$aTwoFactorToken) {
            throw new AuthException(
                'A user tried to login and the system failed to generate a two-factor auth token.'
            );
        }

        //  Is there any query data?
        $aQuery = array_filter([
            'return_to' => $this->data['return_to'] ?: null,
            'remember'  => $bRemember,
        ]);

        $sQuery = !empty($aQuery) ? '?' . http_build_query($aQuery) : '';

        //  Where we sending the user?
        switch ($oConfig->item('authTwoFactorMode')) {

            case 'QUESTION':
                $sController = 'mfa/question';
                break;

            case 'DEVICE':
                $sController = 'mfa/device';
                break;

            default:
                throw new AuthException('"' . $oConfig->item('authTwoFactorMode') . '" is not a valid MFA Mode');
                break;
        }

        //  Compile the URL
        $aUrl = [
            'auth',
            $sController,
            $oUser->id,
            $aTwoFactorToken['salt'],
            $aTwoFactorToken['token'],
        ];

        //  Login was successful, redirect to the appropriate MFA page
        redirect(implode('/', $aUrl) . $sQuery);
    }

    // --------------------------------------------------------------------------

    /**
     * @param Resource\User $oUser     The user who is resetting their password
     * @param bool          $bRemember Whether to set the rememberMe cookie or not
     * @param string        $sReason   The reason for the reset
     *
     * @throws FactoryException
     */
    protected function handlePasswordReset(Resource\User $oUser, bool $bRemember = false, string $sReason = ''): void
    {
        $aQuery = array_filter([
            'return_to' => $this->data['return_to'] ?: null,
            'remember'  => $bRemember,
            'reason'    => $sReason,
        ]);

        $aQuery = $aQuery ? '?' . http_build_query($aQuery) : '';

        /**
         * Log the user out and remove the 'remember me' cookie - if we don't do this
         * then the password reset page will see a logged in user and error.
         */

        /** @var Authentication $oAuthService */
        $oAuthService = Factory::service('Authentication', Constants::MODULE_SLUG);
        $oAuthService->logout();

        /** @var Password $oPasswordModel */
        $oPasswordModel = Factory::model('UserPassword', Constants::MODULE_SLUG);

        redirect($oPasswordModel::resetUrl($oUser) . $aQuery);
    }

    // --------------------------------------------------------------------------

    /**
     * Log a user in using hashes of their user ID and password; easy way of
     * automatically logging a user in from the likes of an email.
     *
     * @throws AuthException
     */
    public function with_hashes(): void
    {
        /** @var Uri $oUri */
        $oUri = Factory::service('Uri');
        /** @var Config $oConfig */
        $oConfig = Factory::service('Config');

        if (!$oConfig->item('authEnableHashedLogin')) {
            show404();
        }

        // --------------------------------------------------------------------------

        $hash['id'] = $oUri->segment(4);
        $hash['pw'] = $oUri->segment(5);

        if (empty($hash['id']) || empty($hash['pw'])) {
            throw new AuthException(lang('auth_with_hashes_incomplete_creds'), 1);
        }

        // --------------------------------------------------------------------------

        /**
         * If the user is already logged in we need to check to see if we check to see if they are
         * attempting to login as themselves, if so we redirect, otherwise we log them out and try
         * again using the hashes.
         */

        if (isLoggedIn()) {

            if (md5(activeUser('id')) == $hash['id']) {

                //  We are attempting to log in as who we're already logged in as, redirect normally
                if ($this->data['return_to']) {
                    redirect($this->data['return_to']);

                } else {
                    redirect(activeUser()->group()->default_homepage);
                }

            } else {

                //  We are logging in as someone else, log the current user out and try again
                /** @var Authentication $oAuthService */
                $oAuthService = Factory::service('Authentication', Constants::MODULE_SLUG);
                $oAuthService->logout();

                redirect(preg_replace('/^\//', '', $_SERVER['REQUEST_URI']));
            }
        }

        // --------------------------------------------------------------------------

        /**
         * The active user is a guest, we must look up the hashed user and log them in
         * if all is ok otherwise we report an error.
         */

        /** @var \Nails\Auth\Model\User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);
        $oUser      = $oUserModel->getByHashes($hash['id'], $hash['pw']);

        // --------------------------------------------------------------------------

        if ($oUser) {

            //  User was verified, log the user in
            $oUserModel->setLoginData($oUser->id);

            // --------------------------------------------------------------------------

            //  Say hello
            if ($oUser->last_login) {

                $lastLogin = $oConfig->item('authShowNicetimeOnLogin') ? niceTime(strtotime($oUser->last_login)) : toUserDatetime($oUser->last_login);

                if ($oConfig->item('authShowLastIpOnLogin')) {
                    $this->oUserFeedback->success(lang('auth_login_ok_welcome_with_ip', [
                        $oUser->first_name,
                        $lastLogin,
                        $oUser->last_ip,
                    ]));
                } else {
                    $this->oUserFeedback->success(lang('auth_login_ok_welcome', [$oUser->first_name, $oUser->last_login]));
                }

            } else {
                $this->oUserFeedback->success(lang('auth_login_ok_welcome_notime', [$oUser->first_name]));
            }

            // --------------------------------------------------------------------------

            //  Update their last login
            $oUserModel->updateLastLogin($oUser->id);

            // --------------------------------------------------------------------------

            //  Redirect user
            if ($this->data['return_to'] != siteUrl()) {

                //  We have somewhere we want to go
                redirect($this->data['return_to']);

            } else {

                //  Nowhere to go? Send them to their default homepage
                redirect($oUser->group_homepage);
            }

        } else {

            //  Bad lookup, invalid hash.
            $this->oUserFeedback->error(lang('auth_with_hashes_autologin_fail'));
            redirect($this->data['return_to']);
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Handle login/registration via social provider
     *
     * @param string $sProvider The provider to use
     *
     * @throws FactoryException
     *
     * @todo (Pablo - 2019-12-10) - Verify this still works
     */
    protected function socialSignon($sProvider): void
    {
        /** @var Uri $oUri */
        $oUri = Factory::service('Uri');
        /** @var Logger $oLogger */
        $oLogger = Factory::service('Logger');
        /** @var Session $oSession */
        $oSession = Factory::service('Session');
        /** @var SocialSignOn $oSocial */
        $oSocial = Factory::service('SocialSignOn', Constants::MODULE_SLUG);
        /** @var \Nails\Auth\Model\User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);

        //  Fetch the user's social profile and, if one exists, the local profile.
        try {

            //  Get the adapter, HybridAuth will handle the redirect
            $oAdapter = $oSocial->authenticate($sProvider);
            if (empty($oAdapter)) {
                throw new AuthException('Failed to authenticate with provider. ' . $oSocial->lastError());
            }

            $provider = $oSocial->getProvider($sProvider);
            if (empty($provider)) {
                throw new AuthException('Failed to get provider adapter. ' . $oSocial->lastError());
            }

            $socialUser = $oAdapter->getUserProfile();

        } catch (Exception $e) {

            //  Failed to fetch from the provider, something must have gone wrong
            $oLogger->line('HybridAuth failed to fetch data from provider.');
            $oLogger->line('Error Code: ' . $e->getCode());
            $oLogger->line('Error Message: ' . $e->getMessage());

            if (empty($provider)) {
                $this->oUserFeedback->error('Sorry, there was a problem communicating with the network.');

            } elseif (is_array($provider)) {
                $this->oUserFeedback->error('Sorry, there was a problem communicating with ' . $provider['label'] . '.');

            } else {
                $this->oUserFeedback->error('Sorry, there was a problem communicating with ' . $provider . '.');
            }

            if ($oUri->segment(4) == 'register') {
                $sRedirectUrl = 'auth/register';
            } else {
                $sRedirectUrl = 'auth/login';
            }

            if ($this->data['return_to']) {
                $sRedirectUrl .= '?return_to=' . urlencode($this->data['return_to']);
            }

            redirect($sRedirectUrl);
        }

        $oUser = $oSocial->getUserByProviderId($provider['slug'], $socialUser->identifier);

        // --------------------------------------------------------------------------

        /**
         * See if we already know about this user, react accordingly.
         * If a user already exists for this provider/identifier then it's logical
         * to spok them in, I mean, log them in - provided of course they aren't
         * already logged in, if they are then silly user. If no user is recognised
         * then we need to register them, providing, of course that registration is
         * enabled and that no one else on the system has their email address.
         * On that note, we need to respect \Nails\Config::get('APP_NATIVE_LOGIN_USING'); if the provider
         * cannot satisfy this then we'll need to interrupt registration and ask them
         * for either a username or an email (or both).
         */

        if ($oUser) {

            if (isLoggedIn() && activeUser('id') == $oUser->id) {

                /**
                 * Logged in user is already logged in and is the social user.
                 * Silly user, just redirect them to where they need to go.
                 */

                $this->oUserFeedback->warning(lang('auth_social_already_linked', $provider['label']));

                if ($this->data['return_to']) {
                    redirect($this->data['return_to']);
                } else {
                    redirect($oUser->group_homepage);
                }

            } elseif (isLoggedIn() && activeUser('id') != $oUser->id) {

                /**
                 * Hmm, a user was found for this Provider ID, but it's not the actively logged
                 * in user. This means that this provider account is already registered.
                 */

                $this->oUserFeedback->error(lang(
                    'auth_social_account_in_use',
                    [$provider['label'], \Nails\Config::get('APP_NAME')]
                ));

                if ($this->data['return_to']) {
                    redirect($this->data['return_to']);
                } else {
                    redirect($oUser->group_homepage);
                }

            } elseif ($oUser->is_suspended) {

                $this->oUserFeedback->error(lang('auth_login_fail_suspended'));

                createUserEvent(
                    'did_login_fail',
                    ['reason' => 'suspended'],
                    null,
                    $oUser->id
                );

                if ($this->data['return_to']) {
                    redirect($this->data['return_to']);
                } else {
                    redirect($oUser->group_homepage);
                }

            } else {

                //  Fab, user exists, try to log them in
                $oUserModel->setLoginData($oUser->id);
                $oSocial->saveSession($oUser->id);

                if (!$this->handleLogin($oUser)) {

                    $sRedirectUrl = 'auth/login';

                    if ($this->data['return_to']) {
                        $sRedirectUrl .= '?return_to=' . urlencode($this->data['return_to']);
                    }

                    redirect($sRedirectUrl);
                }
            }

        } elseif (isLoggedIn()) {

            /**
             * User is logged in and it look's like the provider isn't being used by
             * anyone else. Go ahead and link the two accounts together.
             */

            if ($oSocial->saveSession(activeUser('id'), [$provider])) {

                createUserEvent('did_link_provider', ['provider' => $provider]);
                $this->oUserFeedback->success(lang('auth_social_linked_ok', $provider['label']));

            } else {
                $this->oUserFeedback->error(lang('auth_social_linked_fail', $provider['label']));
            }

            redirect($this->data['return_to']);

        } else {

            /**
             * Didn't find a user and the active user isn't logged in, assume they want
             * to register an account. I mean, who wouldn't, this site is AwEsOmE.
             */

            if (appSetting('user_registration_enabled', 'auth')) {

                $aRequiredData = [];
                $aOptionalData = [];

                //  Fetch required data
                switch (\Nails\Config::get('APP_NATIVE_LOGIN_USING')) {

                    case 'EMAIL':
                        $aRequiredData['email'] = trim($socialUser->email);
                        break;

                    case 'USERNAME':
                        $aRequiredData['username'] = '';
                        break;

                    default:
                        $aRequiredData['email']    = trim($socialUser->email);
                        $aRequiredData['username'] = '';
                        break;
                }

                $aRequiredData['first_name'] = trim($socialUser->firstName);
                $aRequiredData['last_name']  = trim($socialUser->lastName);

                //  And any optional data
                if (checkdate($socialUser->birthMonth, $socialUser->birthDay, $socialUser->birthYear)) {

                    $aOptionalData['dob']          = [];
                    $aOptionalData['dob']['year']  = trim($socialUser->birthYear);
                    $aOptionalData['dob']['month'] = str_pad(trim($socialUser->birthMonth), 2, 0, STR_PAD_LEFT);
                    $aOptionalData['dob']['day']   = str_pad(trim($socialUser->birthDay), 2, 0, STR_PAD_LEFT);
                    $aOptionalData['dob']          = implode('-', $aOptionalData['dob']);
                }

                switch (strtoupper($socialUser->gender)) {

                    case 'MALE':
                        $aOptionalData['gender'] = 'MALE';
                        break;

                    case 'FEMALE':
                        $aOptionalData['gender'] = 'FEMALE';
                        break;
                }

                // --------------------------------------------------------------------------

                /**
                 * If any required fields are missing then we need to interrupt the registration
                 * flow and ask for them
                 */

                if (count($aRequiredData) !== count(array_filter($aRequiredData))) {

                    /**
                     * @TODO: One day work out a way of doing this so that we don't need to call
                     * the API again etc, uses unnecessary calls. Then again, maybe it *is*
                     * necessary.
                     */

                    $this->socialSignOnRequestData($aRequiredData, $provider['slug']);
                }

                /**
                 * We have everything we need to create the user account However, first we need to
                 * make sure that our data is valid and not in use. At this point it's not the
                 * user's fault so don't throw an error.
                 */

                //  Check email
                if (isset($aRequiredData['email'])) {

                    $check = $oUserModel->getByEmail($aRequiredData['email']);

                    if ($check) {
                        $aRequiredData['email']  = '';
                        $socialSignOnRequestData = true;
                    }
                }

                // --------------------------------------------------------------------------

                if (isset($aRequiredData['username'])) {

                    /**
                     * Username was set using provider provided username, check it's valid if
                     * not, then request one. At this point it's not the user's fault so don't
                     * throw an error.
                     */

                    $check = $oUserModel->getByUsername($aRequiredData['username']);

                    if ($check) {
                        $aRequiredData['username'] = '';
                        $socialSignOnRequestData   = true;
                    }

                } else {

                    /**
                     * No username, make one up for them, try to use the social_user username
                     * (as it might not have been set above), failing that use the user's name,
                     * failing THAT use a random string
                     */

                    if (!empty($socialUser->username)) {

                        $sUsername = $socialUser->username;

                    } elseif ($aRequiredData['first_name'] || $aRequiredData['last_name']) {

                        $sUsername = $aRequiredData['first_name'] . ' ' . $aRequiredData['last_name'];

                    } else {
                        $oDate     = Factory::factory('DateTime');
                        $sUsername = 'user' . $oDate->format('YmdHis');
                    }

                    $basename                  = url_title($sUsername, '-', true);
                    $aRequiredData['username'] = $basename;

                    $oUser = $oUserModel->getByUsername($aRequiredData['username']);

                    while ($oUser) {
                        $aRequiredData['username'] = increment_string($basename, '');
                        $oUser                     = $oUserModel->getByUsername($aRequiredData['username']);
                    }
                }

                // --------------------------------------------------------------------------

                //  Request data?
                if (!empty($socialSignOnRequestData)) {
                    $this->socialSignOnRequestData($aRequiredData, $provider->slug);
                }

                // --------------------------------------------------------------------------

                //  Handle referrals
                if ($oSession->getUserData('referred_by')) {
                    $aOptionalData['referred_by'] = $oSession->getUserData('referred_by');
                }

                // --------------------------------------------------------------------------

                //  Merge data arrays
                $data = array_merge($aRequiredData, $aOptionalData);

                // --------------------------------------------------------------------------

                //  Create user
                $newUser = $oUserModel->create($data);

                if ($newUser) {

                    /**
                     * Welcome aboard, matey
                     * - Save provider details
                     * - Upload profile image if available
                     */

                    $oSocial->saveSession($newUser->id, [$provider]);

                    if (!empty($socialUser->photoURL)) {

                        //  Has profile image
                        $imgUrl = $socialUser->photoURL;

                    } elseif (!empty($newUser->email)) {

                        //  Attempt gravatar
                        $imgUrl = 'http://www.gravatar.com/avatar/' . md5($newUser->email) . '?d=404&s=2048&r=pg';
                    }

                    if (!empty($imgUrl)) {

                        //  Fetch the image
                        //  @todo Consider streaming directly to the filesystem
                        $oHttpClient = Factory::factory('HttpClient');

                        try {

                            $oResponse = $oHttpClient->get($imgUrl);

                            if ($oResponse->getStatusCode() === 200) {

                                //  Attempt upload
                                /** @var Cdn $oCdn */
                                $oCdn = Factory::service('Cdn', \Nails\Cdn\Constants::MODULE_SLUG);
                                /** @var FileCache $oFileCache */
                                $oFileCache = Factory::service('FileCache');

                                //  Save file to cache
                                //  @todo (Pablo - 2019-07-18) - Use the file cache write methods
                                $cacheFile = $oFileCache->getDir() . 'new-user-profile-image-' . $newUser->id;

                                if (@file_put_contents($cacheFile, (string) $oResponse->getBody)) {

                                    $_upload = $oCdn->objectCreate($cacheFile, 'profile-images', []);

                                    if ($_upload) {

                                        $data                = [];
                                        $data['profile_img'] = $_upload->id;

                                        $oUserModel->update($newUser->id, $data);

                                    } else {
                                        $oLogger->line('Failed to upload user\'s profile image');
                                        $oLogger->line($oCdn->lastError());
                                    }
                                }
                            }

                        } catch (Exception $e) {
                            $oLogger->line('Failed to upload user\'s profile image');
                            $oLogger->line($e->getMessage());
                        }
                    }

                    // --------------------------------------------------------------------------

                    //  @todo (Pablo - 2019-07-16) - Setting login data is causing a cascade of ini_set errors
                    //  Aint that swell, all registered! Redirect!
                    $oUserModel->setLoginData($newUser->id);

                    // --------------------------------------------------------------------------

                    //  Create an event for this event
                    createUserEvent('did_register', ['method' => $provider], $newUser->id, $newUser->id);

                    // --------------------------------------------------------------------------

                    //  Redirect
                    $this->oUserFeedback->success(lang('auth_social_register_ok', $newUser->first_name));

                    if (empty($this->data['return_to'])) {
                        /** @var Group $oUserGroupModel */
                        $oUserGroupModel = Factory::model('UserGroup', Constants::MODULE_SLUG);
                        $group           = $oUserGroupModel->getById($newUser->group_id);
                        $sRedirectUrl    = $group->registration_redirect ? $group->registration_redirect : $group->default_homepage;
                    } else {
                        $sRedirectUrl = $this->data['return_to'];
                    }

                    redirect($sRedirectUrl);

                } else {

                    //  Oh dear, something went wrong
                    $this->oUserFeedback->error('Sorry, something went wrong and your account could not be created.');

                    $sRedirectUrl = 'auth/login';

                    if ($this->data['return_to']) {
                        $sRedirectUrl .= '?return_to=' . urlencode($this->data['return_to']);
                    }

                    redirect($sRedirectUrl);
                }

            } else {

                //  How unfortunate, registration is disabled. Redirect back to the login page
                $this->oUserFeedback->error(lang('auth_social_register_disabled'));

                $sRedirectUrl = 'auth/login';

                if ($this->data['return_to']) {
                    $sRedirectUrl .= '?return_to=' . urlencode($this->data['return_to']);
                }

                redirect($sRedirectUrl);
            }
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Handles requesting of additional data from the user
     *
     * @param array  &$aRequiredData An array of fields to request
     *
     * @throws FactoryException
     */
    protected function socialSignOnRequestData(array &$aRequiredData): void
    {
        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        if ($oInput->post()) {

            /** @var FormValidation $oFormValidation */
            $oFormValidation = Factory::service('FormValidation');

            if (isset($aRequiredData['email'])) {
                $oFormValidation->set_rules('email', 'email', 'trim|required|valid_email|is_unique[' . \Nails\Config::get('NAILS_DB_PREFIX') . 'user_email.email]');
            }

            if (isset($aRequiredData['username'])) {
                $oFormValidation->set_rules('username', 'username', 'trim|required|is_unique[' . \Nails\Config::get('NAILS_DB_PREFIX') . 'user.username]');
            }

            if (empty($aRequiredData['first_name'])) {
                $oFormValidation->set_rules('first_name', '', 'trim|required');
            }

            if (empty($aRequiredData['last_name'])) {
                $oFormValidation->set_rules('last_name', '', 'trim|required');
            }

            $oFormValidation->set_message('required', lang('fv_required'));
            $oFormValidation->set_message('valid_email', lang('fv_valid_email'));

            if (\Nails\Config::get('APP_NATIVE_LOGIN_USING') == 'EMAIL') {
                $oFormValidation->set_message(
                    'is_unique',
                    lang('fv_email_already_registered', siteUrl('auth/password/forgotten'))
                );
            } elseif (\Nails\Config::get('APP_NATIVE_LOGIN_USING') == 'USERNAME') {
                $oFormValidation->set_message(
                    'is_unique',
                    lang('fv_username_already_registered', siteUrl('auth/password/forgotten'))
                );
            } else {
                $oFormValidation->set_message(
                    'is_unique',
                    lang('fv_identity_already_registered', siteUrl('auth/password/forgotten'))
                );
            }

            if ($oFormValidation->run()) {

                //  Valid!Ensure required data is set correctly then allow system to move on.
                if (isset($aRequiredData['email'])) {
                    $aRequiredData['email'] = $oInput->post('email');
                }

                if (isset($aRequiredData['username'])) {
                    $aRequiredData['username'] = $oInput->post('username');
                }

                if (empty($aRequiredData['first_name'])) {
                    $aRequiredData['first_name'] = $oInput->post('first_name');
                }

                if (empty($aRequiredData['last_name'])) {
                    $aRequiredData['last_name'] = $oInput->post('last_name');
                }

            } else {
                $this->oUserFeedback->error(lang('fv_there_were_errors'));
                $this->socialSignOnRequestDataForm($aRequiredData, $provider);
            }

        } else {
            $this->socialSignOnRequestDataForm($aRequiredData, $provider);
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Renders the "request data" form
     *
     * @param array  &$aRequiredData An array of fields to request
     * @param string  $sProvider     The provider being used
     *
     * @throws FactoryException
     */
    protected function socialSignOnRequestDataForm(array &$aRequiredData, string $sProvider): void
    {
        /** @var Uri $oUri */
        $oUri = Factory::service('Uri');

        $this->data['required_data'] = $aRequiredData;
        $this->data['form_url']      = 'auth/login/' . $sProvider;

        if ($oUri->segment(4) == 'register') {
            $this->data['form_url'] .= '/register';
        }

        if ($this->data['return_to']) {
            $this->data['form_url'] .= '?return_to=' . urlencode($this->data['return_to']);
        }

        $this->loadStyles(\Nails\Config::get('NAILS_APP_PATH') . 'application/modules/auth/views/register/social_request_data.php');

        Factory::service('View')
            ->load([
                'structure/header/blank',
                'auth/register/social_request_data',
                'structure/footer/blank',
            ]);

        /** @var Output $oOutput */
        $oOutput = Factory::service('Output');
        echo $oOutput->getOutput();
        exit();
    }

    // --------------------------------------------------------------------------

    /**
     * Route requests appropriately
     *
     * @throws FactoryException
     */
    public function _remap(): void
    {
        /** @var Uri $oUri */
        $oUri = Factory::service('Uri');

        $sMethod = $oUri->segment(3) ? $oUri->segment(3) : 'index';

        if (method_exists($this, $sMethod) && substr($sMethod, 0, 1) != '_') {

            $this->{$sMethod}();

        } else {

            //  Assume the 3rd segment is a login provider supported by Hybrid Auth
            /** @var SocialSignOn $oSocial */
            $oSocial = Factory::service('SocialSignOn', Constants::MODULE_SLUG);
            if ($oSocial->isValidProvider($sMethod)) {
                $this->socialSignon($sMethod);
            } else {
                show404();
            }
        }
    }
}

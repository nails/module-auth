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

use Nails\Auth\Controller\Base;
use Nails\Common\Exception\NailsException;
use Nails\Factory;

class Login extends Base
{
    /**
     * Construct the controller
     */
    public function __construct()
    {
        parent::__construct();

        $oInput = Factory::service('Input');

        $sReturnTo = $oInput->get('return_to');

        if ($sReturnTo) {

            $sReturnTo = preg_match('#^https?\://#', $sReturnTo) ? $sReturnTo : site_url($sReturnTo);
            $aReturnTo = parse_url($sReturnTo);

            //  urlencode the query if there is one
            if (!empty($aReturnTo['query'])) {
                //  Break it apart and glue it together (urlencoded)
                parse_str($aReturnTo['query'], $aQuery);
                $aReturnTo['query'] = http_build_query($aQuery);
            }

            if (empty($aReturnTo['host']) && site_url() === '/') {
                $this->data['return_to'] = [
                    !empty($aReturnTo['path']) ? $aReturnTo['path'] : '',
                    !empty($aReturnTo['query']) ? '?' . $aReturnTo['query'] : '',
                ];
            } else {
                $this->data['return_to'] = [
                    !empty($aReturnTo['scheme']) ? $aReturnTo['scheme'] . '://' : 'http://',
                    !empty($aReturnTo['host']) ? $aReturnTo['host'] : site_url(),
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
        $this->data['page']->title = lang('auth_title_login');
    }

    // --------------------------------------------------------------------------

    /**
     * Validate data and log the user in.
     * @return  void
     **/
    public function index()
    {
        //  If you're logged in you shouldn't be accessing this method
        if (isLoggedIn()) {
            redirect($this->data['return_to']);
        }

        // --------------------------------------------------------------------------

        //  If there's POST data attempt to log user in
        $oInput = Factory::service('Input');
        if ($oInput->post()) {

            //  Validate input
            $oFormValidation = Factory::service('FormValidation');

            //  The rules vary depending on what login methods are enabled.
            switch (APP_NATIVE_LOGIN_USING) {

                case 'EMAIL':
                    $oFormValidation->set_rules('identifier', 'Email', 'required|trim|valid_email');
                    break;

                case 'USERNAME':
                    $oFormValidation->set_rules('identifier', 'Username', 'required|trim');
                    break;

                default:
                    $oFormValidation->set_rules('identifier', 'Username or Email', 'trim');
                    break;
            }

            //  Password is always required, obviously.
            $oFormValidation->set_rules('password', 'Password', 'required');
            $oFormValidation->set_message('required', lang('fv_required'));
            $oFormValidation->set_message('valid_email', lang('fv_valid_email'));

            if ($oFormValidation->run()) {

                //  Attempt the log in
                $sIdentifier = $oInput->post('identifier');
                $sPassword   = $oInput->post('password');
                $bRememberMe = (bool) $oInput->post('remember');
                $oAuthModel  = Factory::model('Auth', 'nails/module-auth');

                $oUser = $oAuthModel->login($sIdentifier, $sPassword, $bRememberMe);

                if ($oUser) {
                    $this->_login($oUser, $bRememberMe);
                } else {
                    $this->data['error'] = $oAuthModel->lastError();
                }

            } else {

                $this->data['error'] = lang('fv_there_were_errors');
            }
        }

        // --------------------------------------------------------------------------

        $oSocial                               = Factory::service('SocialSignOn', 'nails/module-auth');
        $this->data['social_signon_enabled']   = $oSocial->isEnabled();
        $this->data['social_signon_providers'] = $oSocial->getProviders('ENABLED');

        // --------------------------------------------------------------------------

        $this->loadStyles(APPPATH . 'modules/auth/views/login/form.php');
        $oView = Factory::service('View');
        $oView->load('structure/header/blank', $this->data);
        $oView->load('auth/login/form', $this->data);
        $oView->load('structure/footer/blank', $this->data);
    }

    // --------------------------------------------------------------------------

    /**
     * Handles the next stage of login after successfully authenticating
     *
     * @param  stdClass $oUser    The user object
     * @param  boolean  $remember Whether to set the rememberMe cookie or not
     * @param  string   $provider Which provider authenticated the login
     *
     * @return void
     */
    protected function _login($oUser, $remember = false, $provider = 'native')
    {
        $oConfig            = Factory::service('Config');
        $oUserPasswordModel = Factory::model('UserPassword', 'nails/module-auth');
        $oAuthModel         = Factory::model('Auth', 'nails/module-auth');

        if ($oUser->is_suspended) {

            $this->data['error'] = lang('auth_login_fail_suspended');
            return;

        } elseif (!empty($oUser->temp_pw)) {

            /**
             * Temporary password detected, log user out and redirect to
             * password reset page.
             **/

            $this->resetPassword($oUser->id, $oUser->salt, $remember, 'TEMP');

        } elseif ($oUserPasswordModel->isExpired($oUser->id)) {

            /**
             * Expired password detected, log user out and redirect to
             * password reset page.
             **/

            $this->resetPassword($oUser->id, $oUser->salt, $remember, 'EXPIRED');

        } elseif ($oConfig->item('authTwoFactorMode')) {

            //  Generate token
            $twoFactorToken = $oAuthModel->mfaTokenGenerate($oUser->id);

            if (!$twoFactorToken) {
                $subject  = 'Failed to generate two-factor auth token';
                $sMessage = 'A user tried to login and the system failed to generate a two-factor auth token.';
                showFatalError($subject, $sMessage);
            }

            //  Is there any query data?
            $query = [];

            if ($this->data['return_to']) {
                $query['return_to'] = $this->data['return_to'];
            }

            if ($remember) {
                $query['remember'] = true;
            }

            $query = $query ? '?' . http_build_query($query) : '';

            //  Where we sending the user?
            switch ($oConfig->item('authTwoFactorMode')) {

                case 'QUESTION':
                    $controller = 'mfa_question';
                    break;

                case 'DEVICE':
                    $controller = 'mfa_device';
                    break;

                default:
                    throw new \Exception('"' . $oConfig->item('authTwoFactorMode') . '" is not a valid MFA Mode');
                    break;
            }

            //  Compile the URL
            $url = [
                'auth',
                $controller,
                $oUser->id,
                $twoFactorToken['salt'],
                $twoFactorToken['token'],
            ];

            $url = implode($url, '/') . $query;

            //  Login was successful, redirect to the appropriate MFA page
            redirect($url);

        } else {

            //  Finally! Send this user on their merry way...
            if ($oUser->last_login) {

                $lastLogin = $oConfig->item('authShowNicetimeOnLogin') ? niceTime(strtotime($oUser->last_login)) : toUserDatetime($oUser->last_login);

                if ($oConfig->item('authShowLastIpOnLogin')) {
                    $sStatus  = 'positive';
                    $sMessage = lang('auth_login_ok_welcome_with_ip', [
                        $oUser->first_name,
                        $lastLogin,
                        $oUser->last_ip,
                    ]);
                } else {
                    $sStatus  = 'positive';
                    $sMessage = lang('auth_login_ok_welcome', [$oUser->first_name, $lastLogin]);
                }

            } else {
                $sStatus  = 'positive';
                $sMessage = lang('auth_login_ok_welcome_notime', [$oUser->first_name]);
            }

            $oSession = Factory::service('Session', 'nails/module-auth');
            $oSession->setFlashData($sStatus, $sMessage);

            $sRedirectUrl = $this->data['return_to'] ? $this->data['return_to'] : $oUser->group_homepage;

            // --------------------------------------------------------------------------

            //  Generate an event for this log in
            create_event('did_log_in', ['provider' => $provider], $oUser->id);

            // --------------------------------------------------------------------------

            redirect($sRedirectUrl);
        }
    }

    // --------------------------------------------------------------------------

    protected function resetPassword($iUserId, $sUserSalt, $bRemember, $sReason = '')
    {
        $aQuery = [];

        if ($this->data['return_to']) {
            $aQuery['return_to'] = $this->data['return_to'];
        }

        if ($bRemember) {
            $aQuery['remember'] = true;
        }

        if ($sReason) {
            $aQuery['reason'] = $sReason;
        }

        $aQuery = $aQuery ? '?' . http_build_query($aQuery) : '';

        /**
         * Log the user out and remove the 'remember me' cookie - if we don't do this
         * then the password reset page will see a logged in user and go nuts
         * (i.e error).
         */

        $oAuthModel = Factory::model('Auth', 'nails/module-auth');
        $oAuthModel->logout();

        redirect('auth/password/reset/' . $iUserId . '/' . md5($sUserSalt) . $aQuery);
    }

    // --------------------------------------------------------------------------

    /**
     * Log a user in using hashes of their user ID and password; easy way of
     * automatically logging a user in from the likes of an email.
     *
     * @throws NailsException
     * @return  void
     **/
    public function with_hashes()
    {
        $oUri    = Factory::service('Uri');
        $oConfig = Factory::service('Config');

        if (!$oConfig->item('authEnableHashedLogin')) {
            show_404();
        }

        // --------------------------------------------------------------------------

        $hash['id'] = $oUri->segment(4);
        $hash['pw'] = $oUri->segment(5);

        if (empty($hash['id']) || empty($hash['pw'])) {
            throw new NailsException(lang('auth_with_hashes_incomplete_creds'), 1);
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

                    //  Nowhere to go? Send them to their default homepage
                    redirect(activeUser('group_homepage'));
                }

            } else {

                //  We are logging in as someone else, log the current user out and try again
                $oAuthModel = Factory::model('Auth', 'nails/module-auth');
                $oAuthModel->logout();

                redirect(preg_replace('/^\//', '', $_SERVER['REQUEST_URI']));
            }
        }

        // --------------------------------------------------------------------------

        /**
         * The active user is a guest, we must look up the hashed user and log them in
         * if all is ok otherwise we report an error.
         */

        $oSession   = Factory::service('Session', 'nails/module-auth');
        $oUserModel = Factory::model('User', 'nails/module-auth');
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
                    $sStatus  = 'positive';
                    $sMessage = lang('auth_login_ok_welcome_with_ip', [
                        $oUser->first_name,
                        $lastLogin,
                        $oUser->last_ip,
                    ]);
                } else {
                    $sStatus  = 'positive';
                    $sMessage = lang('auth_login_ok_welcome', [$oUser->first_name, $oUser->last_login]);
                }

            } else {
                $sStatus  = 'positive';
                $sMessage = lang('auth_login_ok_welcome_notime', [$oUser->first_name]);
            }

            $oSession->setFlashData($sStatus, $sMessage);

            // --------------------------------------------------------------------------

            //  Update their last login
            $oUserModel->updateLastLogin($oUser->id);

            // --------------------------------------------------------------------------

            //  Redirect user
            if ($this->data['return_to'] != site_url()) {

                //  We have somewhere we want to go
                redirect($this->data['return_to']);

            } else {

                //  Nowhere to go? Send them to their default homepage
                redirect($oUser->group_homepage);
            }

        } else {

            //  Bad lookup, invalid hash.
            $oSession->setFlashData('error', lang('auth_with_hashes_autologin_fail'));
            redirect($this->data['return_to']);
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Handle login/registration via social provider
     *
     * @param  string $provider The provider to use
     *
     * @return void
     */
    protected function socialSignon($provider)
    {
        $oUri       = Factory::service('Uri');
        $oSession   = Factory::service('Session', 'nails/module-auth');
        $oSocial    = Factory::service('SocialSignOn', 'nails/module-auth');
        $oUserModel = Factory::model('User', 'nails/module-auth');

        //  Get the adapter, HybridAuth will handle the redirect
        $adapter  = $oSocial->authenticate($provider);
        $provider = $oSocial->getProvider($provider);

        // --------------------------------------------------------------------------

        //  Fetch the user's social profile and, if one exists, the local profile.
        try {

            $socialUser = $adapter->getUserProfile();

        } catch (Exception $e) {

            //  Failed to fetch from the provider, something must have gone wrong
            log_message('error', 'HybridAuth failed to fetch data from provider.');
            log_message('error', 'Error Code: ' . $e->getCode());
            log_message('error', 'Error Message: ' . $e->getMessage());

            if (empty($provider)) {
                $oSession->setFlashData(
                    'error',
                    'Sorry, there was a problem communicating with the network.'
                );
            } else {
                $oSession->setFlashData(
                    'error',
                    'Sorry, there was a problem communicating with ' . $provider['label'] . '.'
                );
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
         * On that note, we need to respect APP_NATIVE_LOGIN_USING; if the provider
         * cannot satisfy this then we'll need to interrupt registration and ask them
         * for either a username or an email (or both).
         */

        if ($oUser) {

            if (isLoggedIn() && activeUser('id') == $oUser->id) {

                /**
                 * Logged in user is already logged in and is the social user.
                 * Silly user, just redirect them to where they need to go.
                 */

                $oSession->setFlashData('message', lang('auth_social_already_linked', $provider['label']));

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

                $oSession->setFlashData('error', lang('auth_social_account_in_use', [$provider['label'], APP_NAME]));

                if ($this->data['return_to']) {
                    redirect($this->data['return_to']);
                } else {
                    redirect($oUser->group_homepage);
                }

            } else {

                //  Fab, user exists, try to log them in
                $oUserModel->setLoginData($oUser->id);
                $oSocial->saveSession($oUser->id);

                if (!$this->_login($oUser)) {

                    $oSession->setFlashData('error', $this->data['error']);

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

            if ($oSocial->saveSession(activeUser('id'), $provider)) {

                create_event('did_link_provider', ['provider' => $provider]);
                $oSession->setFlashData('success', lang('auth_social_linked_ok', $provider['label']));

            } else {
                $oSession->setFlashData('error', lang('auth_social_linked_fail', $provider['label']));
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
                switch (APP_NATIVE_LOGIN_USING) {

                    case 'EMAIL':
                        $aRequiredData['email'] = trim($socialUser->email);
                        break;

                    case 'USERNAME':
                        $aRequiredData['username'] = !empty($socialUser->username) ? trim($socialUser->username) : '';
                        break;

                    default:
                        $aRequiredData['email']    = trim($socialUser->email);
                        $aRequiredData['username'] = !empty($socialUser->username) ? trim($socialUser->username) : '';
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

                    $this->requestData($aRequiredData, $provider['slug']);
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
                        $aRequiredData['email'] = '';
                        $requestData            = true;
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
                        $requestData               = true;
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
                if (!empty($requestData)) {
                    $this->requestData($aRequiredData, $provider->slug);
                }

                // --------------------------------------------------------------------------

                //  Handle referrals
                if ($oSession->userdata('referred_by')) {
                    $aOptionalData['referred_by'] = $oSession->userdata('referred_by');
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

                    $oSocial->saveSession($newUser->id, $provider);

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
                                $oCdn = Factory::service('Cdn', 'nails/module-cdn');

                                //  Save file to cache
                                $cacheFile = CACHE_PATH . 'new-user-profile-image-' . $newUser->id;

                                if (@file_put_contents($cacheFile, (string) $oResponse->getBody)) {

                                    $_upload = $oCdn->objectCreate($cacheFile, 'profile-images', []);

                                    if ($_upload) {

                                        $data                = [];
                                        $data['profile_img'] = $_upload->id;

                                        $oUserModel->update($newUser->id, $data);

                                    } else {
                                        log_message('debug', 'Failed to upload user\'s profile image');
                                        log_message('debug', $oCdn->lastError());
                                    }
                                }
                            }

                        } catch (\Exception $e) {
                            log_message('debug', 'Failed to upload user\'s profile image');
                            log_message('debug', $e->getMessage());
                        }
                    }

                    // --------------------------------------------------------------------------

                    //  Aint that swell, all registered! Redirect!
                    $oUserModel->setLoginData($newUser->id);

                    // --------------------------------------------------------------------------

                    //  Create an event for this event
                    create_event('did_register', ['method' => $provider], $newUser->id);

                    // --------------------------------------------------------------------------

                    //  Redirect
                    $oSession->setFlashData('success', lang('auth_social_register_ok', $newUser->first_name));

                    if (empty($this->data['return_to'])) {
                        $oUserGroupModel = Factory::model('UserGroup', 'nails/module-auth');
                        $group           = $oUserGroupModel->getById($newUser->group_id);
                        $sRedirectUrl    = $group->registration_redirect ? $group->registration_redirect : $group->default_homepage;
                    } else {
                        $sRedirectUrl = $this->data['return_to'];
                    }

                    redirect($sRedirectUrl);

                } else {

                    //  Oh dear, something went wrong
                    $sStatus  = 'error';
                    $sMessage = 'Sorry, something went wrong and your account could not be created.';
                    $oSession->setFlashData($sStatus, $sMessage);

                    $sRedirectUrl = 'auth/login';

                    if ($this->data['return_to']) {
                        $sRedirectUrl .= '?return_to=' . urlencode($this->data['return_to']);
                    }

                    redirect($sRedirectUrl);
                }

            } else {

                //  How unfortunate, registration is disabled. Redirect back to the login page
                $oSession->setFlashData('error', lang('auth_social_register_disabled'));

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
     * @param  array  &$aRequiredData An array of fields to request
     * @param  string $provider       The provider to use
     *
     * @return void
     */
    protected function requestData(&$aRequiredData, $provider)
    {
        $oInput = Factory::service('Input');
        if ($oInput->post()) {

            $oFormValidation = Factory::service('FormValidation');

            if (isset($aRequiredData['email'])) {
                $oFormValidation->set_rules('email', 'email', 'trim|required|valid_email|is_unique[' . NAILS_DB_PREFIX . 'user_email.email]');
            }

            if (isset($aRequiredData['username'])) {
                $oFormValidation->set_rules('username', 'username', 'trim|required|is_unique[' . NAILS_DB_PREFIX . 'user.username]');
            }

            if (empty($aRequiredData['first_name'])) {
                $oFormValidation->set_rules('first_name', '', 'trim|required');
            }

            if (empty($aRequiredData['last_name'])) {
                $oFormValidation->set_rules('last_name', '', 'trim|required');
            }

            $oFormValidation->set_message('required', lang('fv_required'));
            $oFormValidation->set_message('valid_email', lang('fv_valid_email'));

            if (APP_NATIVE_LOGIN_USING == 'EMAIL') {
                $oFormValidation->set_message(
                    'is_unique',
                    lang('fv_email_already_registered', site_url('auth/password/forgotten'))
                );
            } elseif (APP_NATIVE_LOGIN_USING == 'USERNAME') {
                $oFormValidation->set_message(
                    'is_unique',
                    lang('fv_username_already_registered', site_url('auth/password/forgotten'))
                );
            } else {
                $oFormValidation->set_message(
                    'is_unique',
                    lang('fv_identity_already_registered', site_url('auth/password/forgotten'))
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
                $this->data['error'] = lang('fv_there_were_errors');
                $this->requestDataForm($aRequiredData, $provider);
            }

        } else {
            $this->requestDataForm($aRequiredData, $provider);
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Renders the "request data" form
     *
     * @param  array  &$aRequiredData An array of fields to request
     * @param  string $provider       The provider being used
     *
     * @return void
     */
    protected function requestDataForm(&$aRequiredData, $provider)
    {
        $oUri                        = Factory::service('Uri');
        $this->data['required_data'] = $aRequiredData;
        $this->data['form_url']      = 'auth/login/' . $provider;

        if ($oUri->segment(4) == 'register') {
            $this->data['form_url'] .= '/register';
        }

        if ($this->data['return_to']) {
            $this->data['form_url'] .= '?return_to=' . urlencode($this->data['return_to']);
        }

        $oView = Factory::service('View');
        $oView->load('structure/header/blank', $this->data);
        $oView->load('auth/register/social_request_data', $this->data);
        $oView->load('structure/footer/blank', $this->data);

        $oOutput = Factory::service('Output');
        echo $oOutput->get_output();
        exit();
    }

    // --------------------------------------------------------------------------

    /**
     * Route requests appropriately
     * @return void
     */
    public function _remap()
    {
        $oUri   = Factory::service('Uri');
        $method = $oUri->segment(3) ? $oUri->segment(3) : 'index';

        if (method_exists($this, $method) && substr($method, 0, 1) != '_') {

            $this->{$method}();

        } else {

            //  Assume the 3rd segment is a login provider supported by Hybrid Auth
            $oSocial = Factory::service('SocialSignOn', 'nails/module-auth');
            if ($oSocial->isValidProvider($method)) {
                $this->socialSignon($method);
            } else {
                show_404();
            }
        }
    }
}

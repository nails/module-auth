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
use Nails\Auth\Exception\Login\RequiresPasswordResetExpiredException;
use Nails\Auth\Exception\Login\RequiresPasswordResetTempException;
use Nails\Auth\Model\User\Password;
use Nails\Auth\Resource;
use Nails\Auth\Service\Authentication;
use Nails\Auth\Service\Passkey;
use Nails\Auth\Validator\User\Identifier;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\ValidationException;
use Nails\Common\Service\Asset;
use Nails\Common\Service\Config;
use Nails\Common\Service\FormValidation;
use Nails\Common\Service\Input;
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
            ->setDescription('Log in to your ' . \Nails\Factory::service('MetaData')->getAppName() . ' account');
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
        /** @var Authentication $oAuthService */
        $oAuthService = Factory::service('Authentication', Constants::MODULE_SLUG);
        /** @var \Nails\Captcha\Service\Captcha $oCaptchaService */
        $oCaptchaService = Factory::service('Captcha', Nails\Captcha\Constants::MODULE_SLUG);

        // --------------------------------------------------------------------------

        if ($oInput->post()) {

            try {

                (new Identifier())
                    ->addRules(['password' => [FormValidation::RULE_REQUIRED]])
                    ->run($oInput->post());

                if (appSetting('user_login_captcha_enabled', 'auth')) {
                    if (!$oCaptchaService->verify()) {
                        throw new ValidationException(
                            lang('auth_login_captcha_fail')
                        );
                    }
                }

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

            } catch (RequiresPasswordResetTempException $e) {
                $this->handlePasswordReset($oUser, $bRemember, 'TEMP');

            } catch (RequiresPasswordResetExpiredException $e) {
                $this->handlePasswordReset($oUser, $bRemember, 'EXPIRED');

            } catch (AuthException $e) {
                $this->oUserFeedback->error($e->getMessage());
            }
        }

        // --------------------------------------------------------------------------

        /** @var Passkey $oPasskeyService */
        $oPasskeyService                = Factory::service('Passkey', Constants::MODULE_SLUG);
        $this->data['passkeys_enabled'] = $oPasskeyService->isEnabled();

        // --------------------------------------------------------------------------

        $sAppView = \Nails\Config::get('NAILS_APP_PATH') . 'application/modules/auth/views/login/form.php';

        $this->loadStyles($sAppView);

        //  Re-boot captcha as loadStyles clears everything
        if (appSetting('user_login_captcha_enabled', 'auth')) {
            $oCaptchaService->boot();
        }

        /**
         * An app which has overridden the view owns its own assets; it can opt in
         * with loadPasskeyAssets() from the passkey helper.
         */
        if ($this->data['passkeys_enabled'] && !$this->isViewOverridden($sAppView)) {
            /** @var Asset $oAsset */
            $oAsset = Factory::service('Asset');
            $oAsset->load('passkey.min.js', Constants::MODULE_SLUG, 'JS', false, true);
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

        if (!empty($oUser->temp_pw)) {

            $this->handlePasswordReset($oUser, $bRemember, 'TEMP');

        } elseif ($oUserPasswordModel->isExpired($oUser->id)) {

            $this->handlePasswordReset($oUser, $bRemember, 'EXPIRED');

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

            if ($this->shouldNudgeForPasskey($oUser)) {

                /** @var Passkey $oPasskeyService */
                $oPasskeyService = Factory::service('Passkey', Constants::MODULE_SLUG);
                $oPasskeyService->markNudged($sRedirectUrl);

                redirect('auth/passkeys/nudge');
            }

            // --------------------------------------------------------------------------

            redirect($sRedirectUrl);
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Whether to offer this user a passkey before sending them on their way
     *
     * @throws FactoryException
     */
    protected function shouldNudgeForPasskey(Resource\User $oUser): bool
    {
        /** @var Passkey $oPasskeyService */
        $oPasskeyService = Factory::service('Passkey', Constants::MODULE_SLUG);

        if (!$oPasskeyService->isEnabled() || $oPasskeyService->isNudgeDismissed()) {
            return false;
        }

        if ($oPasskeyService->hasBeenNudged()) {
            return false;
        }

        /** @var \Nails\Auth\Model\User\Passkey $oPasskeyModel */
        $oPasskeyModel = Factory::model('UserPasskey', Constants::MODULE_SLUG);

        return $oPasskeyModel->countForUser((int) $oUser->id) === 0;
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
                    $this->oUserFeedback->success(lang('auth_login_ok_welcome', [
                        $oUser->first_name,
                        $oUser->last_login,
                    ]));
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
}

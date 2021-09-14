<?php

/**
 * This class provides some common functionality to the MFA controllers
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    Controller
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Auth\Controller;

use Nails\Auth\Constants;
use Nails\Auth\Model\User;
use Nails\Auth\Service\Authentication;
use Nails\Common\Service\Config;
use Nails\Common\Service\Input;
use Nails\Common\Service\Uri;
use Nails\Factory;

/**
 * Class BaseMfa
 *
 * @package Nails\Auth\Controller
 */
abstract class BaseMfa extends Base
{
    protected $authMfaMode;
    protected $authMfaConfig;
    protected $returnTo;
    protected $remember;
    protected $mfaUser;
    protected $loginMethod;

    // --------------------------------------------------------------------------

    /**
     * Construct the controller and set the Mfa Configs
     */
    public function __construct()
    {
        parent::__construct();

        /** @var Config $oConfig */
        $oConfig = Factory::service('Config');

        $this->authMfaMode   = $oConfig->item('authTwoFactorMode');
        $aConfig             = $oConfig->item('authTwoFactor');
        $this->authMfaConfig = $aConfig[$this->authMfaMode];
    }

    // --------------------------------------------------------------------------

    protected function validateToken()
    {
        /** @var User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);
        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        /** @var Uri $oUri */
        $oUri = Factory::service('Uri');

        $this->returnTo = $oInput->get('return_to', true);
        $this->remember = $oInput->get('remember', true);
        $iUserId        = (int) $oUri->segment(4);
        $this->mfaUser  = $oUserModel->getById($iUserId);

        if (!$this->mfaUser) {
            $this->oUserFeedback->error(lang('auth_twofactor_token_unverified'));
            if ($this->returnTo) {
                redirect('auth/login?return_to=' . $this->returnTo);
            } else {
                redirect('auth/login');
            }
        }

        $sSalt             = $oUri->segment(5);
        $sToken            = $oUri->segment(6);
        $sIpAddress        = $oInput->ipAddress();
        $this->loginMethod = $oUri->segment(7) ? $oUri->segment(7) : 'native';

        //  Safety first
        switch (strtolower($this->loginMethod)) {

            case 'facebook':
            case 'twitter':
            case 'linkedin':
            case 'native':
                //  All good, homies.
                break;

            default:
                $this->loginMethod = 'native';
                break;
        }

        /** @var Authentication $oAuthService */
        $oAuthService = Factory::service('Authentication', Constants::MODULE_SLUG);
        if (!$oAuthService->mfaTokenValidate($this->mfaUser->id, $sSalt, $sToken, $sIpAddress)) {

            $this->oUserFeedback->error(lang('auth_twofactor_token_unverified'));

            $aQuery = array_filter([
                'return_to' => $this->returnTo,
                'remember'  => $this->remember,
            ]);

            if ($aQuery) {
                $sQuery = '?' . http_build_query($aQuery);
            } else {
                $sQuery = '';
            }

            redirect('auth/login' . $sQuery);

        } else {

            //  Token is valid, generate a new one for the next request
            $this->data['token'] = $oAuthService->mfaTokenGenerate($this->mfaUser->id);

            //  Set other data for the views
            $this->data['user_id']      = $this->mfaUser->id;
            $this->data['login_method'] = $this->loginMethod;
            $this->data['return_to']    = $this->returnTo;
            $this->data['remember']     = $this->remember;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Logs a user In
     */
    protected function loginUser()
    {
        //  Set login data for this user
        /** @var User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);
        $oUserModel->setLoginData($this->mfaUser->id);

        //  If we're remembering this user set a cookie
        if ($this->remember) {
            $oUserModel->setRememberCookie(
                $this->mfaUser->id,
                $this->mfaUser->password,
                $this->mfaUser->email
            );
        }

        //  Update their last login and increment their login count
        $oUserModel->updateLastLogin($this->mfaUser->id);

        // --------------------------------------------------------------------------

        //  Generate an event for this log in
        createUserEvent('did_log_in', ['method' => $this->loginMethod], null, $this->mfaUser->id);

        // --------------------------------------------------------------------------

        //  Say hello
        if ($this->mfaUser->last_login) {

            /** @var Config $oConfig */
            $oConfig = Factory::service('Config');

            $sLastLogin = $oConfig->item('authShowNicetimeOnLogin')
                ? niceTime(strtotime($this->mfaUser->last_login))
                : toUserDatetime($this->mfaUser->last_login);

            if ($oConfig->item('authShowLastIpOnLogin')) {
                $this->oUserFeedback->success(lang(
                    'auth_login_ok_welcome_with_ip',
                    [
                        $this->mfaUser->first_name,
                        $sLastLogin,
                        $this->mfaUser->last_ip,
                    ]
                ));

            } else {
                $this->oUserFeedback->success(lang(
                    'auth_login_ok_welcome',
                    [
                        $this->mfaUser->first_name,
                        $sLastLogin,
                    ]
                ));
            }

        } else {
            $this->oUserFeedback->success(lang(
                'auth_login_ok_welcome_notime',
                [
                    $this->mfaUser->first_name,
                ]
            ));
        }

        // --------------------------------------------------------------------------

        //  Delete the token we generated, it's no needed, eh!
        /** @var Authentication $oAuthService */
        $oAuthService = Factory::model('Authentication', Constants::MODULE_SLUG);
        $oAuthService->mfaTokenDelete($this->data['token']['id']);

        // --------------------------------------------------------------------------

        $sRedirectUrl = $this->returnTo != siteUrl() ? $this->returnTo : $this->mfaUser->group_homepage;
        redirect($sRedirectUrl);
    }
}

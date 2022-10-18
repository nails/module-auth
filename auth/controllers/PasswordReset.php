<?php

/**
 * Reset password facility
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    Controller
 * @author      Nails Dev Team
 * @link
 */

use Nails\Auth\Constants;
use Nails\Auth\Controller\Base;
use Nails\Auth\Model\User;
use Nails\Auth\Model\User\Password;
use Nails\Auth\Service\Authentication;
use Nails\Common\Service\Config;
use Nails\Common\Service\FormValidation;
use Nails\Common\Service\Input;
use Nails\Common\Service\Uri;
use Nails\Factory;

/**
 * Class PasswordReset
 */
class PasswordReset extends Base
{
    /**
     * PasswordReset constructor.
     */
    public function __construct()
    {
        parent::__construct();

        //  If user is logged in they shouldn't be accessing this method
        if (isLoggedIn()) {
            $this->oUserFeedback->error(lang('auth_no_access_already_logged_in', activeUser('email')));
            redirect('/');
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Validate the supplied assets and if valid present the user with a reset form
     *
     * @param int    $iUserId The ID of the user to reset
     * @param string $sHash   The hash to validate against
     *
     * @return  void
     **/
    protected function validate($iUserId, $sHash)
    {
        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        /** @var Config $oConfig */
        $oConfig = Factory::service('Config');
        /** @var User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);
        /** @var Authentication $oAuthService */
        $oAuthService = Factory::service('Authentication', Constants::MODULE_SLUG);
        /** @var Password $oUserPasswordModel */
        $oUserPasswordModel = Factory::model('UserPassword', Constants::MODULE_SLUG);

        //  Check auth credentials
        $oUser = $oUserModel->getById($iUserId);

        // --------------------------------------------------------------------------

        if ($oUser && isset($oUser->salt) && $sHash == $oUserPasswordModel::resetHash($oUser)) {

            //  Valid combination, is there MFA on the account?
            if ($oConfig->item('authTwoFactorMode')) {

                /**
                 * This variable will stop the password resetting until we're confident
                 * that MFA has been passed
                 */

                $bMfaValid = false;

                /**
                 * Check the user's account to see if they have MFA enabled, if so
                 * require that they pass that before allowing the password to be reset
                 */

                switch ($oConfig->item('authTwoFactorMode')) {

                    case 'QUESTION':
                        $this->data['mfaQuestion'] = $oAuthService->mfaQuestionGet($oUser->id);

                        if ($this->data['mfaQuestion']) {

                            if ($oInput->post()) {

                                //  Validate answer
                                $isValid = $oAuthService->mfaQuestionValidate(
                                    $this->data['mfaQuestion']->id,
                                    $oUser->id,
                                    $oInput->post('mfaAnswer')
                                );

                                if ($isValid) {

                                    $bMfaValid = true;

                                } else {
                                    $this->oUserFeedback->error('Sorry, the answer to your security question was incorrect.');
                                }
                            }

                        } else {

                            //  No questions set up, allow for now
                            $bMfaValid = true;
                        }

                        break;

                    case 'DEVICE':
                        $this->data['mfaDevice'] = $oAuthService->mfaDeviceSecretGet($oUser->id);

                        if ($this->data['mfaDevice']) {

                            if ($oInput->post()) {

                                //  Validate answer
                                $isValid = $oAuthService->mfaDeviceCodeValidate(
                                    $oUser->id,
                                    $oInput->post('mfaCode')
                                );

                                if ($isValid) {
                                    $bMfaValid = true;

                                } else {
                                    $this->oUserFeedback->error(sprintf(
                                        'Sorry, that code could not be validated. %s',
                                        $oAuthService->lastError()
                                    ));
                                }
                            }

                        } else {

                            //  No devices set up, allow for now
                            $bMfaValid = true;
                        }
                        break;
                }

            } else {

                //  No MFA so just set this to true
                $bMfaValid = true;
            }

            // --------------------------------------------------------------------------

            // Only run if MFA has been passed and there's POST data
            if ($bMfaValid && $oInput->post()) {

                try {

                    /** @var FormValidation $oFormValidation */
                    $oFormValidation = Factory::service('FormValidation');
                    $oFormValidation
                        ->buildValidator([
                            'new_password' => ['required', 'matches[confirm_pass]'],
                            'confirm_pass' => ['required'],
                        ])
                        ->run();

                    //  Validated, update user and login.
                    $bRemember = (bool) $oInput->get('remember');
                    $aData     = [
                        'forgotten_password_code' => null,
                        'temp_pw'                 => false,
                        'password'                => $oInput->post('new_password'),
                    ];

                    //  Reset the password
                    if (!$oUserModel->update($oUser->id, $aData)) {
                        throw new \Nails\Auth\Exception\AuthException(
                            lang('auth_forgot_reset_badupdate', $oUserModel->lastError())
                        );
                    }

                    $oUserModel->resetFailedLogin($oUser->id);

                    //  Refresh user object
                    $oUser      = $oUserModel->getById($oUser->id);
                    $oLoginUser = $oAuthService->loginWithCredentials(
                        $oUser,
                        $oInput->post('new_password'),
                        $bRemember,
                        false
                    );

                    if ($oLoginUser) {

                        //  Say hello
                        if ($oLoginUser->last_login) {

                            if ($oConfig->item('authShowNicetimeOnLogin')) {
                                $sLastLogin = niceTime(strtotime($oLoginUser->last_login));
                            } else {
                                $sLastLogin = toUserDatetime($oLoginUser->last_login);
                            }

                            if ($oConfig->item('authShowLastIpOnLogin')) {

                                $this->oUserFeedback->success(lang(
                                    'auth_login_ok_welcome_with_ip',
                                    [
                                        $oLoginUser->first_name,
                                        $sLastLogin,
                                        $oLoginUser->last_ip,
                                    ]
                                ));

                            } else {
                                $this->oUserFeedback->success(lang(
                                    'auth_login_ok_welcome',
                                    [
                                        $oLoginUser->first_name,
                                        $sLastLogin,
                                    ]
                                ));
                            }

                        } else {
                            $this->oUserFeedback->success(lang(
                                'auth_login_ok_welcome_notime',
                                [
                                    $oLoginUser->first_name,
                                ]
                            ));
                        }

                        //  If MFA is setup then we'll need to set the user's session data
                        if ($oConfig->item('authTwoFactorMode')) {
                            $oUserModel->setLoginData($oUser->id);
                        }

                        //  Log user in and forward to wherever they need to go
                        if ($oInput->get('return_to')) {
                            redirect($oInput->get('return_to'));
                        } elseif ($oUser->group_homepage) {
                            redirect($oUser->group_homepage);
                        } else {
                            redirect('/');
                        }

                    } else {
                        $this->oUserFeedback->error(lang('auth_forgot_reset_badlogin', siteUrl('auth/login')));
                    }

                } catch (\Nails\Common\Exception\ValidationException $e) {
                    $this->oUserFeedback->error($e->getMessage());

                } catch (\Nails\Auth\Exception\AuthException $e) {
                    $this->oUserFeedback->error($e->getMessage());
                }
            }

            // --------------------------------------------------------------------------

            //  Set data
            $this->oMetaData->setTitles([lang('auth_title_reset')]);
            $this->data['resetUrl']      = $oUserPasswordModel::resetUrl($oUser);
            $this->data['passwordRules'] = $oUserPasswordModel->getRulesAsString($oUser->group_id);
            $this->data['return_to']     = $oInput->get('return_to');
            $this->data['remember']      = $oInput->get('remember');

            if (empty((string) $this->data['warning'])) {

                switch ($oInput->get('reason')) {

                    case 'EXPIRED':
                        $this->oUserFeedback->warning(lang(
                            'auth_login_pw_expired',
                            $oUserPasswordModel->expiresAfter($oUser->group_id)
                        ));
                        break;

                    case 'TEMP':
                    default:
                        $this->oUserFeedback->warning(lang('auth_login_pw_temp'));
                        break;
                }
            }

            // --------------------------------------------------------------------------

            //  Load the views
            $this->loadStyles(NAILS_APP_PATH . 'application/modules/auth/views/password/change_temp.php');
            Factory::service('View')
                ->load([
                    'structure/header/blank',
                    'auth/password/change_temp',
                    'structure/footer/blank',
                ]);

            return;
        }

        // --------------------------------------------------------------------------

        show404();
    }

    // --------------------------------------------------------------------------

    /**
     * Route requests to the right method
     *
     * @param string $iUserId The ID of the user to reset, as per the URL
     *
     * @return  void
     **/
    public function _remap($iUserId)
    {
        /** @var Uri $oUri */
        $oUri = Factory::service('Uri');
        $this->validate($iUserId, $oUri->rsegment(3));
    }
}

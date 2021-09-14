<?php

/**
 * User registration facility
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    Controller
 * @author      Nails Dev Team
 * @link
 */

use Nails\Auth\Constants;
use Nails\Auth\Controller\Base;
use Nails\Auth\Model\User\Group;
use Nails\Auth\Model\User\Password;
use Nails\Auth\Service\SocialSignOn;
use Nails\Common\Service\FormValidation;
use Nails\Common\Service\Input;
use Nails\Common\Service\Session;
use Nails\Config;
use Nails\Factory;

/**
 * Class Register
 */
class Register extends Base
{
    /**
     * Construct the controller
     */
    public function __construct()
    {
        parent::__construct();

        // --------------------------------------------------------------------------

        //  Is registration enabled
        if (!appSetting('user_registration_enabled', 'auth')) {
            show404();
        }

        // --------------------------------------------------------------------------

        //  Specify a default title for this page
        $this->data['page']->title = lang('auth_title_register');
    }

    // --------------------------------------------------------------------------

    /**
     * Display registration form, validate data and create user
     *
     * @return void
     */
    public function index()
    {
        /** @var Session $oSession */
        $oSession = Factory::service('Session');
        /** @var FormValidation $oFormValidation */
        $oFormValidation = Factory::service('FormValidation');
        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        /** @var \Nails\Auth\Model\User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);
        /** @var \Nails\Auth\Model\User\Email $oUserEmailModel */
        $oUserEmailModel = Factory::model('UserEmail', Constants::MODULE_SLUG);
        /** @var \Nails\Captcha\Service\Captcha $oCaptchaService */
        $oCaptchaService = Factory::service('Captcha', Nails\Captcha\Constants::MODULE_SLUG);

        // --------------------------------------------------------------------------

        //  If you're logged in you shouldn't be accessing this method
        if (isLoggedIn()) {
            $this->oUserFeedback->error(lang('auth_no_access_already_logged_in', activeUser('email')));
            redirect('/');
        }

        // --------------------------------------------------------------------------

        /** @var Group $oUserGroupModel */
        $oUserGroupModel = Factory::model('UserGroup', Constants::MODULE_SLUG);
        $iDefaultGroupId = $oUserGroupModel->getDefaultGroupId();

        // --------------------------------------------------------------------------

        if ($oInput->post()) {

            try {

                $oFormValidation
                    ->buildValidator([
                        'first_name'           => [$oFormValidation::RULE_REQUIRED],
                        'last_name'            => [$oFormValidation::RULE_REQUIRED],
                        'password'             => [$oFormValidation::RULE_REQUIRED],
                        'email'                => in_array(Config::get('APP_NATIVE_LOGIN_USING'), ['EMAIL', 'BOTH']) ? [
                            $oFormValidation::RULE_REQUIRED,
                            $oFormValidation::RULE_VALID_EMAIL,
                            $oFormValidation::rule(
                                $oFormValidation::RULE_IS_UNIQUE, $oUserEmailModel->getTableName(), 'email'
                            ),
                        ] : [],
                        'username'             => in_array(Config::get('APP_NATIVE_LOGIN_USING'), [
                            'USERNAME',
                            'BOTH',
                        ]) ? [
                            $oFormValidation::RULE_REQUIRED,
                            $oFormValidation::rule(
                                $oFormValidation::RULE_IS_UNIQUE, $oUserModel->getTableName(), 'username'
                            ),
                        ] : [],
                        'g-recaptcha-response' => [
                            function ($sToken) use ($oCaptchaService) {
                                if (appSetting('user_registration_captcha_enabled', 'auth')) {
                                    if (!$oCaptchaService->verify($sToken)) {
                                        throw new \Nails\Common\Exception\ValidationException(
                                            lang('auth_register_captcha_fail')
                                        );
                                    }
                                }
                            },
                        ],
                    ])
                    ->setMessages([
                        $oFormValidation::RULE_IS_UNIQUE => implode('', [
                            Config::get('APP_NATIVE_LOGIN_USING') === 'EMAIL'
                                ? lang('auth_register_email_is_unique', siteUrl('auth/password/forgotten'))
                                : null,
                            Config::get('APP_NATIVE_LOGIN_USING') === 'USERNAME'
                                ? lang('auth_register_username_is_unique', siteUrl('auth/password/forgotten'))
                                : null,
                            Config::get('APP_NATIVE_LOGIN_USING') === 'BOTH'
                                ? lang('auth_register_identity_is_unique', siteUrl('auth/password/forgotten'))
                                : null,
                        ]),
                    ])
                    ->run();

                // --------------------------------------------------------------------------

                //  Attempt the registration
                $aInsertData = [
                    'email'       => trim(strtolower($oInput->post('email'))),
                    'username'    => trim($oInput->post('username')),
                    'group_id'    => $iDefaultGroupId,
                    'password'    => $oInput->post('password'),
                    'first_name'  => trim($oInput->post('first_name')),
                    'last_name'   => trim($oInput->post('last_name')),
                    'referred_by' => (int) $oSession->getUserData('referred_by') ?: null,
                ];

                // --------------------------------------------------------------------------

                /** @var \Nails\Auth\Model\User $oUserModel */
                $oUserModel = Factory::model('User', Constants::MODULE_SLUG);
                $oUser      = $oUserModel->create($aInsertData);

                if (empty($oUser)) {
                    throw new \Nails\Auth\Exception\AuthException(
                        'Could not create new user account. ' . $oUserModel->lastError()
                    );
                }

                //  Create an event for this event
                createUserEvent('did_register', ['method' => 'native'], null, $oUser->id);

                //  Log the user in
                if (!$oUserModel->setLoginData($oUser->id)) {
                    //  Login failed for some reason, send them to the login page to try again
                    redirect('auth/login');

                } else {
                    $this->oUserFeedback->success(lang('auth_register_flashdata_welcome', $oUser->first_name));
                }

                // --------------------------------------------------------------------------

                //  Redirect to the group homepage
                //  @todo (Pablo - 2017-07-11) - Setting for forced email activation
                //  @todo (Pablo - 2017-07-11) - Handle setting MFA questions and/or devices

                $oGroup = $oUserGroupModel->getById($oUser->group_id);

                if ($oGroup->registration_redirect) {
                    $sRedirectUrl = $oGroup->registration_redirect;
                } else {
                    $sRedirectUrl = $oGroup->default_homepage;
                }

                redirect($sRedirectUrl);

            } catch (\Nails\Common\Exception\ValidationException $e) {
                $this->oUserFeedback->error($e->getMessage());

            } catch (AuthException $e) {
                $this->oUserFeedback->error($e->getMessage());
            }
        }

        // --------------------------------------------------------------------------

        /** @var SocialSignOn $oSocial */
        $oSocial = Factory::service('SocialSignOn', Constants::MODULE_SLUG);
        /** @var Password $oUserPasswordModel */
        $oUserPasswordModel = Factory::model('UserPassword', Constants::MODULE_SLUG);

        $this->data['social_signon_enabled']   = $oSocial->isEnabled();
        $this->data['social_signon_providers'] = $oSocial->getProviders('ENABLED');
        $this->data['passwordRulesAsString']   = $oUserPasswordModel->getRulesAsString($iDefaultGroupId);

        // --------------------------------------------------------------------------

        $this->loadStyles(Config::get('NAILS_APP_PATH') . 'application/modules/auth/views/register/form.php');

        //  Re-boot captcha as loadStyles clears everything
        if (appSetting('user_registration_captcha_enabled', 'auth')) {
            $oCaptchaService->boot();
        }

        // --------------------------------------------------------------------------

        Factory::service('View')
            ->load([
                'structure/header/blank',
                'auth/register/form',
                'structure/footer/blank',
            ]);
    }
}

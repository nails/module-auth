<?php

/**
 * Forgotten password facility
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    Controller
 * @author      Nails Dev Team
 * @link
 */

use Nails\Auth\Constants;
use Nails\Auth\Controller\Base;
use Nails\Auth\Factory\Email\ForgottenPassword;
use Nails\Auth\Model\User;
use Nails\Auth\Model\User\Password;
use Nails\Auth\Validator\User\Identifier;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\NailsException;
use Nails\Common\Service\Config;
use Nails\Common\Service\Input;
use Nails\Common\Service\Uri;
use Nails\Factory;

/**
 * Class PasswordForgotten
 */
class PasswordForgotten extends Base
{
    /**
     * Constructor
     **/
    public function __construct()
    {
        parent::__construct();
        $this->data['page']->title = lang('auth_title_forgotten_password');
    }

    // --------------------------------------------------------------------------

    /**
     * Reset password form
     *
     * @return  void
     *
     * @throws FactoryException
     */
    public function index()
    {
        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        /** @var Config $oConfig */
        $oConfig = Factory::service('Config');
        /** @var Password $oUserPasswordModel */
        $oUserPasswordModel = Factory::model('UserPassword', Constants::MODULE_SLUG);
        /** @var User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);
        /** @var \Nails\Captcha\Service\Captcha $oCaptchaService */
        $oCaptchaService = Factory::service('Captcha', Nails\Captcha\Constants::MODULE_SLUG);

        // --------------------------------------------------------------------------

        if ($oInput->post() || $oInput->get('identifier')) {

            try {

                //  Define vars
                $sIdentifier = $oInput->post('identifier');

                /**
                 * Override with the $_GET variable if POST failed to return anything. Populate
                 * the $_POST var with some data so form validation continues as normal, feels
                 * hacky but works.
                 */

                if (!$sIdentifier && $oInput->get('identifier')) {
                    $_POST['identifier'] = $oInput->get('identifier');
                    $sIdentifier         = $oInput->get('identifier');
                }

                // --------------------------------------------------------------------------

                (new Identifier())->run($oInput->post());

                if (appSetting('user_password_reset_captcha_enabled', 'auth')) {
                    if (!$oCaptchaService->verify()) {
                        throw new \Nails\Common\Exception\ValidationException(
                            'You failed the captcha test.'
                        );
                    }
                }

                // --------------------------------------------------------------------------

                /** @var \Nails\Auth\Resource\User $oUser */
                $oUser = $oUserModel
                    ->skipCache()
                    ->getByIdentifier($sIdentifier);

                if ($oUser && $oUserPasswordModel->isTokenDebouncing($oUser->forgotten_password_code)) {

                    $oNow             = Factory::factory('DateTime');
                    $oDebounceExpires = \DateTime::createFromFormat(
                        'U',
                        $oUserPasswordModel->extractTokenDebounce($oUser->forgotten_password_code)
                    );
                    $oDiff            = $oNow->diff($oDebounceExpires);
                    $iWaitMins        = $oDiff->i + ($oDiff->s ? 1 : 0);

                    throw new NailsException(
                        sprintf(
                            'You have recently requested a password reset, please wait %s minute%s before trying again. Remember to check your junk if you have not received the email.',
                            $iWaitMins,
                            $iWaitMins ? 's' : ''
                        )
                    );
                }

                $bAlwaysSucceed  = $oConfig->item('authForgottenPassAlwaysSucceed');
                $bGeneratedToken = $oUserPasswordModel->setToken($oUser);

                //  Attempt to reset password
                if ($bGeneratedToken) {

                    //  Refresh the User object
                    $oUser = $oUserModel->getById($oUser->id);

                    if (!$bAlwaysSucceed && empty($oUser->email)) {
                        throw new NailsException(
                            lang('auth_forgot_email_fail_no_email')
                        );
                    }

                    //  Send forgotten password email
                    [$sTTL, $sKey] = array_pad(explode(':', $oUser->forgotten_password_code), 2, null);

                    /** @var ForgottenPassword $oEmail */
                    $oEmail = Factory::factory('EmailForgottenPassword', Constants::MODULE_SLUG);
                    $oEmail
                        ->to($oUser)
                        ->data([
                            'resetUrl'   => siteUrl('auth/password/forgotten/' . $sKey),
                            'identifier' => $oUser->email,
                        ]);

                    try {

                        $oEmail->send();

                    } catch (\Exception $e) {
                        if (!$bAlwaysSucceed) {
                            throw new NailsException(lang('auth_forgot_email_fail'), null, $e);
                        }
                    }

                } elseif (!$bAlwaysSucceed) {
                    throw new NailsException(
                        lang('auth_forgot_code_not_set')
                    );
                }

                $this->oUserFeedback->success(lang('auth_forgot_success'));
                redirect(loginUrl(null));

            } catch (Exception $e) {
                $this->oUserFeedback->error($e->getMessage());
            }
        }

        //  Load the views
        $this->loadStyles(\Nails\Config::get('NAILS_APP_PATH') . 'application/modules/auth/views/password/forgotten.php');

        //  Re-boot captcha as loadStyles clears everything
        if (appSetting('user_password_reset_captcha_enabled', 'auth')) {
            $oCaptchaService->boot();
        }

        Factory::service('View')
            ->load([
                'structure/header/blank',
                'auth/password/forgotten',
                'structure/footer/blank',
            ]);
    }

    // --------------------------------------------------------------------------

    /**
     * Validate a code
     *
     * @param string $sCode The code to validate
     *
     * @throws FactoryException
     */
    public function _validate($sCode)
    {
        /** @var Password $oUserPasswordModel */
        $oUserPasswordModel = Factory::model('UserPassword', Constants::MODULE_SLUG);

        $mNewPassword = $oUserPasswordModel->validateToken($sCode, true);

        // --------------------------------------------------------------------------

        if ($mNewPassword === 'EXPIRED') {

            $this->oUserFeedback->error(lang('auth_forgot_expired_code'));

        } elseif ($mNewPassword === false) {

            $this->oUserFeedback->error(lang('auth_forgot_invalid_code'));

        } else {

            $this->oUserFeedback->warning(lang('auth_forgot_reminder', htmlentities($mNewPassword['password'])));

            $this->loadStyles(
                \Nails\Config::get('NAILS_APP_PATH') . 'application/modules/auth/views/password/forgotten_reset.php'
            );
            Factory::service('View')
                ->setData([
                    'new_password' => $mNewPassword['password'],
                    'user'         => (object) [
                        'id'       => $mNewPassword['user_id'],
                        'identity' => $mNewPassword['user_identity'],
                    ],
                ])
                ->load([
                    'structure/header/blank',
                    'auth/password/forgotten_reset',
                    'structure/footer/blank',
                ]);

            return;
        }

        // --------------------------------------------------------------------------

        $this->loadStyles(\Nails\Config::get('NAILS_APP_PATH') . 'application/modules/auth/views/password/forgotten.php');
        Factory::service('View')
            ->load([
                'structure/header/blank',
                'auth/password/forgotten',
                'structure/footer/blank',
            ]);
    }

    // --------------------------------------------------------------------------

    /**
     * Route requests to the right method
     *
     * @param string $sMethod The method being called
     *
     * @throws FactoryException
     */
    public function _remap($sMethod)
    {
        //  If you're logged in you shouldn't be accessing this method
        if (isLoggedIn()) {
            $this->oUserFeedback->error(lang('auth_no_access_already_logged_in', activeUser('email')));
            redirect('/');
        }

        // --------------------------------------------------------------------------

        /** @var Uri $oUri */
        $oUri = Factory::service('Uri');

        if ($sMethod == 'index') {
            $this->index();
        } elseif ($oUri->segment(5) !== 'process') {

            //  @todo (Pablo - 2019-12-10) - Remove this once https://github.com/nails/module-auth/issues/36 is resolved
            $this->loadStyles(\Nails\Config::get('NAILS_APP_PATH') . 'application/modules/auth/views/password/forgotten_interstitial.php');
            Factory::service('View')
                ->load([
                    'structure/header/blank',
                    'auth/password/forgotten_interstitial',
                    'structure/footer/blank',
                ]);

        } else {
            $this->_validate($sMethod);
        }
    }
}

<?php

/**
 * Handles Multi-Factor Authentication when authTypeMode is 'DEVICE'
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    Controller
 * @author      Nails Dev Team
 * @link
 */

use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\ValidationException;
use Nails\Common\Service\FormValidation;
use Nails\Common\Service\Input;
use Nails\Factory;
use Nails\Auth\Controller\BaseMfa;
use Nails\Auth\Constants;
use Nails\Auth\Service\Authentication;

/**
 * Class MfaDevice
 */
class MfaDevice extends BaseMfa
{
    /**
     * Ensures we're use the correct MFA type
     *
     * @throws FactoryException
     */
    public function _remap()
    {
        if ($this->authMfaMode == 'DEVICE') {
            $this->index();
        } else {
            show404();
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Remaps requests to the correct method
     *
     * @throws FactoryException
     */
    public function index()
    {
        //  Validates the request token and generates a new one for the next request
        $this->validateToken();

        // --------------------------------------------------------------------------

        //  Has this user already set up an MFA?
        /** @var Authentication $oAuthService */
        $oAuthService = Factory::service('Authentication', Constants::MODULE_SLUG);
        $oMfaDevice   = $oAuthService->mfaDeviceSecretGet($this->mfaUser->id);

        if ($oMfaDevice) {
            $this->requestCode();
        } else {
            $this->setupDevice();
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Sets up a new MFA device
     *
     * @throws FactoryException
     */
    protected function setupDevice()
    {
        /** @var Authentication $oAuthService */
        $oAuthService = Factory::service('Authentication', Constants::MODULE_SLUG);
        /** @var Input $oInput */
        $oInput = Factory::service('Input');

        if ($oInput->post()) {

            /** @var FormValidation $oFormValidation */
            $oFormValidation = Factory::service('FormValidation');

            try {

                $oFormValidation
                    ->buildValidator([
                        'mfa_secret' => [FormValidation::RULE_REQUIRED],
                        'mfa_code'   => [FormValidation::RULE_REQUIRED],
                    ])
                    ->run();

                $sSecret  = $oInput->post('mfa_secret');
                $sMfaCode = $oInput->post('mfa_code');

                //  Verify the inout
                if ($oAuthService->mfaDeviceSecretValidate($this->mfaUser->id, $sSecret, $sMfaCode)) {

                    //  Codes have been validated and saved to the DB, sign the user in and move on
                    $this->oUserFeedback->success(
                        '<strong>Multi Factor Authentication Enabled!</strong><br />You successfully ' .
                        'associated an MFA device with your account. You will be required to use it ' .
                        'the next time you log in.'
                    );

                    $this->loginUser();

                } else {
                    $this->oUserFeedback->error('Sorry, that code failed to validate. Please try again.');
                }

            } catch (ValidationException $e) {
                $this->oUserFeedback->error($e->getMessage());
            }
        }

        //  Generate the secret
        $this->data['secret'] = $oAuthService->mfaDeviceSecretGenerate(
            $this->mfaUser->id,
            $oInput->post('mfa_secret', true)
        );

        if (!$this->data['secret']) {
            $this->oUserFeedback->error('<strong>Sorry,</strong> it has not been possible to get an MFA device set up for this user. ' . $oAuthService->lastError());
            redirect(loginUrl($this->returnTo ?: false));
        }

        // --------------------------------------------------------------------------

        $this->data['page']->title = 'Set up a new MFA device';
        $this->loadStyles(NAILS_APP_PATH . 'application/modules/auth/views/mfa/device/setup.php');
        Factory::service('View')
            ->load([
                'structure/header/blank',
                'auth/mfa/device/setup',
                'structure/footer/blank',
            ]);
    }

    // --------------------------------------------------------------------------

    /**
     * Requests a code from the user
     *
     * @throws FactoryException
     */
    protected function requestCode()
    {
        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        if ($oInput->post()) {

            /** @var FormValidation $oFormValidation */
            $oFormValidation = Factory::service('FormValidation');

            try {

                $oFormValidation
                    ->buildValidator([
                        'mfa_code' => [FormValidation::RULE_REQUIRED],
                    ])
                    ->run();

                /** @var Authentication $oAuthService */
                $oAuthService = Factory::service('Authentication', Constants::MODULE_SLUG);
                $sMfaCode     = $oInput->post('mfa_code');

                //  Verify the inout
                if ($oAuthService->mfaDeviceCodeValidate($this->mfaUser->id, $sMfaCode)) {
                    $this->loginUser();
                } else {
                    $this->oUserFeedback->error(sprintf(
                        'Sorry, that code failed to validate. Please try again. %s',
                        $oAuthService->lastError()
                    ));
                }

            } catch (ValidationException $e) {
                $this->oUserFeedback->error($e->getMessage());
            }
        }

        // --------------------------------------------------------------------------

        $this->data['page']->title = 'Enter your Code';
        $this->loadStyles(NAILS_APP_PATH . 'application/modules/auth/views/mfa/device/ask.php');
        Factory::service('View')
            ->load([
                'structure/header/blank',
                'auth/mfa/device/ask',
                'structure/footer/blank',
            ]);
    }
}

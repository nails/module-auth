<?php

use App\Controller\Base;
use App\Model\User\Meta\Role;
use Nails\Auth\Service\Session;
use Nails\Common\Exception\ValidationException;
use Nails\Common\Service\FormValidation;
use Nails\Common\Service\Uri;
use Nails\Factory;

class Register extends Base
{
    /**
     * Handles initial user registration
     *
     * @return void
     */
    public function index()
    {
        if (isLoggedIn()) {
            $this->oUserFeedback->error('You cannot register an account whilst logged in.');
            redirect();
        }

        $oInput = Factory::service('Input');
        if ($oInput->post()) {
            $aRules = [
                'areas[]'           => 'required',
                'roles[]'           => 'required',
                'category_topics[]' => '',
                'practice_id'       => '',
                'salutation'        => 'required',
                'first_name'        => 'required',
                'last_name'         => 'required',
                'email'             => 'required|is_unique[' . NAILS_DB_PREFIX . 'user_email.email]|valid_email|callback_callbackValidEmail',
                'password'          => 'required',
            ];

            /** @var FormValidation $oFormValidation */
            $oFormValidation = Factory::service('FormValidation');
            /** @var \Nails\Auth\Model\User\Email $oUserEmailModel */
            $oUserEmailModel = Factory::model('UserEmail', \Nails\Auth\Constants::MODULE_SLUG);
            /** @var \Nails\Auth\Model\User\Password $oUserPasswordModel */
            $oUserPasswordModel = Factory::model('UserPassword', \Nails\Auth\Constants::MODULE_SLUG);
            /** @var \Nails\Auth\Model\User\Group $oUserGroupModel */
            $oUserGroupModel = Factory::model('UserGroup', \Nails\Auth\Constants::MODULE_SLUG);

            try {

                $oFormValidation
                    ->buildValidator([
                        'areas[]'     => [
                            FormValidation::RULE_REQUIRED,
                        ],
                        'roles[]'     => [
                            FormValidation::RULE_REQUIRED,
                        ],
                        'practice_id' => [
                            FormValidation::RULE_REQUIRED,
                        ],
                        'salutation'  => [
                            FormValidation::RULE_REQUIRED,
                        ],
                        'first_name'  => [
                            FormValidation::RULE_REQUIRED,
                        ],
                        'last_name'   => [
                            FormValidation::RULE_REQUIRED,
                        ],
                        'email'       => [
                            FormValidation::RULE_REQUIRED,
                            FormValidation::RULE_VALID_EMAIL,
                            FormValidation::rule(FormValidation::RULE_IS_UNIQUE, $oUserEmailModel->getTableName(), 'email'),
                            function ($sEmail) {
                                if (!isValidNhsEmail($sEmail)) {
                                    throw new ValidationException(
                                        'You can only use a valid NHS email address with your account.'
                                    );
                                }
                            },
                        ],
                        'password'    => [
                            FormValidation::RULE_REQUIRED,
                            function ($sPassword) use ($oUserPasswordModel, $oUserGroupModel) {
                                $oDefaultGroup = $oUserGroupModel->getDefaultGroup();
                                if (!$oUserPasswordModel->isAcceptable($oDefaultGroup, $sPassword)) {
                                    throw new ValidationException(
                                        $oUserPasswordModel->lastError()
                                    );
                                }
                            },
                        ],
                    ])
                    ->setMessages([
                        FormValidation::RULE_IS_UNIQUE => sprintf(
                            'The email address specified is already associated with an account. Please use the %s tool to gain access to your account.',
                            anchor('auth/password/forgotten', 'forgotten password')
                        ),
                    ])
                    ->run();

                $aUserData = [
                    'salutation'  => $oInput->post('salutation'),
                    'first_name'  => $oInput->post('first_name'),
                    'last_name'   => $oInput->post('last_name'),
                    'email'       => $oInput->post('email'),
                    'password'    => $oInput->post('password'),
                    'practice_id' => (int) $oInput->post('practice_id') ?: null,
                ];

                //  Create the user
                /** @var Session $oSession */
                $oSession = Factory::service('Session');
                /** @var \App\Auth\Model\User $oUserModel */
                $oUserModel = Factory::model('User', 'nails/module-auth');
                /** @var \App\Resource\User\Meta\Area $oUserAreaModel */
                $oUserAreaModel = Factory::model('UserMetaArea', 'app');
                /** @var Role $oUserRoleModel */
                $oUserRoleModel = Factory::model('UserMetaRole', 'app');
                /** @var \App\Model\User\Meta\Category\Topic $oUserCategoryTopicModel */
                $oUserCategoryTopicModel = Factory::model('UserMetaCategoryTopic', 'app');

                $oUser = $oUserModel->create($aUserData);

                //  Add areas, roles and interests
                $oUserAreaModel->createMany(array_map(function ($iArea) use ($oUser) {
                    return [
                        'user_id' => $oUser->id,
                        'area_id' => (int) $iArea,
                    ];
                }, array_filter((array) $oInput->post('areas'))));
                $oUserRoleModel->createMany(array_map(function ($iRole) use ($oUser) {
                    return [
                        'user_id' => $oUser->id,
                        'role_id' => (int) $iRole,
                    ];
                }, array_filter((array) $oInput->post('roles'))));
                $oUserCategoryTopicModel->createMany(array_map(function ($iCategoryId) use ($oUser) {
                    return [
                        'user_id'           => $oUser->id,
                        'category_topic_id' => (int) $iCategoryId,
                    ];
                }, array_filter((array) $oInput->post('category_topics'))));

                if (!empty($oUser) && !(bool) getModuleSetting('require_verified_email')) {

                    //  Log the user in
                    $oUserModel->setLoginData($oUser->id);

                    //  If we have a return URL, set the return_to
                    $sReturnUrl = $oInput->get('return_to');

                    //  Handle the submissions redirect flow
                    if (empty($sReturnUrl) && $oSession->getUserData('submission_review_register')) {
                        $sReturnUrl = $oSession->getUserData('submission_review_register');
                        $oSession->unsetUserData('submission_review_register');
                    }

                    //  Set the default success message
                    $sSuccess = 'Your account has been created. Please check your email and verify your account by clicking on the link you receive.';

                    if ($sReturnUrl) {

                        if (strpos($sReturnUrl, 'events') !== false) {
                            $sSuccess .= '<br>You now have a member account on the North Central London GP Website. You may now register for this event using the options at the top right of this page.';
                        }

                        $this->oUserFeedback->success($sSuccess);
                        redirect($sReturnUrl);
                    } else {

                        $this->oUserFeedback->success($sSuccess);
                        redirect('/');
                    }

                } elseif (!empty($oUser) && (bool) getModuleSetting('require_verified_email')) {

                    //  Log the user in
                    $oUserModel->setLoginData($oUser->id);

                    $this->oUserFeedback->success('Thank you for registering, your account has been created. Please now verify your email address.');
                    redirect(registerUrl(false) . '/verify');

                } else {
                    $this->oUserFeedback->error('Sorry, there was a problem creating your account. Please try again.');
                }

            } catch (ValidationException $e) {
                $this->oUserFeedback->error($e->getMessage());
                $this->data['form_errors'] = $e->getData();
            }
        }

        // --------------------------------------------------------------------------

        //  Fetch list of areas
        $oAreaModel          = Factory::model('Area', 'app');
        $this->data['areas'] = $oAreaModel->getAllFlat();

        // --------------------------------------------------------------------------

        //  Fetch list of roles
        $oRoleModel          = Factory::model('Role', 'app');
        $this->data['roles'] = $oRoleModel->getAllFlat();

        // --------------------------------------------------------------------------

        //  Fetch list of practices (flat and full)
        $oPracticeModel          = Factory::model('Practice', 'app');
        $this->data['practices'] = $oPracticeModel->getAllFlat();
        $oPracticesFull          = $oPracticeModel->getAll();

        // --------------------------------------------------------------------------

        //  Fetch list of roles
        $oCategoryTopicModel           = Factory::model('CategoryTopic', 'app');
        $this->data['category_topics'] = $oCategoryTopicModel->getAllFlat();

        // --------------------------------------------------------------------------

        $oAsset = Factory::service('Asset');
        $oAsset->load('app.register.min.js', 'APP');
        $oAsset->inline('var register = new REGISTER_JS()', 'JS');

        // --------------------------------------------------------------------------

        $this->oMetaData
            ->setTitles(['Register'])
            ->setDescription('Register for an account on the North Central London GP Website')
            ->setKeywords(['member', 'register']);

        // --------------------------------------------------------------------------

        $oView = Factory::service('View');
        $oView->load('structure/header', $this->data);
        $oView->load('register/index', $this->data);
        $oView->load('structure/footer', $this->data);
    }

    // --------------------------------------------------------------------------

    public function verify_resend()
    {
        if (!isLoggedIn()) {
            unauthorised('Please log in to resend your verification email.');
        }

        $oEmailer        = Factory::service('Emailer', 'nails/module-email');
        $email           = new \stdClass();
        $email->type     = 'register_verify';
        $email->to_email = activeUser('email');

        if (!$oEmailer->send($email)) {
            throw new \Exception('Failed to send request. ' . $oEmailer->lastError());
        } else {
            $this->oUserFeedback->success('Please check your inbox (and junk folders) for your verification email');
            redirect(registerUrl(false) . '/verify');
        }
    }

    // --------------------------------------------------------------------------

    public function verify()
    {
        if (!(bool) getModuleSetting('require_verified_email')) {
            show404();

        } elseif (activeUser('email_is_verified')) {
            redirect('/');
        }

        /** @var \App\Auth\Model\User $oUserModel */
        $oUserModel = Factory::model('User', 'nails/module-auth');

        /** @var Uri $oUri */
        $oUri = Factory::service('Uri');

        $sCode  = $oUri->segment(3);
        $sEmail = $oUri->segment(4);

        if (empty($sCode) && empty($sEmail)) {

            Factory::service('View')
                ->load([
                    'structure/header',
                    'register/activate',
                    'structure/footer',
                ]);

        } else {

            $oUser = $oUserModel->getByEmail($sEmail);

            if (!empty($oUser) && $oUser->email_is_verified) {

                $this->oUserFeedback->success('Thanks for verifying your email address');
                $oUserModel->setLoginData($oUser->id);

            } elseif (!empty($oUser) && $oUser->email_verification_code === $sCode) {

                if ($oUserModel->emailVerify($sEmail, $sCode)) {
                    $this->oUserFeedback->success('Thanks for verifying your email address');
                    $oUserModel->setLoginData($oUser->id);
                } else {
                    $this->oUserFeedback->error('Failed to verify email address');
                }

            } else {
                show404();
            }

            redirect('/');
        }
    }
}

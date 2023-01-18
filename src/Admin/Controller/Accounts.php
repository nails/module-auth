<?php

/**
 * This class provides account management functionality to Admin
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    AdminController
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Auth\Admin\Controller;

use Nails\Admin\Controller\DefaultController;
use Nails\Admin\Factory\Nav;
use Nails\Admin\Helper;
use Nails\Admin\Model\ChangeLog;
use Nails\Auth\Admin\Permission;
use Nails\Auth\Constants;
use Nails\Auth\Interfaces\Admin\User\Tab;
use Nails\Auth\Model\User;
use Nails\Auth\Model\User\Group;
use Nails\Auth\Model\User\Password;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\ModelException;
use Nails\Common\Exception\NailsException;
use Nails\Common\Exception\ValidationException;
use Nails\Common\Factory\Component;
use Nails\Common\Helper\Directory;
use Nails\Common\Helper\Model\Where;
use Nails\Common\Service\FormValidation;
use Nails\Common\Service\Input;
use Nails\Common\Service\UserFeedback;
use Nails\Common\Service\Uri;
use Nails\Components;
use Nails\Config;
use Nails\Factory;
use stdClass;

/**
 * Class Accounts
 *
 * @package Nails\Admin\Auth
 */
class Accounts extends DefaultController
{
    const CONFIG_MODEL_NAME          = 'User';
    const CONFIG_MODEL_PROVIDER      = Constants::MODULE_SLUG;
    const CONFIG_SORT_DIRECTION      = 'desc';
    const EDIT_ENABLE_MODIFIED_CHECK = false;
    const CONFIG_INDEX_DATA          = [
        'expand' => ['group'],
    ];
    const CONFIG_INDEX_FIELDS        = [
        'ID'          => 'id',
        'User'        => 'id',
        'Login Count' => 'login_count',
        'Registered'  => 'created',
        'Last Login'  => 'last_login',
        'Last Seen'   => 'last_seen',
    ];
    const CONFIG_SORT_OPTIONS        = [
        'ID'          => 'id',
        'First name'  => 'first_name',
        'Surname'     => 'last_name',
        'Login Count' => 'login_count',
        'Registered'  => 'created',
        'Last Seen'   => 'last_seen',
        'Last Login'  => 'last_login',
    ];
    const CONFIG_PERMISSION_BROWSE   = Permission\Users\Browse::class;
    const CONFIG_PERMISSION_CREATE   = Permission\Users\Create::class;
    const CONFIG_PERMISSION_DELETE   = Permission\Users\Delete::class;
    const CONFIG_PERMISSION_EDIT     = Permission\Users\Edit::class;

    // --------------------------------------------------------------------------

    /**
     * The ChangeLog model
     *
     * @var ChangeLog
     */
    protected $oChangeLogModel;

    // --------------------------------------------------------------------------

    /**
     * Announces this controller's navGroups
     *
     * @return Nav
     * @throws FactoryException
     */
    public static function announce()
    {
        /** @var Nav $oNavGroup */
        $oNavGroup = Factory::factory('Nav', \Nails\Admin\Constants::MODULE_SLUG);
        $oNavGroup
            ->setLabel('Users')
            ->setIcon('fa-users');

        if (userHasPermission(Permission\Users\Browse::class)) {

            $oModel = Factory::model('User', Constants::MODULE_SLUG);

            $iNumTotal = $oModel->countAll();

            /** @var \Nails\Admin\Factory\Nav\Alert $oAlertTotal */
            $oAlertTotal = Factory::factory('NavAlert', \Nails\Admin\Constants::MODULE_SLUG);
            $oAlertTotal
                ->setValue($iNumTotal)
                ->setLabel('Number of Users');

            $iNumSuspended = $oModel->countAll([
                new Where('is_suspended', true),
            ]);

            if ($iNumSuspended) {
                /** @var \Nails\Admin\Factory\Nav\Alert $oAlertSuspended */
                $oAlertSuspended = Factory::factory('NavAlert', \Nails\Admin\Constants::MODULE_SLUG);
                $oAlertSuspended
                    ->setValue($iNumSuspended)
                    ->setSeverity('danger')
                    ->setLabel('Number of Suspended Users');
            }

            $oNavGroup->addAction('View All Users', 'index', array_filter([$oAlertTotal, $oAlertSuspended ?? null]), 0);
        }

        return $oNavGroup;
    }

    // --------------------------------------------------------------------------

    /**
     * Accounts constructor.
     *
     * @throws FactoryException
     * @throws NailsException
     */
    public function __construct()
    {
        parent::__construct();

        // --------------------------------------------------------------------------

        /** @var Input $oInput */
        $oInput  = Factory::service('Input');
        $sReturn = $oInput->server('request_uri');

        // --------------------------------------------------------------------------

        //  Remove isModal parameter
        $aUrl   = parse_url($sReturn);
        $sPath  = getFromArray('path', $aUrl);
        $sQuery = getFromArray('query', $aUrl);
        parse_str($sQuery, $aQuery);
        unset($aQuery['isModal']);
        $sQuery  = http_build_query($aQuery);
        $sReturn = $sQuery ? $sPath . '?' . $sQuery : $sPath;
        $sReturn = urlencode($sReturn);

        // --------------------------------------------------------------------------

        $this->aConfig['INDEX_ROW_BUTTONS'] = array_merge(
            $this->aConfig['INDEX_ROW_BUTTONS'],
            [
                [
                    'url'     => \Nails\Auth\Helper\User::compileLoginUrl('{{id_md5}}', '{{password_md5}}'),
                    'label'   => 'Login As',
                    'class'   => 'btn-warning',
                    'enabled' => function ($oUser) {
                        /**
                         * Requirements
                         * - target user is not active user
                         * - active user has loginAs permission
                         * - target user is not suspended
                         * - if target user is a superuser active user must also be a superuser
                         */
                        return $oUser->id !== activeUser('id')
                            && userHasPermission(Permission\Users\LoginAs::class)
                            && !$oUser->is_suspended
                            && $this->activeUserCanEditSuperUser($oUser);
                    },
                ],
                [
                    'url'     => 'suspend/{{id}}?return_to=' . $sReturn,
                    'label'   => 'Suspend',
                    'class'   => 'btn-danger',
                    'enabled' => function ($oUser) {
                        /**
                         * Requirements
                         * - target user is not active user
                         * - active user has suspend permission
                         * - target user is not suspended
                         * - if target user is a superuser active user must also be a superuser
                         */
                        return $oUser->id !== activeUser('id')
                            && userHasPermission(Permission\Users\Suspend::class)
                            && !$oUser->is_suspended
                            && $this->activeUserCanEditSuperUser($oUser);
                    },
                ],
                [
                    'url'     => 'unsuspend/{{id}}?return_to=' . $sReturn,
                    'label'   => 'Unsuspend',
                    'class'   => 'btn-success',
                    'enabled' => function ($oUser) {
                        /**
                         * Requirements
                         * - target user is not active user
                         * - active user has unsuspend permission
                         * - target user is suspended
                         * - if target user is a superuser active user must also be a superuser
                         */
                        return $oUser->id !== activeUser('id')
                            && userHasPermission(Permission\Users\Suspend::class)
                            && $oUser->is_suspended
                            && $this->activeUserCanEditSuperUser($oUser);
                    },
                ],
                [
                    'url'     => self::url('change_group?users={{id}}'),
                    'label'   => 'Change Group',
                    'class'   => 'btn-default',
                    'enabled' => function ($oUser) {
                        /**
                         * Requirements
                         * - target user is not active user
                         * - active user has changeUserGroup permission
                         * - if target user is a superuser active user must also be a superuser
                         */
                        return userHasPermission(Permission\Users\Group\Change::class) && $this->activeUserCanEditSuperUser($oUser);
                    },
                ],
            ]
        );

        // --------------------------------------------------------------------------

        //  Override the edit and delete `enabled` behavour to add additional checks
        foreach ($this->aConfig['INDEX_ROW_BUTTONS'] as &$aButton) {
            if ($aButton['label'] === lang('action_edit')) {

                $aButton['enabled'] = function ($oUser) {
                    return ($oUser->id === activeUser('id') || userHasPermission(Permission\Users\Edit::class)) &&
                        !(!isSuperUser() && isSuperUser($oUser));
                };

            } elseif ($aButton['label'] === lang('action_delete')) {

                $aButton['enabled'] = function ($oUser) {
                    return static::isDeleteButtonEnabled($oUser) &&
                        $oUser->id !== activeUser('id') &&
                        !(!isSuperUser() && isSuperUser($oUser));
                };
            }
        }

        // --------------------------------------------------------------------------

        $this->aConfig['INDEX_USER_FIELDS'][]     = 'user';
        $this->aConfig['INDEX_CENTERED_FIELDS'][] = 'login_count';
        $this->aConfig['INDEX_CENTERED_FIELDS'][] = 'group_name';
        $this->aConfig['INDEX_NUMERIC_FIELDS'][]  = 'login_count';

        // --------------------------------------------------------------------------

        if (userHasPermission(Permission\Users\Create::class)) {
            $this->aConfig['INDEX_HEADER_BUTTONS'][] = [
                'label' => 'Import',
                'url'   => Import::url(),
            ];
        }

        // --------------------------------------------------------------------------

        get_instance()->lang->load('admin_accounts');
        /** @var ChangeLog oChangeLogModel */
        $this->oChangeLogModel = Factory::model('ChangeLog', \Nails\Admin\Constants::MODULE_SLUG);
    }

    // --------------------------------------------------------------------------

    /**
     * Determines whether the active user can edit the target superuser
     *
     * @param stdClass $oUser The user to check
     *
     * @return bool
     */
    protected function activeUserCanEditSuperUser($oUser): bool
    {
        return !(!isSuperUser() && isSuperUser($oUser));
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the available checkbox filters
     *
     * @return array
     * @throws FactoryException
     */
    protected function indexCheckboxFilters(): array
    {
        /** @var Group $oGroupModel */
        $oGroupModel = Factory::model('UserGroup', Constants::MODULE_SLUG);
        $aGroups     = $oGroupModel->getAll();

        return array_merge(
            parent::indexCheckboxFilters(),
            [
                Factory::factory('IndexFilter', \Nails\Admin\Constants::MODULE_SLUG)
                    ->setLabel('Group')
                    ->setColumn('group_id')
                    ->addOptions(array_map(function ($oGroup) {
                        return Factory::factory('IndexFilterOption', \Nails\Admin\Constants::MODULE_SLUG)
                            ->setLabel($oGroup->label)
                            ->setValue($oGroup->id)
                            ->setIsSelected(true);
                    }, $aGroups)),
                Factory::factory('IndexFilter', \Nails\Admin\Constants::MODULE_SLUG)
                    ->setLabel('Suspended')
                    ->setColumn('is_suspended')
                    ->addOptions([
                        Factory::factory('IndexFilterOption', \Nails\Admin\Constants::MODULE_SLUG)
                            ->setLabel('Yes')
                            ->setValue(true),
                        Factory::factory('IndexFilterOption', \Nails\Admin\Constants::MODULE_SLUG)
                            ->setLabel('No')
                            ->setValue(false)
                            ->setIsSelected(true),
                    ]),
            ]
        );
    }

    // --------------------------------------------------------------------------

    /**
     * Create a new user account
     *
     * @throws FactoryException
     * @throws ModelException
     * @todo (Pablo - 2019-01-22) - Use the DefaultController create() method
     *
     */
    public function create(): void
    {
        if (!userHasPermission(Permission\Users\Create::class)) {
            unauthorised();
        }

        // --------------------------------------------------------------------------

        $this->setTitles([lang('accounts_create_title')]);

        // --------------------------------------------------------------------------

        //  Attempt to create the new user account
        /** @var Input $oInput */
        $oInput = Factory::service('Input');

        if ($oInput->post()) {

            /** @var FormValidation $oFormValidation */
            $oFormValidation = Factory::service('FormValidation');

            //  Set rules
            $oFormValidation->set_rules('group_id', '', 'required|is_natural_no_zero');
            $oFormValidation->set_rules('password', '', '');
            $oFormValidation->set_rules('send_activation', '', '');
            $oFormValidation->set_rules('temp_pw', '', '');
            $oFormValidation->set_rules('first_name', '', 'required|max_length[150]');
            $oFormValidation->set_rules('last_name', '', 'required|max_length[150]');
            $oFormValidation->set_rules('email', '', 'required|valid_email|is_unique[' . Config::get('NAILS_DB_PREFIX') . 'user_email.email]|max_length[255]');

            if (in_array(Config::get('APP_NATIVE_LOGIN_USING'), ['BOTH', 'USERNAME'])) {
                $oFormValidation->set_rules('username', '', 'required|max_length[150]|alpha_dash_period|is_unique[' . Config::get('NAILS_DB_PREFIX') . 'user.username]');
            }

            //  Set messages
            $oFormValidation->set_message('required', lang('fv_required'));
            $oFormValidation->set_message('min_length', lang('fv_min_length'));
            $oFormValidation->set_message('alpha_dash_period', lang('fv_alpha_dash_period'));
            $oFormValidation->set_message('is_natural_no_zero', lang('fv_required'));
            $oFormValidation->set_message('valid_email', lang('fv_valid_email'));
            $oFormValidation->set_message('is_unique', lang('fv_email_already_registered'));

            //  Execute
            if ($oFormValidation->run()) {

                //  Success
                $aData = [
                    'group_id' => (int) $oInput->post('group_id', true),
                    'password' => trim($oInput->post('password', true)),
                ];

                if (!$aData['password']) {
                    //  Password isn't set, generate one
                    /** @var Password $oUserPasswordModel */
                    $oUserPasswordModel = Factory::model('UserPassword', Constants::MODULE_SLUG);
                    $aData['password']  = $oUserPasswordModel->generate($aData['group_id']);
                }

                if ($oInput->post('email')) {
                    $aData['email'] = $oInput->post('email', true);
                }

                if ($oInput->post('username')) {
                    $aData['username'] = $oInput->post('username', true);
                }

                $aData['first_name']     = $oInput->post('first_name', true);
                $aData['last_name']      = $oInput->post('last_name', true);
                $aData['temp_pw']        = stringToBoolean($oInput->post('temp_pw', true));
                $aData['inform_user_pw'] = true;

                /** @var User $oUserModel */
                $oUserModel = Factory::model('User', Constants::MODULE_SLUG);
                $oNewUser   = $oUserModel->create($aData, stringToBoolean($oInput->post('send_activation', true)));

                if ($oNewUser) {

                    /**
                     * Any errors happen? While the user can be created successfully other problems
                     * might happen along the way
                     */

                    if ($oUserModel->getErrors()) {

                        $sMessage = '<strong>Please note:</strong> while the user was created successfully, the ';
                        $sMessage .= 'following issues were encountered:';
                        $sMessage .= '<ul><li>' . implode('</li><li>', $oUserModel->getErrors()) . '</li></ul>';

                        $this->oUserFeedback->warning($sMessage);
                    }

                    // --------------------------------------------------------------------------

                    //  Add item to admin changelog
                    $this->addToChangeLogCreate(
                        $oNewUser,
                    );

                    // --------------------------------------------------------------------------

                    $this->oUserFeedback->success(sprintf(
                        'A user account was created for <strong>%s</strong>, update their details now.',
                        $oNewUser->first_name
                    ));

                    redirect(
                        $oInput->get('isModal')
                            ? static::url('edit/' . $oNewUser->id . '?isModal=true')
                            : static::url('edit/' . $oNewUser->id)
                    );

                } else {
                    $this->oUserFeedback->error(sprintf(
                        'There was an error when creating the user account: <br />&rsaquo; %s',
                        implode('<br />&rsaquo; ', $oUserModel->getErrors())
                    ));
                }

            } else {
                $this->oUserFeedback->error(lang('fv_there_were_errors'));
            }
        }

        // --------------------------------------------------------------------------

        //  Get data for the view
        /** @var Group $oUserGroupModel */
        $oUserGroupModel = Factory::model('UserGroup', Constants::MODULE_SLUG);
        /** @var Password $oUserPasswordModel */
        $oUserPasswordModel = Factory::model('UserPassword', Constants::MODULE_SLUG);

        $this->data['groups']        = $oUserGroupModel->getAll();
        $this->data['passwordRules'] = [];

        foreach ($this->data['groups'] as $oGroup) {
            $this->data['passwordRules'][$oGroup->id] = $oUserPasswordModel->getRulesAsString($oGroup->id);
        }

        // --------------------------------------------------------------------------

        //  Load views
        Helper::loadView('create');
    }

    // --------------------------------------------------------------------------

    /**
     * Edit a user account
     *
     * @return void
     * @throws FactoryException
     * @throws ModelException
     * @throws NailsException
     */
    public function edit(): void
    {
        /** @var Uri $oUri */
        $oUri = Factory::service('Uri');
        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        /** @var User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);

        if ($oUri->segment(5) != activeUser('id') && !userHasPermission(Permission\Users\Edit::class)) {
            unauthorised();
        }

        // --------------------------------------------------------------------------

        /** @var \Nails\Auth\Resource\User $oUser */
        $oUser = $oUserModel->getById($oUri->segment(5));

        if (empty($oUser)) {
            $this->oUserFeedback->error(lang('accounts_edit_error_unknown_id'));
            $this->returnToIndex();

        } elseif (!isSuperUser() && isSuperUser($oUser)) {
            //  Non-superusers editing superusers is not cool
            $this->oUserFeedback->error(lang('accounts_edit_error_noteditable'));
            $this->returnToIndex();

        } elseif (activeUser('id') != $oUser->id && !userHasPermission(Permission\Users\Edit::class)) {
            //  Is this user editing someone other than themselves? If so, do they have permission?
            $this->oUserFeedback->error(lang('accounts_edit_error_noteditable'));
            $this->returnToIndex();
        }

        // --------------------------------------------------------------------------

        $aTabs = [];
        /** @var Component $oComponent */
        foreach (Components::available() as $oComponent) {

            $aClasses = $oComponent
                ->findClasses('Auth\\Admin\\User\\Tab')
                ->whichImplement(Tab::class);

            foreach ($aClasses as $sClass) {
                $aTabs[$sClass] = new $sClass();
            }
        }

        // --------------------------------------------------------------------------

        //  Validate if we're saving, otherwise get the data and display the edit form
        if ($oInput->post()) {

            try {

                /** @var FormValidation $oFormValidation */
                $oFormValidation = Factory::service('FormValidation');

                $aRules = [];
                $aData  = [];

                /** @var Tab $oTab */
                foreach ($aTabs as $oTab) {
                    $aRules = array_merge($aRules, $oTab->getValidationRules($oUser));
                    $aData  = array_merge($aData, $oTab->getPostData($oUser, $oInput->post()));
                }

                $oValidator = $oFormValidation->buildValidator($aRules, [], $aData);
                $oValidator->run();

                if (!$oUserModel->update($oUser->id, $aData)) {
                    throw new NailsException('Failed to update user. ' . $oUserModel->lastError());
                }

                $this->addToChangeLogEdit(
                    $oUser,
                    $oUserModel->getById($oUser->id)
                );

                $this->oUserFeedback->success(sprintf(
                    'User %s updated successfully.',
                    $oUser->name
                ));

                redirect(
                    $oInput->get('isModal')
                        ? static::url('edit/' . $oUser->id . '?isModal=true')
                        : static::url('edit/' . $oUser->id)
                );

            } catch (ValidationException $e) {
                $this->oUserFeedback->error($e->getMessage());
            }
        }

        // --------------------------------------------------------------------------

        if (activeUser('id') == $oUser->id) {
            $this->oUserFeedback->info(lang('accounts_edit_editing_self', [$oUser->first_name]));
        }

        // --------------------------------------------------------------------------

        $this->data['aTabs'] = $aTabs;
        $this->data['oUser'] = $oUser;
        $this->data['oItem'] = $oUser;

        $this
            ->setData('aTabs', $aTabs)
            ->setData('oUser', $oUser)
            ->setData('oItem', $oUser)
            ->setTitles([
                lang(
                    'accounts_edit_title',
                    $oUser->name
                ),
            ])
            ->loadView('edit');
    }

    // --------------------------------------------------------------------------

    /**
     * Delete a user
     *
     * @throws FactoryException
     * @throws ModelException
     * @todo (Pablo - 2019-01-22) - Use the DefaultController edit() method
     *
     */
    public function delete(): void
    {
        if (!userHasPermission(Permission\Users\Delete::class)) {
            unauthorised();
        }

        // --------------------------------------------------------------------------

        //  Get the user's details
        /** @var Uri $oUri */
        $oUri = Factory::service('Uri');
        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        /** @var User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);

        $iUserId = $oUri->segment(5);
        $oUser   = $oUserModel->getById($iUserId);

        // --------------------------------------------------------------------------

        //  Non-superusers editing superusers is not cool
        if (!isSuperUser() && isSuperUser($oUser)) {
            $this->oUserFeedback->error(lang('accounts_edit_error_noteditable'));
            $this->returnToIndex();
        }

        // --------------------------------------------------------------------------

        //  Delete user
        $oUser = $oUserModel->getById($iUserId);

        if (!$oUser) {
            show404();
        } elseif ($oUser->id == activeUser('id')) {
            $this->oUserFeedback->error(lang('accounts_delete_error_selfie'));
            $this->returnToIndex();
        }

        // --------------------------------------------------------------------------

        //  Define messages
        if ($oUserModel->destroy($iUserId)) {

            $this->oUserFeedback->success(lang('accounts_delete_success', $oUser->name));

            //  Update admin changelog
            $this->oChangeLogModel->add(
                'deleted',
                'a',
                'user',
                $iUserId,
                '#' . number_format($iUserId) . ' ' . $oUser->name
            );

        } else {
            $this->oUserFeedback->error(lang('accounts_delete_error', $oUser->name));
        }

        // --------------------------------------------------------------------------

        $this->returnToIndex();
    }

    // --------------------------------------------------------------------------

    /**
     * Change a user's group
     *
     * @throws FactoryException
     */
    public function change_group(): void
    {
        if (!userHasPermission(Permission\Users\Group\Change::class)) {
            show404();
        }

        // --------------------------------------------------------------------------

        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        /** @var User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);

        $aUserIds = explode(',', $oInput->get('users'));
        $aUsers   = $oUserModel->getByIds($aUserIds);

        if (empty($aUsers)) {
            show404();
        }

        $aRemovedUsers = [];
        foreach ($aUsers as &$oUser) {
            if (isSuperUser($oUser) && !isSuperUser()) {
                $aRemovedUsers[] = $oUser;
                $oUser           = null;
            }
        }

        $aUsers = array_filter($aUsers);
        $aUsers = array_values($aUsers);

        if (!empty($aRemovedUsers)) {
            $this->oUserFeedback->warning(sprintf(
                'You do not have permission to change the group of the following users: %s',
                implode(', ', array_map(function ($oUser) {
                    return '<br><strong>#' . $oUser->id . ' ' . $oUser->name . '</strong>';
                }, $aRemovedUsers))
            ));
        }

        if (empty($aUsers)) {
            $this->oUserFeedback->error('No users selected.');
        }

        // --------------------------------------------------------------------------

        /** @var Group $oUserGroupModel */
        $oUserGroupModel = Factory::model('UserGroup', Constants::MODULE_SLUG);
        $aGroups         = $oUserGroupModel->getAll();

        if (!isSuperUser()) {
            foreach ($aGroups as &$oGroup) {
                if (isGroupSuperUser($oGroup)) {
                    $oGroup = null;
                }
            }
        }

        $aGroups     = array_filter($aGroups);
        $aGroups     = array_values($aGroups);
        $aUserGroups = array_combine(
            arrayExtractProperty($aGroups, 'id'),
            arrayExtractProperty($aGroups, 'label')
        );

        // --------------------------------------------------------------------------

        if ($oInput->post()) {

            if ($oUserGroupModel->changeUserGroup(arrayExtractProperty($aUsers, 'id'), (int) $oInput->post('group_id'))) {
                $this->oUserFeedback->success('User group was updated successfully.');
                $this->returnToIndex();
            } else {
                $this->oUserFeedback->error('Failed to update user group. ' . $oUserGroupModel->lastError());
            }
        }

        // --------------------------------------------------------------------------

        $this
            ->setTitles(['Change a user\'s group'])
            ->setData('aUsers', $aUsers)
            ->setData('aUserGroups', $aUserGroups)
            ->loadView('changeGroup');
    }

    // --------------------------------------------------------------------------

    /**
     * Suspend a user
     *
     * @return void
     * @throws FactoryException
     * @throws ModelException
     * @throws NailsException
     */
    public function suspend(): void
    {
        if (!userHasPermission(Permission\Users\Suspend::class)) {
            unauthorised();
        }

        // --------------------------------------------------------------------------

        //  Get the user's details
        /** @var Uri $oUri */
        $oUri = Factory::service('Uri');
        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        /** @var User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);

        $iUserId   = $oUri->segment(5);
        $oUser     = $oUserModel->getById($iUserId);
        $bOldValue = $oUser->is_suspended;

        // --------------------------------------------------------------------------

        //  Non-superusers editing superusers is not cool
        if (!isSuperUser() && isSuperUser($oUser)) {
            $this->oUserFeedback->error(lang('accounts_edit_error_noteditable'));
            $this->returnToIndex();
        }

        // --------------------------------------------------------------------------

        //  Suspend user
        $oUserModel->suspend($iUserId);

        // --------------------------------------------------------------------------

        //  Get the user's details, again
        $oUser     = $oUserModel->getById($iUserId);
        $bNewValue = $oUser->is_suspended;

        // --------------------------------------------------------------------------

        //  Define messages
        if (!$oUser->is_suspended) {
            $this->oUserFeedback->error(
                lang('accounts_suspend_error', $oUser->name)
            );
        } else {
            $this->oUserFeedback->success(
                lang('accounts_suspend_success', $oUser->name)
            );
        }

        // --------------------------------------------------------------------------

        //  Update admin changelog
        $this->oChangeLogModel->add(
            'suspended',
            'a',
            'user',
            $iUserId,
            '#' . number_format($iUserId) . ' ' . $oUser->name,
            static::url('edit/' . $iUserId),
            'is_suspended',
            $bOldValue,
            $bNewValue,
            false
        );

        // --------------------------------------------------------------------------

        $this->returnToIndex();
    }

    // --------------------------------------------------------------------------

    /**
     * Unsuspend a user
     *
     * @throws FactoryException
     * @throws ModelException
     */
    public function unsuspend(): void
    {
        if (!userHasPermission(Permission\Users\Suspend::class)) {
            unauthorised();
        }

        // --------------------------------------------------------------------------

        //  Get the user's details
        /** @var Uri $oUri */
        $oUri = Factory::service('Uri');
        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        /** @var User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);

        $iUserId   = $oUri->segment(5);
        $oUser     = $oUserModel->getById($iUserId);
        $bOldValue = $oUser->is_suspended;

        // --------------------------------------------------------------------------

        //  Non-superusers editing superusers is not cool
        if (!isSuperUser() && isSuperUser($oUser)) {
            $this->oUserFeedback->error(lang('accounts_edit_error_noteditable'));
            $this->returnToIndex();
        }

        // --------------------------------------------------------------------------

        //  Unsuspend user
        $oUserModel->unsuspend($iUserId);

        // --------------------------------------------------------------------------

        //  Get the user's details, again
        $oUser     = $oUserModel->getById($iUserId);
        $bNewValue = $oUser->is_suspended;

        // --------------------------------------------------------------------------

        //  Define messages
        if ($oUser->is_suspended) {
            $this->oUserFeedback->error(lang('accounts_unsuspend_error', $oUser->name));
        } else {
            $this->oUserFeedback->success(lang('accounts_unsuspend_success', $oUser->name));
        }

        // --------------------------------------------------------------------------

        //  Update admin changelog
        $this->oChangeLogModel->add(
            'unsuspended',
            'a',
            'user',
            $iUserId,
            '#' . number_format($iUserId) . ' ' . $oUser->name,
            static::url('edit/' . $iUserId),
            'is_suspended',
            $bOldValue,
            $bNewValue,
            false
        );

        // --------------------------------------------------------------------------

        $this->returnToIndex();
    }

    // --------------------------------------------------------------------------

    /**
     * Manage a user's email address
     *
     * @throws FactoryException
     */
    public function email(): void
    {
        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        /** @var User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);

        $sAction = $oInput->post('action');
        $sEmail  = trim($oInput->post('email'));
        $iId     = (int) $oInput->post('id') ?: null;

        switch ($sAction) {

            case 'add':
                $bIsPrimary  = (bool) $oInput->post('is_primary');
                $bIsVerified = (bool) $oInput->post('is_verified');

                if ($oUserModel->emailAdd($sEmail, $iId, $bIsPrimary, $bIsVerified)) {
                    $this->oUserFeedback->success('"' . $sEmail . '" was added successfully. ');
                } else {
                    $this->oUserFeedback->error('Failed to add email. ' . $oUserModel->lastError());
                }
                break;

            case 'delete':
                if ($oUserModel->emailDelete($sEmail, $iId)) {
                    $this->oUserFeedback->success('"' . $sEmail . '" was deleted successfully. ');
                } else {
                    $this->oUserFeedback->error('Failed to delete email "' . $sEmail . '". ' . $oUserModel->lastError());
                }
                break;

            case 'makePrimary':
                if ($oUserModel->emailMakePrimary($sEmail, $iId)) {
                    $this->oUserFeedback->success('"' . $sEmail . '" was set as the primary email.');
                } else {
                    $this->oUserFeedback->error('Failed to mark "' . $sEmail . '" as the primary address. ' . $oUserModel->lastError());
                }
                break;

            case 'verify':
                //  Get the code for this email
                $aUserEmails = $oUserModel->getEmailsForUser($iId);
                $sCode       = '';

                foreach ($aUserEmails as $oUserEmail) {
                    if ($oUserEmail->email == $sEmail) {
                        $sCode = $oUserEmail->code;
                    }
                }

                if (!empty($sCode) && $oUserModel->emailVerify($iId, $sCode)) {
                    $this->oUserFeedback->success('"' . $sEmail . '" was verified successfully.');
                } elseif (empty($sCode)) {
                    $this->oUserFeedback->error('Failed to mark "' . $sEmail . '" as verified. Could not determine email\'s security code.');
                } else {
                    $this->oUserFeedback->error('Failed to mark "' . $sEmail . '" as verified. ' . $oUserModel->lastError());
                }
                break;

            default:
                $this->oUserFeedback->error('Unknown action: "' . $sAction . '"');
                break;
        }

        redirect($oInput->post('return'));
    }
}

<?php

/**
 * This class provides group management functionality to Admin
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    AdminController
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Auth\Admin\Controller;

use Nails\Admin\Admin\Permission\SuperUser;
use Nails\Admin\Controller\DefaultController;
use Nails\Auth\Admin\Permission;
use Nails\Auth\Constants;
use Nails\Auth\Interfaces\Admin\User\Group\Tab;
use Nails\Auth\Model\User\Group;
use Nails\Auth\Model\User\Password;
use Nails\Common\Exception\ValidationException;
use Nails\Common\Factory\Component;
use Nails\Common\Resource;
use Nails\Common\Service\FormValidation;
use Nails\Common\Service\Input;
use Nails\Common\Service\Uri;
use Nails\Components;
use Nails\Config;
use Nails\Factory;

/**
 * Class Groups
 *
 * @package Nails\Admin\Auth
 */
class Groups extends DefaultController
{
    const CONFIG_MODEL_NAME     = 'UserGroup';
    const CONFIG_MODEL_PROVIDER = Constants::MODULE_SLUG;
    const CONFIG_SIDEBAR_GROUP  = 'Users';
    const CONFIG_SORT_OPTIONS   = [
        'Label'    => 'label',
        'Created'  => 'created',
        'Modified' => 'modified',
    ];
    const CONFIG_PERMISSION_BROWSE   = Permission\Groups\Browse::class;
    const CONFIG_PERMISSION_CREATE   = Permission\Groups\Create::class;
    const CONFIG_PERMISSION_DELETE   = Permission\Groups\Delete::class;
    const CONFIG_PERMISSION_EDIT     = Permission\Groups\Edit::class;

    // --------------------------------------------------------------------------

    /**
     * @var Tab[]
     */
    protected array $aGroupTabs = [];

    // --------------------------------------------------------------------------

    /**
     * Load data for the edit/create view
     *
     * @param Resource $oItem The main item object
     *
     * @return void
     */
    protected function loadEditViewData(?Resource $oItem = null): void
    {
        parent::loadEditViewData($oItem);

        $this->aGroupTabs = [];
        /** @var Component $oComponent */
        foreach (Components::available() as $oComponent) {
            $aClasses = $oComponent
                ->findClasses('Auth\\Admin\\User\\Group\\Tab')
                ->whichImplement(Tab::class);

            foreach ($aClasses as $sClass) {
                if ($sClass::isEnabled($oItem)) {
                    $this->aGroupTabs[$sClass] = new $sClass();
                }
            }
        }

        $this->data['aGroupTabs'] = $this->aGroupTabs;

        /** @var \Nails\Admin\Service\Permission $oPermissionService */
        $oPermissionService         = Factory::service('Permission', \Nails\Admin\Constants::MODULE_SLUG);
        $this->data['aPermissions'] = $oPermissionService->getGrouped();
    }

    // --------------------------------------------------------------------------

    /**
     * Form validation for edit/create
     *
     * @param string $sMode      The mode in which the validation is being run
     * @param array  $aOverrides Any overrides for the fields; best to do this in the model's describeFields() method
     *
     * @return void
     * @throws ValidationException
     */
    protected function runFormValidation(string $sMode, array $aOverrides = []): void
    {
        parent::runFormValidation(
            $sMode,
            [
                'slug'        => array_filter([
                    'required',
                    $this->data['item'] ? 'unique_if_diff[' . Config::get('NAILS_DB_PREFIX') . 'user_group.slug.' . $this->data['item']->slug . ']' : null,
                ]),
                'label'       => ['required'],
                'description' => ['required'],
            ]
        );

        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        $aRules = [];
        /** @var Tab $oTab */
        foreach ($this->aGroupTabs as $oTab) {
            $aRules = array_merge($aRules, $oTab->getValidationRules($this->data['item']));
        }

        if ($aRules) {
            /** @var FormValidation $oFormValidation */
            $oFormValidation = Factory::service('FormValidation');
            $oFormValidation->buildValidator($aRules, [], $oInput->post())->run();
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Extract data from post variable
     *
     * @return array
     */
    protected function getPostObject(): array
    {
        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        /** @var Group $oUserGroupModel */
        $oUserGroupModel = Factory::model('UserGroup', Constants::MODULE_SLUG);
        /** @var Password $oUserPasswordModel */
        $oUserPasswordModel = Factory::model('UserPassword', Constants::MODULE_SLUG);

        $aData = [
            'slug'                  => $oInput->post('slug'),
            'label'                 => $oInput->post('label'),
            'description'           => $oInput->post('description'),
            'default_homepage'      => $oInput->post('default_homepage'),
            'registration_redirect' => $oInput->post('registration_redirect'),
            'acl'                   => json_encode(
                array_values(
                    array_filter(
                        $oInput->post('is_superuser')
                            ? [SuperUser::class]
                            : (array) $oInput->post('acl'),
                    )
                )
            ),
            'password_rules'        => $oUserPasswordModel->processRules($oInput->post('pw') ?: []),
        ];

        /** @var Tab $oTab */
        foreach ($this->aGroupTabs as $oTab) {
            $aData = array_merge($aData, $oTab->getPostData($this->data['item'], $oInput->post()));
        }

        return $aData;
    }

    // --------------------------------------------------------------------------

    protected function afterCreateAndEdit(
        $sMode,
        ?Resource $oNewItem,
        ?Resource $oOldItem = null
    ): void {
        parent::afterCreateAndEdit($sMode, $oNewItem, $oOldItem);

        if (!$oNewItem instanceof \Nails\Auth\Resource\User\Group) {
            return;
        }

        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        /** @var Tab $oTab */
        foreach ($this->aGroupTabs as $oTab) {
            $oTab->afterSave($oNewItem, $oInput->post());
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Delete an item
     *
     * @return void
     */
    public function delete(): void
    {
        if (!static::isDeleteButtonEnabled()) {
            show404();
        }

        /** @var Uri $oUri */
        $oUri = Factory::service('Uri');
        /** @var Group $oItemModel */
        $oItemModel = static::getModel();

        $iItemId = (int) $oUri->segment(5);
        $oItem   = $oItemModel->getById($iItemId);

        if (empty($oItem)) {
            show404();

        } elseif ($oItem->id === activeUser()->group()->id) {
            $this->oUserFeedback->error('You cannot delete your own user group.');
            redirect(self::url());

        } elseif (!isSuperUser() && isGroupSuperUser($oItem)) {
            $this->oUserFeedback->error('You cannot delete a group which has super user permissions.');
            redirect(self::url());

        } elseif ($oItem->id === $oItemModel->getDefaultGroupId()) {
            $this->oUserFeedback->error('You cannot delete the default user group.');
            redirect(self::url());

        } else {
            parent::delete();
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Set the default user group
     *
     * @return void
     */
    public function set_default(): void
    {
        if (!userHasPermission(Permission\Groups\SetDefault::class)) {
            show404();
        }

        /** @var Uri $oUri */
        $oUri = Factory::service('Uri');
        /** @var Group $oUserGroupModel */
        $oUserGroupModel = Factory::model('UserGroup', Constants::MODULE_SLUG);

        if ($oUserGroupModel->setAsDefault($oUri->segment(5))) {
            $this->oUserFeedback->success(
                'Group set as default successfully.'
            );
        } else {
            $this->oUserFeedback->error(
                'Failed to set default user group. ' . $oUserGroupModel->lastError()
            );
        }

        redirect(self::url());
    }
}

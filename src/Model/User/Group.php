<?php

/**
 * This model contains all methods for interacting with user groups.
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    Model
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Auth\Model\User;

use Nails\Auth\Admin\Permission;
use Nails\Auth\Constants;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\ModelException;
use Nails\Common\Exception\NailsException;
use Nails\Common\Model\Base;
use Nails\Factory;
use RuntimeException;
use Throwable;

/**
 * Class Group
 *
 * @package Nails\Auth\Model\User
 */
class Group extends Base
{
    /**
     * The table this model represents
     *
     * @var string
     */
    const TABLE = NAILS_DB_PREFIX . 'user_group';

    /**
     * The default column to sort on
     *
     * @var string|null
     */
    const DEFAULT_SORT_COLUMN = 'id';

    /**
     * The name of the resource to use (as passed to \Nails\Factory::resource())
     *
     * @var string
     */
    const RESOURCE_NAME = 'UserGroup';

    /**
     * The provider of the resource to use (as passed to \Nails\Factory::resource())
     *
     * @var string
     */
    const RESOURCE_PROVIDER = Constants::MODULE_SLUG;

    // --------------------------------------------------------------------------

    protected ?\Nails\Auth\Resource\User\Group $oDefaultGroup;

    // --------------------------------------------------------------------------

    /**
     * Set's a group as the default group
     *
     * @param mixed $mGroupIdOrSlug The group's ID or slug
     *
     * @return bool
     * @throws FactoryException
     * @throws ModelException
     * @throws NailsException
     */
    public function setAsDefault($mGroupIdOrSlug): bool
    {
        /** @var \Nails\Auth\Resource\User\Group $oGroup */
        $oGroup = $this->getByIdOrSlug($mGroupIdOrSlug);

        if (!$oGroup) {
            $this->setError('Invalid Group');
        }

        // --------------------------------------------------------------------------

        /** @var \Nails\Common\Service\Database $oDb */
        $oDb = Factory::service('Database');
        $oDb->transaction()->start();

        //  Unset old default
        $oDb
            ->set('is_default', false)
            ->set($this->getColumnModified(), 'NOW()', false);

        if (isLoggedIn()) {
            $oDb->set($this->getColumnModifiedBy(), activeUser('id'));
        }

        $oDb
            ->where('is_default', true)
            ->update($this->getTableName());

        //  Set new default
        $oDb
            ->set('is_default', true)
            ->set($this->getColumnModified(), 'NOW()', false);

        if (isLoggedIn()) {
            $oDb->set($this->getColumnModifiedBy(), activeUser('id'));
        }

        $oDb
            ->where('id', $oGroup->id)
            ->update($this->getTableName());

        if ($oDb->transaction()->status() === false) {

            $oDb->transaction()->rollback();
            return false;

        } else {

            $oDb->transaction()->commit();
            $this->getDefaultGroup();
            return true;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the default user group
     *
     * @return \Nails\Auth\Resource\User\Group|null
     * @throws ModelException
     * @throws NailsException
     */
    public function getDefaultGroup(): ?\Nails\Auth\Resource\User\Group
    {
        if (empty($this->oDefaultGroup)) {

            /** @var \Nails\Auth\Resource\User\Group[] $aGroups */
            $aGroups = $this->getAll([
                'where' => [
                    ['is_default', true],
                ],
            ]);

            if (empty($aGroups)) {
                throw new NailsException('A default user group must be defined.');
            }

            $this->oDefaultGroup = reset($aGroups);
        }

        return $this->oDefaultGroup ?: null;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the default group's ID
     *
     * @return int
     * @throws NailsException
     */
    public function getDefaultGroupId(): int
    {
        return $this->getDefaultGroup()->id;
    }

    // --------------------------------------------------------------------------

    /**
     * Change the user group of multiple users, executing any pre/post upgrade functionality as required
     *
     * @param int[] $aUserIds    An array of User ID's to update
     * @param int   $iNewGroupId The ID of the new user group
     *
     * @return bool
     * @throws FactoryException
     * @throws ModelException
     */
    public function changeUserGroup(array $aUserIds, int $iNewGroupId): bool
    {
        if (!userHasPermission(Permission\Users\Group\Change::class)) {
            throw new RuntimeException('You do not have permission to change a user\'s group');
        }

        /** @var \Nails\Auth\Resource\User\Group $oGroup */
        $oGroup = $this->getById($iNewGroupId);
        if (empty($oGroup)) {
            $this->setError('"' . $iNewGroupId . '" is not a valid group ID.');
            return false;
        }

        if (isGroupSuperUser($oGroup) && !isSuperUser()) {
            $this->setError('You do not have permission to add user\'s to the superuser group.');
            return false;
        }

        /** @var \Nails\Common\Service\Database $oDb */
        $oDb = Factory::service('Database');
        /** @var \Nails\Auth\Model\User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);

        /** @var \Nails\Auth\Resource\User[] $aUsers */
        $aUsers = $oUserModel->getByIds($aUserIds);

        try {

            $oDb->transaction()->start();
            foreach ($aUsers as $oUser) {

                if (isSuperUser($oUser) && !isSuperUser()) {
                    throw new NailsException('You do not have permission to change a super user\'s group');
                }

                $aData = ['group_id' => $oGroup->id];
                if (!$oUserModel->update($oUser->id, $aData)) {
                    throw new NailsException('Failed to update group ID for user ' . $oUser->id);
                }
            }
            $oDb->transaction()->commit();

            return true;

        } catch (Throwable $e) {
            $oDb->transaction()->rollback();
            $this->setError($e->getMessage());
            return false;
        }
    }
}

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

class Group extends \Nails\Common\Model\Base
{
    protected $defaultGroup;

    // --------------------------------------------------------------------------

    /**
     * Cosntruct the model
     */
    public function __construct()
    {
        parent::__construct();

        // --------------------------------------------------------------------------

        $this->table       = NAILS_DB_PREFIX . 'user_group';
        $this->tableAlias = 'ug';

        // --------------------------------------------------------------------------

        $this->defaultGroup = $this->getDefaultGroup();
    }

    // --------------------------------------------------------------------------

    /**
     * Set's a group as the default group
     * @param mixed $group_id_slug The group's ID or slug
     */
    public function setAsDefault($group_id_slug)
    {
        $group = $this->getByIdOrSlug($group_id_slug);

        if (!$group) {

            $this->setError('Invalid Group');
        }

        // --------------------------------------------------------------------------

        $this->db->trans_begin();

        //  Unset old default
        $this->db->set('is_default', false);
        $this->db->set('modified', 'NOW()', false);
        if ($this->user_model->isLoggedIn()) {

            $this->db->set('modified_by', activeUser('id'));

        }
        $this->db->where('is_default', true);
        $this->db->update($this->table);

        //  Set new default
        $this->db->set('is_default', true);
        $this->db->set('modified', 'NOW()', false);
        if ($this->user_model->isLoggedIn()) {

            $this->db->set('modified_by', activeUser('id'));

        }
        $this->db->where('id', $group->id);
        $this->db->update($this->table);

        if ($this->db->trans_status() === false) {

            $this->db->trans_rollback();
            return false;

        } else {

            $this->db->trans_commit();

            //  Refresh the default group variable
            $this->getDefaultGroup();

            return true;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the default user group
     * @return stdClass
     */
    public function getDefaultGroup()
    {
        $data['where']   = array();
        $data['where'][] = array('column' => 'is_default', 'value' => true);

        $group = $this->getAll(null, null, $data);

        if (!$group) {

            showFatalError('No Default Group Set', 'A default user group must be set.');
        }

        $this->defaultGroup = $group[0];

        return $this->defaultGroup;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the default group's ID
     * @return int
     */
    public function getDefaultGroupId()
    {
        return $this->defaultGroup->id;
    }

    // --------------------------------------------------------------------------

    /**
     * Change the user group of multiple users, executing any pre/post upgrade functionality as required
     * @param  array   $userIds    An array of User ID's to update
     * @param  integer $newGroupId The ID of the new user group
     * @return boolean
     */
    public function changeUserGroup($userIds, $newGroupId)
    {
        $group = $this->getById($newGroupId);

        if (empty($group)) {

            $this->setError('"' . $newGroupId . '" is not a valid group ID.');
            return false;
        }

        $users = $this->user_model->getByIds((array) $userIds);

        $this->db->trans_begin();

        foreach ($users as $user) {

            $preMethod  = 'changeUserGroup_pre_' . $user->group_slug . '_' . $group->slug;
            $postMethod = 'changeUserGroup_post_' . $user->group_slug . '_' . $group->slug;

            if (method_exists($this, $preMethod)) {

                if (!$this->$preMethod($user)) {

                    $this->db->trans_rollback();
                    $msg = '"' . $preMethod. '()" returned false for user ' . $user->id . ', rolling back changes';
                    $this->setError($msg);
                    return false;
                }
            }

            $data = array('group_id' => $group->id);
            if (!$this->user_model->update($user->id, $data)) {

                $this->db->trans_rollback();
                $msg = 'Failed to update group ID for user ' . $user->id;
                $this->setError($msg);
                return false;
            }

            if (method_exists($this, $postMethod)) {

                if (!$this->$postMethod($user)) {

                    $this->db->trans_rollback();
                    $msg = '"' . $postMethod. '()" returned false for user ' . $user->id . ', rolling back changes';
                    $this->setError($msg);
                    return false;
                }
            }

        }

        $this->db->trans_commit();
        return true;
    }

    // --------------------------------------------------------------------------

    /**
     * Formats an array of permissions into a JSON encoded string suitable for the database
     * @param  array  $permissions An array of permissions to set
     * @return string
     */
    public function processPermissions($permissions)
    {
        if (empty($permissions)) {
            return null;
        }

        $out = array();

        //  Level 1
        foreach ($permissions as $levelOneSlug => $levelOnePermissions) {

            if (is_string($levelOnePermissions)) {

                $out[] = $levelOneSlug;
                continue;
            }

            foreach ($levelOnePermissions as $levelTwoSlug => $levelTwoPermissions) {

                if (is_string($levelTwoPermissions)) {

                    $out[] = $levelOneSlug . ':' . $levelTwoSlug;
                    continue;
                }

                foreach ($levelTwoPermissions as $levelThreeSlug => $levelThreePermissions) {

                    $out[] = $levelOneSlug . ':' . $levelTwoSlug . ':' . $levelThreeSlug;
                }
            }
        }

        $out = array_unique($out);
        $out = array_filter($out);

        return json_encode($out);
    }

    // --------------------------------------------------------------------------

    /**
     * Formats a single object
     *
     * The getAll() method iterates over each returned item with this method so as to
     * correctly format the output. Use this to cast integers and booleans and/or organise data into objects.
     *
     * @param  object $oObj      A reference to the object being formatted.
     * @param  array  $aData     The same data array which is passed to _getcount_common, for reference if needed
     * @param  array  $aIntegers Fields which should be cast as integers if numerical and not null
     * @param  array  $aBools    Fields which should be cast as booleans if not null
     * @param  array  $aFloats   Fields which should be cast as floats if not null
     * @return void
     */
    protected function formatObject(
        &$oObj,
        $aData = array(),
        $aIntegers = array(),
        $aBools = array(),
        $aFloats = array()
    ) {

        parent::formatObject($oObj, $aData, $aIntegers, $aBools, $aFloats);

        $oObj->acl            = json_decode($oObj->acl);
        $oObj->password_rules = json_decode($oObj->password_rules);
    }
}

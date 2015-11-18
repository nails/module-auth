<?php

/**
 * This model contains all methods for interacting with user meta tables
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    Model
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Auth\Model\User;

use Nails\Factory;

class Meta
{
    use \Nails\Common\Traits\Caching;

    // --------------------------------------------------------------------------

    /**
     * The Database service
     * @var \Nails\Common\Database
     */
    private $oDb;

    // --------------------------------------------------------------------------

    /**
     * Construct the model
     */
    public function __construct()
    {
        $this->oDb = Factory::service('Database');
    }

    // --------------------------------------------------------------------------

    /**
     * Fetches a record from a user_meta_* table
     * @param  string   $sTable   The table to fetch from
     * @param  integer  $iUserId  The ID of the user the record belongs to
     * @param  array    $aColumns Any specific columns to select
     * @return \stdClass
     */
    public function get($sTable, $iUserId, $aColumns = array())
    {
        //  Check cache
        $sCacheKey = 'user-meta-' . $sTable . '-' . $iUserId;
        $oCache = $this->_get_cache($sCacheKey);

        if (!empty($oCache)) {

            return $oCache;
        }

        if (!empty($aColumns)) {

            $this->oDb->select($aColumns);
        }

        $this->oDb->where('user_id', $iUserId);
        $aResult = $this->oDb->get($sTable)->result();

        if (empty($aResult)) {

            $mOut = null;

        } else {

            $mOut = $aResult[0];
        }

        return $mOut;
    }

    // --------------------------------------------------------------------------

    /**
     * Fetches records from a user_meta_table which may have many rows
     * @param  string   $sTable   The table to fetch from
     * @param  integer  $iUserId  The ID of the user the records belongs to
     * @param  array    $aColumns Any specific columns to select
     * @return array
     */
    public function getMany($sTable, $iUserId, $aColumns = array())
    {
        //  Check cache
        $sCacheKey = 'user-meta-many-' . $sTable . '-' . $iUserId;
        $oCache = $this->_get_cache($sCacheKey);

        if (!empty($oCache)) {

            return $oCache;
        }

        if (!empty($aColumns)) {

            $this->oDb->select($aColumns);
        }

        $this->oDb->where('user_id', $iUserId);
        return $this->oDb->get($sTable)->result();
    }

    // --------------------------------------------------------------------------

    /**
     * Updates a user_meta_* table
     * @param  string  $sTable  The table to update
     * @param  integer $iUserId The ID of the user the record belongs to
     * @param  array   $aData   The data to set
     * @return boolean
     */
    public function update($sTable, $iUserId, $aData)
    {
        //  Safety: Ensure that the user_id is not overridden
        $aData['user_id'] = $iUserId;

        $this->oDb->where('user_id', $iUserId);
        if ($this->oDb->count_all_results($sTable)) {

            $this->oDb->set($aData);
            $this->oDb->where('user_id', $iUserId);
            $bResult = $this->oDb->update($sTable);

        } else {

            $this->oDb->set($aData);
            $bResult = $this->oDb->insert($sTable);
        }

        if ($bResult) {

            $this->_unset_cache('user-meta-' . $sTable . '-' . $iUserId);
        }

        return $bResult;
    }

    // --------------------------------------------------------------------------

    /**
     * Updates a user_meta_* table
     * @param  string  $sTable  The table to update
     * @param  integer $iUserId The ID of the user ID being updated
     * @param  array   $aData   An array of rows to update
     * @return boolean
     */
    public function updateMany($sTable, $iUserId, $aData)
    {
        $this->oDb->trans_begin();
        foreach ($aData as $aRow) {

            //  Safety: Ensure that the row ID, and User ID are not overridden
            unset($aRow['id']);
            unset($aRow['user_id']);

            $this->oDb->where('id', $iRowId);
            if ($this->oDb->count_all_results($sTable)) {

                $this->oDb->set($aRow);
                if (!$this->oDb->update($sTable)) {
                    $this->odb->trans_rollback();
                    return false;
                }

            } else {

                $this->oDb->set($aRow);
                if (!$this->oDb->insert($sTable)) {
                    $this->odb->trans_rollback();
                    return false;
                }
            }
        }

        $this->db->trans_commit();
        $this->_unset_cache('user-meta-' . $sTable . '-' . $iUserId);
        return true;
    }
}
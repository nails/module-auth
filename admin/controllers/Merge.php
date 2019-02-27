<?php

/**
 * This class provides the ability to merge users
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    AdminController
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Admin\Auth;

use Nails\Admin\Helper;
use Nails\Auth\Controller\BaseAdmin;
use Nails\Common\Exception\ValidationException;
use Nails\Factory;

class Merge extends BaseAdmin
{
    /**
     * Announces this controller's navGroups
     *
     * @return \stdClass
     */
    public static function announce()
    {
        if (userHasPermission('admin:auth:merge:users')) {
            $oNavGroup = Factory::factory('Nav', 'nails/module-admin');
            $oNavGroup->setLabel('Users');
            $oNavGroup->setIcon('fa-users');
            $oNavGroup->addAction('Merge Users');
            return $oNavGroup;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Returns an array of extra permissions for this controller
     *
     * @return array
     */
    public static function permissions(): array
    {
        $aPermissions = parent::permissions();

        $aPermissions['users'] = 'Can merge users';

        return $aPermissions;
    }

    // --------------------------------------------------------------------------

    /**
     * Merge users
     *
     * @return void
     */
    public function index()
    {
        if (!userHasPermission('admin:auth:merge:users')) {
            unauthorised();
        }

        // --------------------------------------------------------------------------

        $this->data['page']->title = 'Merge Users';

        // --------------------------------------------------------------------------

        $oInput = Factory::service('Input');
        if ($oInput->post()) {
            try {

                $oFormValidation = Factory::service('FormValidation');

                $oFormValidation->set_rules('user_id', '', 'required');
                $oFormValidation->set_rules('merge_ids', '', 'required');

                $oFormValidation->set_message('required', lang('fv_required'));

                if (!$oFormValidation->run()) {
                    throw new ValidationException(lang('fv_there_were_errors'));
                }

                $iUserId   = (int) $oInput->post('user_id') ?: null;
                $aMergeIds = explode(',', $oInput->post('merge_ids'));
                $bPreview  = !((bool) $oInput->post('do_merge'));

                if (in_array(activeUser('id'), $aMergeIds)) {
                    throw new ValidationException('You cannot list yourself as a user to merge.');
                }

                $oUserModel   = Factory::model('User', 'nails/module-auth');
                $oMergeResult = $oUserModel->merge($iUserId, $aMergeIds, $bPreview);

                if (empty($oMergeResult)) {
                    throw new \RuntimeException('Failed to merge users. ' . $oUserModel->lastError());
                }

                if ($bPreview) {

                    $this->data['mergeResult'] = $oMergeResult;
                    Helper::loadView('preview');
                    return;

                } else {
                    $oSession = Factory::service('Session', 'nails/module-auth');
                    $oSession->setFlashData('success', 'Users were merged successfully.');
                    redirect('admin/auth/merge');
                }

            } catch (\Exception $e) {
                $this->data['error'] = $e->getMessage();
            }
        }

        // --------------------------------------------------------------------------

        //  Load views
        Helper::loadView('index');
    }
}

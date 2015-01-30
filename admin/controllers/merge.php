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

class Merge extends \AdminController
{
    /**
     * Announces this controllers methods
     * @return stdClass
     */
    public static function announce()
    {
        $d = parent::announce();
        if (user_has_permission('admin.accounts:0.can_merge_users')) {

            $d[''] = array('Members', 'Merge Users');
        }
        return $d;
    }

    // --------------------------------------------------------------------------

    /**
     * Construct the controller
     */
    public function __construct()
    {
        parent::__construct();
        if (!user_has_permission('admin.accounts:0.can_merge_users')) {

            unauthorised();
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Merge users
     * @return void
     */
    public function index()
    {
        $this->data['page']->title = 'Merge Users';

        // --------------------------------------------------------------------------

        if ($this->input->post()) {

            $userId   = $this->input->post('userId');
            $mergeIds = explode(',', $this->input->post('mergeIds'));
            $preview  = !$this->input->post('doMerge') ? true : false;

            if (!in_array(active_user('id'), $mergeIds)) {

                $mergeResult = $this->user_model->merge($userId, $mergeIds, $preview);

                if ($mergeResult) {

                    if ($preview) {

                        $this->data['mergeResult'] = $mergeResult;

                        \Nails\Admin\Helper::loadView('preview');
                        return;

                    } else {

                        $status  = 'success';
                        $message = '<strong>Success!</strong> Users were merged successfully.';
                        $this->session->set_flashdata($status, $message);
                        redirect('admin/auth/accounts/merge');
                    }

                } else {

                    $this->data['error'] = 'Failed to merge users. ' . $this->user_model->last_error();
                }

            } else {

                $this->data['error'] = '<strong>Sorry,</strong> you cannot list yourself as a user to merge.';
            }
        }

        // --------------------------------------------------------------------------

        $this->asset->load('nails.admin.accounts.merge.min.js', 'NAILS');
        $this->asset->inline('var _accountsMerge = new NAILS_Admin_Accounts_Merge()', 'JS');

        // --------------------------------------------------------------------------

        //  Load views
        \Nails\Admin\Helper::loadView('index');
    }
}

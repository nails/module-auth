<?php

/**
 * This class allows users to "login as" other users (where permission allows)
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    Controller
 * @author      Nails Dev Team
 * @link
 */

use Nails\Factory;
use Nails\Auth\Controller\Base;

class Override extends Base
{
    /**
     * Construct the controller
     */
    public function __construct()
    {
        parent::__construct();

        // --------------------------------------------------------------------------

        //  If you're not a admin then you shouldn't be accessing this class
        if (!wasAdmin() && !isAdmin()) {
            $oSession = Factory::service('Session', 'nailsapp/module-auth');
            $oSession->set_flashdata('error', lang('auth_no_access'));
            redirect('/');
        }
    }


    // --------------------------------------------------------------------------


    /**
     * Log in as another user
     * @return  void
     */
    public function login_as()
    {
        //  Perform lookup of user
        $oUserModel = Factory::model('User', 'nailsapp/module-auth');

        $hashId = $this->uri->segment(4);
        $hashPw = $this->uri->segment(5);
        $user   = $oUserModel->getByHashes($hashId, $hashPw);

        if (!$user) {
            show_404();
        }

        // --------------------------------------------------------------------------

        /**
         * Check sign-in permissions; ignore if recovering.
         * Users cannot:
         * - Sign in as themselves
         * - Sign in as superusers (unless they are a superuser)
         */

        $oSession = Factory::service('Session', 'nailsapp/module-auth');

        if (!wasAdmin()) {

            $hasPermission = userHasPermission('admin:auth:accounts:loginAs');
            $isCloning     = activeUser('id') == $user->id ? true : false;
            $isSuperuser   = !userHasPermission('superuser') && userHasPermission('superuser', $user) ? true : false;

            if (!$hasPermission || $isCloning || $isSuperuser) {

                if (!$hasPermission) {

                    $oSession->set_flashdata('error', lang('auth_override_fail_nopermission'));
                    redirect('admin/dashboard');

                } elseif ($isCloning) {

                    show_404();

                } elseif ($isSuperuser) {

                    show_404();
                }
            }
        }

        // --------------------------------------------------------------------------

        if (!$this->input->get('returningAdmin') && isAdmin()) {

            /**
             * The current user is an admin, we should set our Admin Recovery Data so
             * that they can come back.
             */

            $oUserModel->setAdminRecoveryData($user->id, $this->input->get('return_to'));
            $redirect = $user->group_homepage;

            //  A bit of feedback
            $status  = 'success';
            $message = lang('auth_override_ok', $user->first_name . ' ' . $user->last_name);

        } elseif (wasAdmin()) {

            /**
             * This user is a recovering adminaholic. Work out where we're sending
             * them back to then remove the adminRecovery data.
             */

            $recoveryData = getAdminRecoveryData();
            $redirect     = !empty($recoveryData->returnTo) ? $recoveryData->returnTo : $user->group_homepage;

            unsetAdminRecoveryData();

            //  Some feedback
            $status  = 'success';
            $message = lang('auth_override_return', $user->first_name . ' ' . $user->last_name);

        } else {

            /**
             * This user is simply logging in as someone else and has passed the hash
             * verification.
             */

            $redirect = $user->group_homepage;

            //  Some feedback
            $status  = 'success';
            $message = lang('auth_override_ok', $user->first_name . ' ' . $user->last_name);
        }

        // --------------------------------------------------------------------------

        //  Replace current user's session data
        $oUserModel->setLoginData($user->id);

        // --------------------------------------------------------------------------

        //  Any feedback?
        if (!empty($message)) {
            $oSession->set_flashdata($status, $message);
        }

        // --------------------------------------------------------------------------

        redirect($redirect);
    }
}
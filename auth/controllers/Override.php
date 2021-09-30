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

use Nails\Auth\Constants;
use Nails\Auth\Controller\Base;
use Nails\Factory;

/**
 * Class Override
 */
class Override extends Base
{
    /**
     * Override constructor.
     */
    public function __construct()
    {
        parent::__construct();

        //  If you're not a admin then you shouldn't be accessing this class
        if (!wasAdmin() && !isAdmin()) {
            unauthorised();
        }
    }


    // --------------------------------------------------------------------------

    /**
     * Log in as another user
     *
     * @return  void
     */
    public function login_as()
    {
        //  Perform lookup of user
        /** @var \Nails\Auth\Model\User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);
        /** @var \Nails\Common\Service\Uri $oUri */
        $oUri = Factory::service('Uri');
        /** @var \Nails\Common\Service\Input $oInput */
        $oInput = Factory::service('Input');

        $sHashId = (string) $oUri->segment(4);
        $sHashPw = (string) $oUri->segment(5);
        $oUser   = $oUserModel->getByHashes($sHashId, $sHashPw);

        if (!$oUser) {
            show404();
        }

        // --------------------------------------------------------------------------

        /**
         * Check sign-in permissions; ignore if recovering.
         * Users cannot:
         * - Sign in as themselves
         * - Sign in as superusers (unless they are a superuser)
         */

        if (!wasAdmin()) {

            $bHasPermission = userHasPermission('admin:auth:accounts:loginAs');
            $bIsCloning     = activeUser('id') == $oUser->id;
            $bIsSuperuser   = !isSuperuser() && isSuperuser($oUser);

            if (!$bHasPermission || $bIsCloning || $bIsSuperuser) {
                if (!$bHasPermission) {
                    $this->oUserFeedback->error(lang('auth_override_fail_nopermission'));
                    redirect('admin/dashboard');

                } elseif ($bIsCloning) {
                    show404();

                } elseif ($bIsSuperuser) {
                    show404();
                }
            }
        }

        // --------------------------------------------------------------------------

        if (!$oInput->get('returningAdmin') && isAdmin()) {

            /**
             * The current user is an admin, we should set our Admin Recovery Data so
             * that they can come back.
             */

            $oUserModel->setAdminRecoveryData($oUser->id, $oInput->get('return_to'));
            $sRedirectUrl = $oInput->get('forward_to') ?: $oUser->group_homepage;

            $this->oUserFeedback->success(lang('auth_override_ok', $oUser->first_name . ' ' . $oUser->last_name));

        } elseif (wasAdmin()) {

            /**
             * This user is a recovering admin. Work out where we're sending
             * them back to then remove the adminRecovery data.
             */

            $oRecoveryData = getAdminRecoveryData();
            $sRedirectUrl  = !empty($oRecoveryData->returnTo) ? $oRecoveryData->returnTo : $oUser->group_homepage;

            unsetAdminRecoveryData();

            $this->oUserFeedback->success(lang('auth_override_return', $oUser->first_name . ' ' . $oUser->last_name));

        } else {

            /**
             * This user is simply logging in as someone else and has passed the hash
             * verification.
             */

            $sRedirectUrl = $oInput->get('forward_to') ?: $oUser->group_homepage;

            $this->oUserFeedback->success(lang('auth_override_ok', $oUser->first_name . ' ' . $oUser->last_name));
        }

        // --------------------------------------------------------------------------

        //  Record the event
        createUserEvent(
            'did_log_in_as',
            [
                'id'         => $oUser->id,
                'first_name' => $oUser->first_name,
                'last_name'  => $oUser->last_name,
                'email'      => $oUser->email,
            ]
        );

        // --------------------------------------------------------------------------

        //  Replace current user's session data
        $oUserModel->setLoginData($oUser->id, true, true);

        // --------------------------------------------------------------------------

        redirect($sRedirectUrl);
    }
}

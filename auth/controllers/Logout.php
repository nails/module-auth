<?php

/**
 * Logs a user out
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    Controller
 * @author      Nails Dev Team
 * @link
 */

use Nails\Auth\Constants;
use Nails\Auth\Controller\Base;
use Nails\Auth\Service\Authentication;
use Nails\Factory;

/**
 * Class Logout
 */
class Logout extends Base
{
    /**
     * Log user out and forward to homepage (or via helper method if needed).
     */
    public function index()
    {
        //  If already logged out just send them silently on their way
        if (!isLoggedIn()) {
            redirect('/');
        }

        // --------------------------------------------------------------------------

        //  Generate an event for this log in
        createUserEvent('did_log_out');

        // --------------------------------------------------------------------------

        /** @var Authentication $oAuthService */
        $oAuthService = Factory::service('Authentication', Constants::MODULE_SLUG);

        $oAuthService->logout();

        // --------------------------------------------------------------------------

        redirect();
    }
}

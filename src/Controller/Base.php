<?php

/**
 * This class provides some common Auth controller functionality
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    Controller
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Auth\Controller;

use Nails\Auth\Constants;
use Nails\Common\Exception\FactoryException;
use Nails\Factory;

abstract class Base extends \App\Controller\Base
{
    /**
     * Base constructor.
     *
     * @throws FactoryException
     */
    public function __construct()
    {
        parent::__construct();
        $oConfig = Factory::service('Config');
        $oConfig->load('auth/auth');
        Factory::service('Translation')->load('auth');
    }

    // --------------------------------------------------------------------------

    /**
     * Loads Auth styles if supplied view does not exist
     *
     * @param string $sView The view to test
     *
     * @throws FactoryException
     */
    protected function loadStyles($sView)
    {
        //  Test if a view has been provided by the app
        if (!$this->isViewOverridden($sView)) {
            $oAsset = Factory::service('Asset');
            $oAsset->clear();
            $oAsset->load('nails.min.css', \Nails\Common\Constants::MODULE_SLUG);
            $oAsset->load('styles.min.css', Constants::MODULE_SLUG);
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Whether the app has supplied its own copy of a view
     *
     * An app which has taken a view over owns its markup and its assets, so the
     * module must not assume its own are wanted.
     *
     * @param string $sView Absolute path to the view the app would provide
     */
    protected function isViewOverridden(string $sView): bool
    {
        return is_file($sView);
    }
}

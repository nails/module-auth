<?php

/**
 * This model contains all methods for interacting with user emails.
 *
 * @package    Nails
 * @subpackage module-auth
 * @category   Model
 * @author     Nails Dev Team
 */

namespace Nails\Auth\Model\User\Email;

use Nails\Auth\Constants;
use Nails\Common\Model\Base;

/**
 * Class Blocker
 *
 * @package Nails\Auth\Model\User\Email
 */
class Blocker extends Base
{
    /**
     * The table this model represents
     *
     * @var string
     */
    const TABLE = NAILS_DB_PREFIX . 'user_email_blocker';

    /**
     * The name of the resource to use (as passed to \Nails\Factory::resource())
     *
     * @var string
     */
    const RESOURCE_NAME = 'UserEmailBlocker';

    /**
     * The provider of the resource to use (as passed to \Nails\Factory::resource())
     *
     * @var string
     */
    const RESOURCE_PROVIDER = Constants::MODULE_SLUG;

    /**
     * Disable setting of created_by and modified_by columns
     *
     * @var bool
     */
    const AUTO_SET_USER = false;
}

<?php

/**
 * This model contains all methods for interacting with user emails.
 *
 * @package    Nails
 * @subpackage module-auth
 * @category   Model
 * @author     Nails Dev Team
 */

namespace Nails\Auth\Model\User;

use Nails\Auth\Constants;
use Nails\Common\Model\Base;

/**
 * Class Email
 *
 * @package Nails\Auth\Model\User
 */
class Email extends Base
{
    /**
     * The table this model represents
     *
     * @var string
     */
    const TABLE = NAILS_DB_PREFIX . 'user_email';

    /**
     * The name of the resource to use (as passed to \Nails\Factory::resource())
     *
     * @var string
     */
    const RESOURCE_NAME = 'UserEmail';

    /**
     * The provider of the resource to use (as passed to \Nails\Factory::resource())
     *
     * @var string
     */
    const RESOURCE_PROVIDER = Constants::MODULE_SLUG;
}

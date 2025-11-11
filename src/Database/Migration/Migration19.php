<?php

/**
 * Migration:   19
 * Started:     11/11/2025
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    Database Migration
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Auth\Database\Migration;

use Nails\Common\Console\Migrate\Base;

class Migration19 extends Migration16
{
    /**
     * Applications moving from `pre-new-admin` to `develop` will be on migration 17. This means that they
     * will not run the permission upgrade (migration 16). They WILL have the user_password_history table
     * (develop: 17, pre-new-admin: 16) and will also have the user_email_blocker alterations
     * (develop: 18, pre-new-admin: 17).
     *
     * This migration ensures that the permission migrations happen again - this operation is safe to run twice.
     */
}

<?php

/**
 * Migration:   23
 * Started:     22/09/2026
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    Database Migration
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Auth\Database\Migration;

use Nails\Common\Interfaces;
use Nails\Common\Traits;

class Migration23 implements Interfaces\Database\Migration
{
    use Traits\Database\Migration;

    /**
     * Execute the migration
     *
     * @return void
     */
    public function execute()
    {
        $this->query('DROP TABLE IF EXISTS `{{NAILS_DB_PREFIX}}user_auth_two_factor_device_code`');
        $this->query('DROP TABLE IF EXISTS `{{NAILS_DB_PREFIX}}user_auth_two_factor_device_secret`');
        $this->query('DROP TABLE IF EXISTS `{{NAILS_DB_PREFIX}}user_auth_two_factor_question`');
        $this->query('DROP TABLE IF EXISTS `{{NAILS_DB_PREFIX}}user_auth_two_factor_token`');
    }
}

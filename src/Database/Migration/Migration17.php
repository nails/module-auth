<?php

/**
 * Migration:   17
 * Started:     01/08/2024
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    Database Migration
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Auth\Database\Migration;

use Nails\Common\Console\Migrate\Base;

class Migration17 extends Base
{
    /**
     * Execute the migration
     *
     * @return Void
     */
    public function execute()
    {
        $this->query('ALTER TABLE `{{NAILS_DB_PREFIX}}user_email_blocker` ADD `created_by` INT  UNSIGNED  NOT NULL  AFTER `created`;');
        $this->query('ALTER TABLE `{{NAILS_DB_PREFIX}}user_email_blocker` ADD `modified` DATETIME  NULL  DEFAULT NULL  AFTER `created_by`;');
        $this->query('ALTER TABLE `{{NAILS_DB_PREFIX}}user_email_blocker` ADD `modified_by` INT  UNSIGNED  NULL  DEFAULT NULL  AFTER `modified`;');
        $this->query('ALTER TABLE `{{NAILS_DB_PREFIX}}user_email_blocker` CHANGE `created_by` `created_by` INT  UNSIGNED  NULL  DEFAULT NULL;');
        $this->query('UPDATE `{{NAILS_DB_PREFIX}}user_email_blocker` SET `created_by` = `user_id`;');
        $this->query('UPDATE `{{NAILS_DB_PREFIX}}user_email_blocker` SET `modified_by` = `user_id`;');
        $this->query('UPDATE `{{NAILS_DB_PREFIX}}user_email_blocker` SET `modified` = `created`;');
        $this->query('ALTER TABLE `{{NAILS_DB_PREFIX}}user_email_blocker` CHANGE `modified` `modified` DATETIME  NOT NULL;');
        $this->query('ALTER TABLE `{{NAILS_DVB_PREFIX}}user_email_blocker` ADD FOREIGN KEY (`created_by`) REFERENCES `{{NAILS_DVB_PREFIX}}user` (`id`) ON DELETE SET NULL;');
        $this->query('ALTER TABLE `{{NAILS_DVB_PREFIX}}user_email_blocker` ADD FOREIGN KEY (`modified_by`) REFERENCES `{{NAILS_DVB_PREFIX}}user` (`id`) ON DELETE SET NULL;');
    }
}

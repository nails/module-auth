<?php

/**
 * Migration:   17
 * Started:     03/05/2024
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    Database Migration
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Auth\Database\Migration;

use Nails\Auth\Auth\PasswordEngine\Sha1;
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
        $this->query(<<<EOT
            CREATE TABLE `{{NAILS_DB_PREFIX}}user_password_history` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `user_id` int unsigned NOT NULL,
                `password` varchar(40) NOT NULL DEFAULT '',
                `password_engine` varchar(255) NOT NULL DEFAULT '',
                `salt` varchar(300) NOT NULL DEFAULT '',
                `created` datetime NOT NULL,
                `created_by` int unsigned NULL DEFAULT NULL,
                `modified` datetime NOT NULL,
                `modified_by` int unsigned NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `user_id` (`user_id`),
                CONSTRAINT `{{NAILS_DB_PREFIX}}user_password_history_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `{{NAILS_DB_PREFIX}}user` (`id`) ON DELETE CASCADE,
                CONSTRAINT `{{NAILS_DB_PREFIX}}user_password_history_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `{{NAILS_DB_PREFIX}}user` (`id`) ON DELETE CASCADE,
                CONSTRAINT `{{NAILS_DB_PREFIX}}user_password_history_ibfk_3` FOREIGN KEY (`modified_by`) REFERENCES `{{NAILS_DB_PREFIX}}user` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB  DEFAULT CHARSET=utf8;
        EOT);
    }
}

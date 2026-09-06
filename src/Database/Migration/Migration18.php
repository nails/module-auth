<?php

/**
 * Migration:   18
 * Started:     02/09/2026
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    Database Migration
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Auth\Database\Migration;

use Nails\Common\Console\Migrate\Base;

class Migration18 extends Base
{
    /**
     * Execute the migration
     *
     * @return Void
     */
    public function execute()
    {
        $this->query(<<<EOT
            CREATE TABLE `{{NAILS_DB_PREFIX}}user_import` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `object_id` int unsigned NOT NULL,
                `log_id` int unsigned NULL DEFAULT NULL,
                `additional` text NULL DEFAULT NULL,
                `skip_registered` tinyint(1) unsigned NOT NULL DEFAULT 0,
                `status` enum('DRAFT', 'PENDING', 'VALIDATING', 'RUNNING', 'COMPLETE', 'PARTIAL', 'FAILED') NOT NULL DEFAULT 'DRAFT',
                `runner` enum('CRON', 'QUEUE') NULL DEFAULT NULL,
                `claim_token` varchar(40) NULL DEFAULT NULL,
                `claimed` datetime NULL DEFAULT NULL,
                `error` text NULL DEFAULT NULL,
                `row_count` int unsigned NULL DEFAULT NULL,
                `validated_count` int unsigned NOT NULL DEFAULT 0,
                `processed_count` int unsigned NOT NULL DEFAULT 0,
                `success_count` int unsigned NOT NULL DEFAULT 0,
                `warning_count` int unsigned NOT NULL DEFAULT 0,
                `error_count` int unsigned NOT NULL DEFAULT 0,
                `started` datetime NULL DEFAULT NULL,
                `finished` datetime NULL DEFAULT NULL,
                `created` datetime NOT NULL,
                `created_by` int unsigned NULL DEFAULT NULL,
                `modified` datetime NOT NULL,
                `modified_by` int unsigned NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `status` (`status`),
                KEY `runner_status` (`runner`, `status`),
                KEY `object_id` (`object_id`),
                KEY `log_id` (`log_id`),
                KEY `created_by` (`created_by`),
                KEY `modified_by` (`modified_by`),
                CONSTRAINT `{{NAILS_DB_PREFIX}}user_import_ibfk_1` FOREIGN KEY (`object_id`) REFERENCES `{{NAILS_DB_PREFIX}}cdn_object` (`id`) ON DELETE CASCADE,
                CONSTRAINT `{{NAILS_DB_PREFIX}}user_import_ibfk_2` FOREIGN KEY (`log_id`) REFERENCES `{{NAILS_DB_PREFIX}}cdn_object` (`id`) ON DELETE SET NULL,
                CONSTRAINT `{{NAILS_DB_PREFIX}}user_import_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `{{NAILS_DB_PREFIX}}user` (`id`) ON DELETE SET NULL,
                CONSTRAINT `{{NAILS_DB_PREFIX}}user_import_ibfk_4` FOREIGN KEY (`modified_by`) REFERENCES `{{NAILS_DB_PREFIX}}user` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
        EOT
        );

        $this->query(<<<EOT
            CREATE TABLE `{{NAILS_DB_PREFIX}}user_import_item` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `import_id` int unsigned NOT NULL,
                `line` int unsigned NOT NULL,
                `user_id` int unsigned NULL DEFAULT NULL,
                `status` enum('SUCCESS', 'WARNING', 'ERROR') NOT NULL,
                `message` text NULL DEFAULT NULL,
                `created` datetime NOT NULL,
                `created_by` int unsigned NULL DEFAULT NULL,
                `modified` datetime NOT NULL,
                `modified_by` int unsigned NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `import_id_line` (`import_id`, `line`),
                KEY `import_id_status` (`import_id`, `status`),
                KEY `user_id` (`user_id`),
                KEY `created_by` (`created_by`),
                KEY `modified_by` (`modified_by`),
                CONSTRAINT `{{NAILS_DB_PREFIX}}user_import_item_ibfk_1` FOREIGN KEY (`import_id`) REFERENCES `{{NAILS_DB_PREFIX}}user_import` (`id`) ON DELETE CASCADE,
                CONSTRAINT `{{NAILS_DB_PREFIX}}user_import_item_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `{{NAILS_DB_PREFIX}}user` (`id`) ON DELETE SET NULL,
                CONSTRAINT `{{NAILS_DB_PREFIX}}user_import_item_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `{{NAILS_DB_PREFIX}}user` (`id`) ON DELETE SET NULL,
                CONSTRAINT `{{NAILS_DB_PREFIX}}user_import_item_ibfk_4` FOREIGN KEY (`modified_by`) REFERENCES `{{NAILS_DB_PREFIX}}user` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
        EOT
        );
    }
}

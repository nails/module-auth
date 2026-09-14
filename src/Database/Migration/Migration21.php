<?php

/**
 * Migration:   21
 * Started:     10/09/2026
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

class Migration21 implements Interfaces\Database\Migration
{
    use Traits\Database\Migration;

    /**
     * Execute the migration
     *
     * @return void
     */
    public function execute()
    {
        //  Already created by migration 19 on `feature/pre-new-admin`
        if ($this->tableExists('{{NAILS_DB_PREFIX}}user_passkey')) {
            return;
        }

        /**
         * `credential_id` is the base64url encoded raw credential ID. WebAuthn permits
         * up to 1023 raw bytes, which is 1364 base64url characters; it is stored as
         * ascii so that the UNIQUE index stays within InnoDB's 3072 byte key limit.
         */
        $this->query(
            <<<'EOT'
            CREATE TABLE `{{NAILS_DB_PREFIX}}user_passkey` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `user_id` int unsigned NOT NULL,
                `label` varchar(100) NOT NULL DEFAULT '',
                `credential_id` varchar(1400) CHARACTER SET ascii NOT NULL,
                `public_key` text NOT NULL,
                `sign_count` int unsigned NOT NULL DEFAULT 0,
                `aaguid` char(36) NULL DEFAULT NULL,
                `attestation_format` varchar(30) NULL DEFAULT NULL,
                `transports` varchar(255) NULL DEFAULT NULL,
                `is_discoverable` tinyint(1) unsigned NULL DEFAULT NULL,
                `is_backup_eligible` tinyint(1) unsigned NOT NULL DEFAULT 0,
                `is_backed_up` tinyint(1) unsigned NOT NULL DEFAULT 0,
                `user_handle` varchar(64) CHARACTER SET ascii NOT NULL,
                `last_used` datetime NULL DEFAULT NULL,
                `last_used_ip` varchar(45) NULL DEFAULT NULL,
                `created` datetime NOT NULL,
                `created_by` int unsigned NULL DEFAULT NULL,
                `modified` datetime NOT NULL,
                `modified_by` int unsigned NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `credential_id` (`credential_id`),
                KEY `user_id` (`user_id`),
                KEY `created_by` (`created_by`),
                KEY `modified_by` (`modified_by`),
                CONSTRAINT `{{NAILS_DB_PREFIX}}user_passkey_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `{{NAILS_DB_PREFIX}}user` (`id`) ON DELETE CASCADE,
                CONSTRAINT `{{NAILS_DB_PREFIX}}user_passkey_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `{{NAILS_DB_PREFIX}}user` (`id`) ON DELETE SET NULL,
                CONSTRAINT `{{NAILS_DB_PREFIX}}user_passkey_ibfk_3` FOREIGN KEY (`modified_by`) REFERENCES `{{NAILS_DB_PREFIX}}user` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            EOT
        );
    }
}

<?php

namespace Nails\Auth\Database\Migration;

use Nails\Common\Console\Migrate\Base;

class Migration18 extends Base
{
    /**
     * Execute the migration
     *
     * @return void
     */
    public function execute()
    {
        /**
         * These alterations are migration 17 on `feature/pre-new-admin`, so an app
         * arriving from that branch may already have some or all of them in place.
         */

        $table     = '{{NAILS_DB_PREFIX}}user_email_blocker';
        $userTable = '{{NAILS_DB_PREFIX}}user';

        $columns = $this->getTableColumns($table);

        // Add created_by if missing
        if (!isset($columns['created_by'])) {
            $this->query(sprintf(
                'ALTER TABLE `%s` ADD `created_by` INT UNSIGNED NULL DEFAULT NULL AFTER `created`;',
                $table
            ));
        } elseif (!$this->isNullableIntUnsigned($columns['created_by'])) {
            // Ensure nullability matches desired schema (NULL DEFAULT NULL)
            $this->query(sprintf(
                'ALTER TABLE `%s` CHANGE `created_by` `created_by` INT UNSIGNED NULL DEFAULT NULL;',
                $table
            ));
        }

        // Add modified if missing
        if (!isset($columns['modified'])) {
            // initially nullable to avoid illegal default
            $this->query(sprintf(
                'ALTER TABLE `%s` ADD `modified` DATETIME NULL DEFAULT NULL AFTER `created_by`;',
                $table
            ));
        }

        // Add modified_by if missing
        if (!isset($columns['modified_by'])) {
            $this->query(sprintf(
                'ALTER TABLE `%s` ADD `modified_by` INT UNSIGNED NULL DEFAULT NULL AFTER `modified`;',
                $table
            ));
        } elseif (!$this->isNullableIntUnsigned($columns['modified_by'])) {
            // Ensure it's nullable unsigned int
            $this->query(sprintf(
                'ALTER TABLE `%s` CHANGE `modified_by` `modified_by` INT UNSIGNED NULL DEFAULT NULL;',
                $table
            ));
        }

        // Add FKs if missing
        if (!$this->foreignKeyExists($table, 'created_by', $userTable, 'id')) {
            $this->query(sprintf(
                'ALTER TABLE `%s` ADD FOREIGN KEY (`created_by`) REFERENCES `%s` (`id`) ON DELETE SET NULL;',
                $table,
                $userTable
            ));
        }
        if (!$this->foreignKeyExists($table, 'modified_by', $userTable, 'id')) {
            $this->query(sprintf(
                'ALTER TABLE `%s` ADD FOREIGN KEY (`modified_by`) REFERENCES `%s` (`id`) ON DELETE SET NULL;',
                $table,
                $userTable
            ));
        }

        // Seed new columns safely
        $this->query(sprintf(
            'UPDATE `%s` SET `created_by` = `user_id` WHERE `created_by` IS NULL;',
            $table
        ));
        $this->query(sprintf(
            'UPDATE `%s` SET `modified_by` = `user_id` WHERE `modified_by` IS NULL;',
            $table
        ));
        $this->query(sprintf(
            'UPDATE `%s` SET `modified` = `created` WHERE `modified` IS NULL;',
            $table
        ));

        // Finally enforce NOT NULL on `modified` when safe
        $columns = $this->getTableColumns($table); // refresh
        if (isset($columns['modified']) && $this->isNullableDatetime($columns['modified'])) {
            $this->query(sprintf(
                'ALTER TABLE `%s` CHANGE `modified` `modified` DATETIME NOT NULL;',
                $table
            ));
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Checks that a column definition is "INT UNSIGNED NULL DEFAULT NULL"
     */
    protected function isNullableIntUnsigned(array $col)
    {
        // Type examples: int(10) unsigned, int unsigned
        $type           = strtolower($col['Type']);
        $isIntUnsigned  = strpos($type, 'int') !== false && strpos($type, 'unsigned') !== false;
        $isNullable     = strtoupper($col['Null']) === 'YES';
        $hasNullDefault = is_null($col['Default']);
        return $isIntUnsigned && $isNullable && $hasNullDefault;
    }

    // --------------------------------------------------------------------------

    /**
     * Checks if column is DATETIME NULL (nullable)
     */
    protected function isNullableDatetime(array $col)
    {
        $type = strtolower($col['Type']);
        return strpos($type, 'datetime') !== false && strtoupper($col['Null']) === 'YES';
    }
}

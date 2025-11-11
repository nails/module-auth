<?php

namespace Nails\Auth\Database\Migration;

use Nails\Common\Console\Migrate\Base;
use PDO;

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
         * Applications moving from `pre-new-admin` to `develop` will be on migration 17. This means that they
         * will not run the permission upgrade (migration 16). They WILL have the user_password_history table
         * (develop: 17, pre-new-admin: 16) and will also have the user_email_blocker alterations
         * (develop: 18, pre-new-admin: 17).
         *
         * This migration has been updated to be aware that the changes might already be in place, and as such
         * will not re-apply them twice.
         */

        $table     = '{{NAILS_DB_PREFIX}}user_email_blocker';
        $userTable = '{{NAILS_DB_PREFIX}}user';

        // Helper: fetch current columns for the target table
        $columns = $this->getTableColumns($table);
        $fks     = $this->getTableForeignKeys($table);

        // Add created_by if missing
        if (!isset($columns['created_by'])) {
            $this->query(sprintf(
                'ALTER TABLE `%s` ADD `created_by` INT UNSIGNED NULL DEFAULT NULL AFTER `created`;',
                $table
            ));
        } else {
            // Ensure nullability matches desired schema (NULL DEFAULT NULL)
            if (!$this->isNullableIntUnsigned($columns['created_by'])) {
                $this->query(sprintf(
                    'ALTER TABLE `%s` CHANGE `created_by` `created_by` INT UNSIGNED NULL DEFAULT NULL;',
                    $table
                ));
            }
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
        } else {
            // Ensure it's nullable unsigned int
            if (!$this->isNullableIntUnsigned($columns['modified_by'])) {
                $this->query(sprintf(
                    'ALTER TABLE `%s` CHANGE `modified_by` `modified_by` INT UNSIGNED NULL DEFAULT NULL;',
                    $table
                ));
            }
        }

        // Add FKs if missing
        if (!$this->hasForeignKeyTo($fks, 'created_by', $userTable, 'id')) {
            $this->query(sprintf(
                'ALTER TABLE `%s` ADD FOREIGN KEY (`created_by`) REFERENCES `%s` (`id`) ON DELETE SET NULL;',
                $table,
                $userTable
            ));
        }
        if (!$this->hasForeignKeyTo($fks, 'modified_by', $userTable, 'id')) {
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

    /**
     * Return a map of columns for a table keyed by column_name, with basic metadata
     */
    protected function getTableColumns($table)
    {
        $result = $this->query(sprintf('SHOW COLUMNS FROM `%s`', $table));
        $out    = [];
        foreach ($result->fetchAll(PDO::FETCH_ASSOC) as $r) {
            // Expected fields: Field, Type, Null, Key, Default, Extra
            $out[$r['Field']] = $r;
        }
        return $out;
    }

    /**
     * Return basic FK info for a table keyed by column_name (array of fks for safety)
     */
    protected function getTableForeignKeys($table)
    {
        $result = $this->query('SELECT DATABASE() as `db`;');
        $row    = $result->fetch(PDO::FETCH_ASSOC);
        $db     = $row['db'];

        $statement = $this->prepare(
            <<<EOT
            SELECT
                kcu.CONSTRAINT_NAME,
                kcu.COLUMN_NAME,
                kcu.REFERENCED_TABLE_NAME,
                kcu.REFERENCED_COLUMN_NAME
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
            WHERE
                kcu.TABLE_SCHEMA = ?
                AND kcu.TABLE_NAME = ?
                AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
            EOT
        );

        $statement->execute([$db, $table]);

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $out  = [];
        foreach ($rows as $r) {
            $out[$r['COLUMN_NAME']][] = $r;
        }
        return $out;
    }

    /**
     * Check if FK exists from column to target table/column
     */
    protected function hasForeignKeyTo(array $fks, $column, $refTable, $refColumn)
    {
        if (!isset($fks[$column])) {
            return false;
        }
        foreach ($fks[$column] as $fk) {
            if (
                strcasecmp($fk['REFERENCED_TABLE_NAME'], $refTable) === 0 &&
                strcasecmp($fk['REFERENCED_COLUMN_NAME'], $refColumn) === 0
            ) {
                return true;
            }
        }
        return false;
    }

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

    /**
     * Checks if column is DATETIME NULL (nullable)
     */
    protected function isNullableDatetime(array $col)
    {
        $type = strtolower($col['Type']);
        return strpos($type, 'datetime') !== false && strtoupper($col['Null']) === 'YES';
    }
}

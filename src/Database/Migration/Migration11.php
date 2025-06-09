<?php

/**
 * Migration:   11
 * Started:     10/11/2019
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    Database Migration
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Auth\Database\Migration;

use Nails\Common\Console\Migrate\Base;
use Nails\Config;

class Migration11 extends Base
{
    /**
     * Execute the migration
     *
     * @return Void
     */
    public function execute()
    {
        $this->query('
            CREATE TABLE IF NOT EXISTS `{{NAILS_DB_PREFIX}}user_event` (
                `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `type` varchar(50) NOT NULL DEFAULT \'\',
                `url` varchar(300) DEFAULT NULL,
                `data` mediumtext,
                `ref` int(11) unsigned DEFAULT NULL,
                `created` datetime NOT NULL,
                `created_by` int(11) unsigned DEFAULT NULL,
                `modified` datetime NOT NULL,
                `modified_by` int(11) unsigned DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `created_by` (`created_by`),
                KEY `modified_by` (`modified_by`),
                CONSTRAINT `{{NAILS_DB_PREFIX}}user_event_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `{{NAILS_DB_PREFIX}}user` (`id`) ON DELETE CASCADE,
                CONSTRAINT `{{NAILS_DB_PREFIX}}user_event_ibfk_2` FOREIGN KEY (`modified_by`) REFERENCES `{{NAILS_DB_PREFIX}}user` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
        ');

        //  Migrate legacy events
        $oResult = $this->query('
            SELECT
                COUNT(*)
            FROM `information_schema`.`TABLES`
            WHERE
                `TABLE_SCHEMA` = "' . $this->oDb->getDatabase() . '"
                AND `TABLE_TYPE` = "BASE TABLE"
                AND `TABLE_NAME` = "{{NAILS_DB_PREFIX}}event";
        ');

        if ((int) $oResult->fetchColumn() > 0) {

            $this->query('
                INSERT INTO `{{NAILS_DB_PREFIX}}user_event`
                    (`id`, `type`, `url`, `data`, `ref`, `created`, `created_by`, `modified`, `modified_by`)
                SELECT
                    `id`, `type`, `url`, `data`, `ref`, `created`, `created_by`, `created`, `created_by`
                FROM `{{NAILS_DB_PREFIX}}event`;
            ');
            $this->query('DROP TABLE `{{NAILS_DB_PREFIX}}event`;');
            $this->query('DELETE FROM `{{NAILS_DB_PREFIX}}migration` WHERE `module` = "nails/module-event";');
        }
    }
}

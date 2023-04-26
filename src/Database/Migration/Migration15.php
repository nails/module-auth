<?php

/**
 * Migration:   15
 * Started:     21/04/2021
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

class Migration15 extends Base
{
    /**
     * Execute the migration
     *
     * @return Void
     */
    public function execute()
    {
        $aMap = [
            '{{NAILS_DB_PREFIX}}user_group'      => [
                'password_rules',
                'acl',
            ],
            '{{NAILS_DB_PREFIX}}user'            => [
                'user_acl',
            ],
            '{{NAILS_DB_PREFIX}}user_event'      => [
                'data',
            ],
            '{{NAILS_DB_PREFIX}}user_meta_admin' => [
                'nav_state',
            ],
        ];

        foreach ($aMap as $sTable => $aColumns) {
            foreach ($aColumns as $sColumn) {
                $this->query('UPDATE `' . $sTable . '` SET `' . $sColumn . '` = NULL WHERE `' . $sColumn . '` = "";');
                $this->query('ALTER TABLE `' . $sTable . '` CHANGE `' . $sColumn . '` `' . $sColumn . '` JSON NULL;');
            }
        }
    }
}

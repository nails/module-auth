<?php

/**
 * Migration:   22
 * Started:     14/09/2026
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

class Migration22 implements Interfaces\Database\Migration
{
    use Traits\Database\Migration;

    /**
     * Execute the migration
     *
     * @return void
     */
    public function execute()
    {
        $this->query('DROP TABLE IF EXISTS `{{NAILS_DB_PREFIX}}user_social`');

        $this->query(
            "DELETE FROM `{{NAILS_DB_PREFIX}}app_setting` WHERE `grouping` = 'auth' AND `key` LIKE 'auth_social_signon_%'"
        );

        $oResult = $this->query('SELECT id, acl FROM `{{NAILS_DB_PREFIX}}user_group`');
        while ($row = $oResult->fetchObject()) {

            if ($row->acl === null) {
                continue;
            }

            $acl = json_decode((string) $row->acl) ?? [];
            if (!in_array('Nails\\Auth\\Admin\\Permission\\Settings\\Social', $acl, true)) {
                continue;
            }

            $acl = array_values(array_filter(
                $acl,
                static fn ($permission) => $permission !== 'Nails\\Auth\\Admin\\Permission\\Settings\\Social'
            ));

            $this
                ->prepare('UPDATE `{{NAILS_DB_PREFIX}}user_group` SET `acl` = :acl WHERE `id` = :id')
                ->execute([
                    ':id'  => $row->id,
                    ':acl' => json_encode($acl),
                ]);
        }
    }
}

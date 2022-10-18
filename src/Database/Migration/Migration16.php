<?php

/**
 * Migration:  16
 * Created:    09/08/2022
 */

namespace Nails\Auth\Database\Migration;

use Nails\Admin\Admin\Permission;
use Nails\Common\Traits;
use Nails\Common\Interfaces;

class Migration16 implements Interfaces\Database\Migration
{
    use Traits\Database\Migration;

    // --------------------------------------------------------------------------

    const MAP = [
        'admin:auth:accounts:browse'              => Permission\Users\Browse::class,
        'admin:auth:accounts:create'              => Permission\Users\Create::class,
        'admin:auth:accounts:edit'                => Permission\Users\Edit::class,
        'admin:auth:accounts:delete'              => Permission\Users\Delete::class,
        'admin:auth:accounts:restore'             => '',
        'admin:auth:accounts:suspend'             => Permission\Users\Suspend::class,
        'admin:auth:accounts:unsuspend'           => Permission\Users\Suspend::class,
        'admin:auth:accounts:loginas'             => Permission\Users\LoginAs::class,
        'admin:auth:accounts:editothers'          => Permission\Users\Edit::class,
        'admin:auth:accounts:changeusergroup'     => Permission\Users\Group\Change::class,
        'admin:auth:accounts:changeownusergroup'  => Permission\Users\Group\Change::class,
        'admin:auth:events:browse'                => Permission\Events\Browse::class,
        'admin:auth:events:create'                => '',
        'admin:auth:events:edit'                  => '',
        'admin:auth:events:delete'                => '',
        'admin:auth:events:restore'               => '',
        'admin:auth:groups:browse'                => Permission\Groups\Browse::class,
        'admin:auth:groups:create'                => Permission\Groups\Create::class,
        'admin:auth:groups:edit'                  => Permission\Groups\Edit::class,
        'admin:auth:groups:delete'                => Permission\Groups\Delete::class,
        'admin:auth:groups:restore'               => '',
        'admin:auth:merge:users'                  => Permission\Users\Merge::class,
        'admin:auth:settings:update:registration' => Permission\Settings\Registration::class,
        'admin:auth:settings:update:login'        => Permission\Settings\Login::class,
        'admin:auth:settings:update:password'     => Permission\Settings\Password::class,
        'admin:auth:settings:update:social'       => Permission\Settings\Social::class,
    ];

    // --------------------------------------------------------------------------

    /**
     * Execute the migration
     */
    public function execute(): void
    {
        $oResult = $this->query('SELECT id, acl FROM `{{NAILS_DB_PREFIX}}user_group`');
        while ($row = $oResult->fetchObject()) {

            $acl = json_decode($row->acl) ?? [];

            foreach ($acl as &$old) {
                $old = self::MAP[$old] ?? $old;
            }

            $acl = array_filter($acl);
            $acl = array_unique($acl);
            $acl = array_values($acl);

            $this
                ->prepare('UPDATE `{{NAILS_DB_PREFIX}}user_group` SET `acl` = :acl WHERE `id` = :id')
                ->execute([
                    ':id'  => $row->id,
                    ':acl' => json_encode($acl),
                ]);
        }
    }
}

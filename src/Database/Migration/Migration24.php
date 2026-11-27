<?php

/**
 * Migration:   24
 * Started:     27/11/2026
 *
 * Repeatable because apps arriving from `feature/pre-new-admin` may resume
 * above this number and would never run a one-shot migration. The UPDATE
 * is a no-op once grouping has moved.
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    Database Migration
 * @author      Nails Dev Team
 */

namespace Nails\Auth\Database\Migration;

use Nails\Auth\Constants;
use Nails\Common\Interfaces;
use Nails\Common\Traits;

class Migration24 implements Interfaces\Database\Migration\Repeatable
{
    use Traits\Database\Migration;

    /**
     * Execute the migration
     *
     * @return void
     */
    public function execute(): void
    {
        $this->query(sprintf(
            "UPDATE `{{NAILS_DB_PREFIX}}app_setting` SET `grouping` = '%s' WHERE `grouping` = 'auth'",
            Constants::MODULE_SLUG
        ));
    }
}

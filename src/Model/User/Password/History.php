<?php

/**
 * This model represents the user password history table
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    Model
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Auth\Model\User\Password;

use Nails\Auth\Constants;
use Nails\Auth\Resource\User;
use Nails\Common\Helper\Model\Limit;
use Nails\Common\Helper\Model\Sort;
use Nails\Common\Helper\Model\Where;
use Nails\Common\Model\Base;

/**
 * Class History
 *
 * @package Nails\Auth\Model\User\Password
 */
class History extends Base
{
    const TABLE             = NAILS_DB_PREFIX . 'user_password_history';
    const RESOURCE_NAME     = 'UserPasswordHistory';
    const RESOURCE_PROVIDER = Constants::MODULE_SLUG;

    // --------------------------------------------------------------------------

    public function purge(User $oUser, int $iKeep): bool
    {
        $aHistoryItems = $this->getAll([
            new Where('user_id', $oUser->id),
            new Sort('created', Sort::DESC),
            new Limit($iKeep),
        ]);

        $aHistoryIds = array_map(
            fn(User\Password\History $oItem) => $oItem->id,
            $aHistoryItems
        );

        if (!empty($aHistoryIds)) {
            $this
                ->deleteWhere([
                    ['user_id', $oUser->id],
                    'id NOT IN (' . implode(',', $aHistoryIds) . ')',
                ]);
        }

        return true;
    }
}

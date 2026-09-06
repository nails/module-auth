<?php

namespace Nails\Auth\Enum\User\Import;

enum ItemStatus: string
{
    case SUCCESS = 'SUCCESS';

    /**
     * The account was created, but something which had to happen afterwards did
     * not; the row is neither a success nor a failure, and the account named by
     * the item's `user_id` is the one which needs attention
     */
    case WARNING = 'WARNING';

    case ERROR = 'ERROR';
}

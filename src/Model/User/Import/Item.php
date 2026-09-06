<?php

/**
 * This model contains all methods for interacting with the per-row outcome of a
 * user import job.
 *
 * @package    Nails
 * @subpackage module-auth
 * @category   Model
 * @author     Nails Dev Team
 */

namespace Nails\Auth\Model\User\Import;

use Nails\Auth\Constants;
use Nails\Common\Exception\ModelException;
use Nails\Common\Model\Base;

/**
 * Class Item
 *
 * @package Nails\Auth\Model\User\Import
 */
class Item extends Base
{
    /**
     * The table this model represents
     *
     * @var string
     */
    const TABLE = NAILS_DB_PREFIX . 'user_import_item';

    /**
     * The name of the resource to use (as passed to \Nails\Factory::resource())
     *
     * @var string
     */
    const RESOURCE_NAME = 'UserImportItem';

    /**
     * The provider of the resource to use (as passed to \Nails\Factory::resource())
     *
     * @var string
     */
    const RESOURCE_PROVIDER = Constants::MODULE_SLUG;

    /**
     * The default column to sort on
     *
     * @var string
     */
    const DEFAULT_SORT_COLUMN = 'line';

    /**
     * The default sort order
     *
     * @var string
     */
    const DEFAULT_SORT_ORDER = self::SORT_ASC;

    /**
     * No caching; items are written by long-running workers.
     *
     * @var bool
     */
    protected static $CACHING_ENABLED = false;

    // --------------------------------------------------------------------------

    /**
     * @throws ModelException
     */
    public function __construct()
    {
        parent::__construct();
        $this
            ->hasOne('user', 'User', Constants::MODULE_SLUG);
    }
}

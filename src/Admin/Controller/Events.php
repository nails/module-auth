<?php

/**
 * The Events Admin controller
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    AdminController
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Auth\Admin\Controller;

use Nails\Admin\Controller\DefaultController;
use Nails\Admin\Helper;
use Nails\Auth\Admin\Permission;
use Nails\Auth\Constants;
use Nails\Auth\Resource\User\Event;

/**
 * Class Events
 *
 * @package Nails\Admin\Auth
 */
class Events extends DefaultController
{
    const CONFIG_MODEL_NAME        = 'UserEvent';
    const CONFIG_SIDEBAR_GROUP     = 'Logs';
    const CONFIG_SIDEBAR_FORMAT    = 'Browse %s';
    const CONFIG_TITLE_SINGLE      = 'User Event Log';
    const CONFIG_MODEL_PROVIDER    = Constants::MODULE_SLUG;
    const CONFIG_PERMISSION_BROWSE = Permission\Events\Browse::class;
    const CONFIG_CAN_CREATE        = false;
    const CONFIG_CAN_DELETE        = false;
    const CONFIG_CAN_RESTORE       = false;
    const CONFIG_CAN_EDIT          = false;
    const CONFIG_CAN_VIEW          = false;
    const CONFIG_SORT_DIRECTION    = 'desc';
    const CONFIG_INDEX_FIELDS      = [
        'User' => 'created_by',
        'Type' => 'type',
        'Date' => 'created',
    ];
    const CONFIG_SORT_OPTIONS      = [
        'Created' => 'created',
        'Type'    => 'type',
    ];

    // --------------------------------------------------------------------------

    public function __construct()
    {
        parent::__construct();
        $this->aConfig['INDEX_FIELDS']['Type'] = function (Event $oRow) {
            return ucwords(str_replace('_', ' ', $oRow->type));
        };

        $this->addIndexRowButton(
            'view/{{id}}',
            'View Data',
            'btn-default fancybox',
        );
    }

    // --------------------------------------------------------------------------

    public function view()
    {
        $this->data['oItem'] = $this->getItem();
        Helper::loadView('view');
    }
}

<?php

namespace Nails\Auth\Resource\User\Import;

use Nails\Auth\Enum\User\Import\ItemStatus;
use Nails\Common\Model\Base;
use Nails\Common\Resource\Entity;
use stdClass;

/**
 * Class Item
 *
 * @package Nails\Auth\Resource\User\Import
 */
class Item extends Entity
{
    public int        $import_id;
    public int        $line;
    public ?int       $user_id;
    public ItemStatus $status;
    public ?string    $message;

    // --------------------------------------------------------------------------

    public function __construct(self|stdClass|array $resource = [], ?Base $model = null)
    {
        $resource->status = $resource->status instanceof ItemStatus
            ? $resource->status
            : ItemStatus::from($resource->status);

        parent::__construct($resource, $model);
    }
}

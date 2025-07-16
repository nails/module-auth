<?php

namespace Nails\Auth\Resource\User;

use Nails\Common\Model\Base;
use Nails\Common\Resource\Entity;
use stdClass;

/**
 * Class Group
 *
 * @package Nails\Auth\Resource\User
 */
class Group extends Entity
{
    /** @var string */
    public $slug;

    /** @var string */
    public $label;

    /** @var string */
    public $description;

    /** @var string */
    public $default_homepage;

    /** @var string|null */
    public $registration_redirect;

    /** @var string[] */
    public $acl;

    /** @var string[] */
    public $password_rules;

    /** @var bool */
    public $is_default;

    // --------------------------------------------------------------------------

    public function __construct(self|stdClass|array $resource = [], ?Base $model = null)
    {
        parent::__construct($resource, $model);

        $this->acl            = json_decode($this->acl ?? '[]') ?? [];
        $this->password_rules = json_decode($this->password_rules ?? '[]') ?? [];
    }
}

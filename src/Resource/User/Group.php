<?php

namespace Nails\Auth\Resource\User;

use Nails\Common\Resource\Entity;

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

    public function __construct($mObj = [])
    {
        parent::__construct($mObj);

        $this->acl            = json_decode($this->acl) ?? [];
        $this->password_rules = json_decode($this->password_rules) ?? [];
    }
}

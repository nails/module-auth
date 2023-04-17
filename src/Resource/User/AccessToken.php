<?php

namespace Nails\Auth\Resource\User;

use Nails\Common\Resource\DateTime;
use Nails\Common\Resource\Entity;

/**
 * Class AccessToken
 *
 * @package Nails\Auth\Resource\User
 */
class AccessToken extends Entity
{
    /** @var int */
    public $user_id;

    /** @var string */
    public $label;

    /** @var string */
    public $token;

    /** @var DateTime */
    public $expires;

    /** @var array */
    public $scope;

    // --------------------------------------------------------------------------

    /**
     * AccessToken constructor.
     *
     * @param array $mObj
     */
    public function __construct($mObj = [])
    {
        $mObj->scope = explode(',', (string) $mObj->scope);
        parent::__construct($mObj);
    }

    // --------------------------------------------------------------------------

    /**
     * Determines whether the token has a given scope
     *
     * @param string $sScope The scope to check
     *
     * @return bool
     */
    public function hasScope(string $sScope)
    {
        return in_array($sScope, $this->scope);
    }
}

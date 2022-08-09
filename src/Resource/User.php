<?php

namespace Nails\Auth\Resource;

use Nails\Auth\Constants;
use Nails\Auth\Resource\User\Group;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Resource\Date;
use Nails\Common\Resource\DateTime;
use Nails\Common\Resource\Entity;
use Nails\Factory;

/**
 * Class User
 *
 * @package Nails\Auth\Resource
 */
class User extends Entity
{
    /** @var string */
    public $id_md5;

    /** @var int */
    public $group_id;

    /** @var string */
    public $ip_address;

    /** @var string */
    public $last_ip;

    /** @var string */
    public $username;

    /** @var string */
    public $password;

    /** @var string */
    public $password_md5;

    /** @var string */
    public $password_engine;

    /** @var DateTime */
    public $password_changed;

    /** @var string */
    public $salt;

    /** @var string */
    public $forgotten_password_code;

    /** @var string */
    public $remember_code;

    /** @var DateTime */
    public $last_login;

    /** @var DateTime */
    public $last_seen;

    /** @var bool */
    public $is_suspended;

    /** @var bool */
    public $temp_pw;

    /** @var string */
    public $failed_login_count;

    /** @var DateTime */
    public $failed_login_expires;

    /** @var DateTime */
    public $last_update;

    /** @var string */
    public $user_acl;

    /** @var int */
    public $login_count;

    /** @var string */
    public $referral;

    /** @var int */
    public $referred_by;

    /** @var string */
    public $salutation;

    /** @var string */
    public $first_name;

    /** @var string */
    public $last_name;

    /** @var string */
    public $name;

    /** @var string */
    public $gender;

    /** @var Date */
    public $dob;

    /** @var int */
    public $profile_img;

    /** @var string */
    public $timezone;

    /** @var string */
    public $datetime_format_date;

    /** @var string */
    public $datetime_format_time;

    /** @var string */
    public $language;

    /** @var string */
    public $email;

    /** @var string */
    public $email_verification_code;

    /** @var bool */
    public $email_is_verified;

    /** @var DateTime */
    public $email_is_verified_on;

    /** @var Group */
    public $group;

    /** @var string */
    public $group_slug;

    /** @var string */
    public $group_name;

    /** @var string */
    public $group_homepage;

    /** @var string */
    public $group_acl;

    /** @var string */
    public $acl;

    // --------------------------------------------------------------------------

    /**
     * User constructor.
     *
     * @param array $mObj
     */
    public function __construct($mObj = [])
    {
        parent::__construct($mObj);

        $this->name = trim(sprintf(
            '%s %s',
            $this->first_name,
            $this->last_name
        ));
    }

    // --------------------------------------------------------------------------

    /**
     * Returns a URL which an admin with permission can use to log in as the user
     *
     * @param string|null $sForwardTo Where to forward the user to after login
     * @param string|null $sReturnTo  Where to return the original user to when returning
     *
     * @return string
     * @throws FactoryException
     */
    public function getLoginUrl(string $sForwardTo = null, string $sReturnTo = null): string
    {
        return \Nails\Auth\Helper\User::compileLoginUrl(
            $this->id_md5,
            $this->password_md5,
            $sForwardTo,
            $sReturnTo
        );
    }

    // --------------------------------------------------------------------------

    public function group(): Group
    {
        if (empty($this->group) && !empty($this->group_id)) {
            $oModel      = Factory::model('UserGroup', Constants::MODULE_SLUG);
            $this->group = $oModel->getById($this->group_id);
        }

        return $this->group;
    }
}

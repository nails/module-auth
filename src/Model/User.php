<?php

/**
 * This model contains all methods for interacting with users.
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    Model
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Auth\Model;

use Nails\Auth\Constants;
use Nails\Auth\Events;
use Nails\Auth\Exception\User\MergeException;
use Nails\Auth\Factory\Email\NewUser;
use Nails\Auth\Factory\Email\VerifyEmail;
use Nails\Auth\Model\User\Email;
use Nails\Auth\Model\User\Group;
use Nails\Auth\Model\User\Password;
use Nails\Auth\Resource;
use Nails\Common\Exception\EnvironmentException;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\ModelException;
use Nails\Common\Exception\NailsException;
use Nails\Common\Factory\Model\Field;
use Nails\Common\Model\Base;
use Nails\Common\Service\Cookie;
use Nails\Common\Service\Database;
use Nails\Common\Service\DateTime;
use Nails\Common\Service\Encrypt;
use Nails\Common\Service\ErrorHandler;
use Nails\Common\Service\Event;
use Nails\Common\Service\Input;
use Nails\Common\Service\Session;
use Nails\Common\Traits\Model\Mergeable;
use Nails\Components;
use Nails\Config;
use Nails\Environment;
use Nails\Factory;
use Nails\Testing;
use ReflectionException;
use stdClass;

/**
 * Class User
 *
 * @package Nails\Auth\Model
 */
class User extends Base
{
    use Mergeable;

    // --------------------------------------------------------------------------

    /**
     * The table this model represents
     *
     * @var string
     */
    const TABLE = NAILS_DB_PREFIX . 'user';

    /**
     * The user meta table
     *
     * @var string
     */
    const TABLE_META = NAILS_DB_PREFIX . 'user_meta_app';

    /**
     * The name of the resource to use (as passed to \Nails\Factory::resource())
     *
     * @var string
     */
    const RESOURCE_NAME = 'User';

    /**
     * The provider of the resource to use (as passed to \Nails\Factory::resource())
     *
     * @var string
     */
    const RESOURCE_PROVIDER = Constants::MODULE_SLUG;

    /**
     * The default column to sort on
     *
     * @var string|null
     */
    const DEFAULT_SORT_COLUMN = 'id';

    /**
     * The default sort order
     *
     * @var string
     */
    const DEFAULT_SORT_ORDER = self::SORT_DESC;

    /**
     * Any fields which should be considered sensitive
     *
     * @var string[]
     */
    const SENSITIVE_FIELDS = [
        'password',
        'password_md5',
        'salt',
        'forgotten_password_code',
        'remember_code',
    ];

    /**
     * The name of the "remember me" cookie
     *
     * @var string
     */
    const REMEMBER_ME_COOKIE = 'nailsrememberme';

    /**
     * Supported genders
     *
     * @var string
     */
    const GENDER_UNDISCLOSED = 'UNDISCLOSED';
    const GENDER_MALE        = 'MALE';
    const GENDER_FEMALE      = 'FEMALE';
    const GENDER_TRANSGENDER = 'TRANSGENDER';
    const GENDER_OTHER       = 'OTHER';

    const AUTO_SET_USER = false;

    // --------------------------------------------------------------------------

    /**
     * The ID of the active user
     *
     * @var int|null
     */
    protected $iActiveUserId;

    /**
     * The Active User object
     *
     * @var Resource\User
     */
    protected $oActiveUser;

    /**
     * Whether the active user is to be remembered
     *
     * @var bool|null
     */
    protected $bIsRemembered = null;

    /**
     * Whether the active user is logged in or not
     *
     * @var bool
     */
    protected $bIsLoggedIn = false;

    /**
     * The name of the "Admin recovery" field
     *
     * @var string
     */
    protected $sAdminRecoveryField = 'nailsAdminRecoveryData';

    /**
     * The columns in the user table
     *
     * @var array|null
     */
    protected $aUserColumns = null;

    /**
     * The columns in the user meta table
     *
     * @var array|null
     */
    protected $aMetaColumns = null;

    /**
     * The user group model
     *
     * @var Group
     */
    protected $oGroupModel;

    /**
     * The user email model
     *
     * @var Email
     */
    protected $oEmailModel;

    /**
     * The name of the "slug" column
     *
     * @var string
     */
    protected $tableSlugColumn = 'username';

    // --------------------------------------------------------------------------

    /**
     * User constructor.
     *
     * @throws FactoryException
     * @throws ModelException
     */
    public function __construct()
    {
        parent::__construct();

        // --------------------------------------------------------------------------

        $this->oGroupModel = Factory::model('UserGroup', Constants::MODULE_SLUG);
        $this->oEmailModel = Factory::model('UserEmail', Constants::MODULE_SLUG);

        // --------------------------------------------------------------------------

        //  Clear the activeUser
        $this->clearActiveUser();
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the searchable columns for this module
     *
     * @return string[]
     */
    public function getSearchableColumns(): array
    {
        return [
            $this->getTableAlias() . '.id',
            $this->getTableAlias() . '.username',
            $this->oEmailModel->getTableAlias() . '.email',
            [
                $this->getTableAlias() . '.first_name',
                $this->getTableAlias() . '.last_name',
            ],
        ];
    }


    // --------------------------------------------------------------------------

    /**
     * Initialise the generic user model
     *
     * @return void
     * @throws FactoryException
     * @throws ModelException
     * @throws NailsException
     * @throws ReflectionException
     */
    public function init()
    {
        /** @var Input $oInput */
        $oInput         = Factory::service('Input');
        $iTestingAsUser = $oInput->header(Testing::TEST_HEADER_USER_NAME);

        if (Environment::is(Environment::ENV_HTTP_TEST) && $iTestingAsUser) {

            $oUser = $this->getById($iTestingAsUser);
            if (empty($oUser)) {
                set_status_header(500);
                ErrorHandler::halt('Not a valid user ID');
            }
            $this->setLoginData($oUser->id);

        } else {

            //  Refresh user's session
            $this->refreshSession();

            //  If no user is logged in, see if there's a remembered user to be logged in
            if (!$this->isLoggedIn()) {
                $this->loginRememberedUser();
            }
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Log in a previously logged in user
     *
     * @return bool
     * @throws FactoryException
     * @throws ModelException
     * @throws NailsException
     * @throws ReflectionException
     */
    protected function loginRememberedUser()
    {
        //  Is remember me functionality enabled?
        /** @var \Nails\Common\Service\Config $oConfig */
        $oConfig = Factory::service('Config');
        $oConfig->load('auth/auth');

        if (!$oConfig->item('authEnableRememberMe')) {
            return false;
        }

        // --------------------------------------------------------------------------

        //  Get the credentials from the cookie set earlier
        /** @var Cookie $oCookie */
        $oCookie  = Factory::service('Cookie');
        $remember = $oCookie->read(static::REMEMBER_ME_COOKIE);

        if ($remember) {

            $remember = explode('|', $remember);
            $email    = isset($remember[0]) ? $remember[0] : null;
            $code     = isset($remember[1]) ? $remember[1] : null;

            if ($email && $code) {

                //  Look up the user so we can cross-check the codes
                $user = $this->getByEmail($email);

                if ($user && $code === $user->remember_code) {
                    //  User was validated, log them in!
                    $this->setLoginData($user->id);
                    $this->iActiveUserId = $user->id;
                }
            }
        }

        return true;
    }

    // --------------------------------------------------------------------------

    /**
     * Fetches a value from the active user's session data
     *
     * @param string $sKeys      The key to look up in activeUser
     * @param string $sDelimiter If multiple fields are requested they'll be joined by this string
     *
     * @return mixed
     */
    public function activeUser(string $sKeys = '', string $sDelimiter = ' ')
    {
        //  Only look for a value if we're logged in
        if (!$this->isLoggedIn()) {
            return false;
        }

        // --------------------------------------------------------------------------

        //  If $sKeys is false just return the user object in its entirety
        if (empty($sKeys)) {
            return $this->oActiveUser;
        }

        // --------------------------------------------------------------------------

        //  If only one key is being requested then don't do anything fancy
        if (strpos($sKeys, ',') === false) {

            $val = isset($this->oActiveUser->{trim($sKeys)}) ? $this->oActiveUser->{trim($sKeys)} : null;

        } else {

            //  More than one key
            $aKeys = explode(',', $sKeys);
            $aKeys = array_filter($aKeys);
            $aOut  = [];

            foreach ($aKeys as $sKey) {

                //  If something is found, use that.
                if (isset($this->oActiveUser->{trim($sKey)})) {
                    $aOut[] = $this->oActiveUser->{trim($sKey)};
                }
            }

            //  If nothing was found, just return null
            if (empty($aOut)) {
                $val = null;
            } else {
                $val = implode($sDelimiter, $aOut);
            }
        }

        return $val;
    }

    // --------------------------------------------------------------------------

    /**
     * Set the active user
     *
     * @param Resource\User $oUser The user object to set
     *
     * @throws FactoryException
     */
    public function setActiveUser(Resource\User $oUser)
    {
        $this->oActiveUser = $oUser;
        /** @var DateTime $oDateTimeService */
        $oDateTimeService = Factory::service('DateTime');

        //  Set the user's date/time formats
        $sFormatDate = $this->activeUser('pref_date_format');
        $sFormatDate = $sFormatDate ? $sFormatDate : $oDateTimeService->getDateFormatDefaultSlug();

        $sFormatTime = $this->activeUser('pref_time_format');
        $sFormatTime = $sFormatTime ? $sFormatTime : $oDateTimeService->getTimeFormatDefaultSlug();

        $oDateTimeService->setUserFormats($sFormatDate, $sFormatTime);
    }

    // --------------------------------------------------------------------------

    /**
     * Clear the active user
     *
     * @return void
     * @throws FactoryException
     */
    public function clearActiveUser()
    {
        $this->oActiveUser = Factory::resource(static::RESOURCE_NAME, static::RESOURCE_PROVIDER, (object) []);
    }

    // --------------------------------------------------------------------------

    /**
     * Set the user's login data
     *
     * @param Resource\User|string|int $mUser           The user's Resource, ID, or identifier
     * @param bool                     $bSetSessionData Whether to set the session data or not
     * @param bool                     $bIsOverride     Whether the login is an override
     *
     * @return bool
     * @throws FactoryException
     * @throws ModelException
     * @throws NailsException
     * @throws ReflectionException
     */
    public function setLoginData($mUser, bool $bSetSessionData = true, bool $bIsOverride = false): bool
    {
        //  Valid user?
        if ($mUser instanceof Resource\User) {

            $oUser  = $mUser;
            $sError = null;

        } elseif (is_numeric($mUser)) {

            $oUser  = $this->getById($mUser);
            $sError = 'Invalid User ID.';

        } elseif (is_string($mUser)) {

            Factory::helper('email');
            if (valid_email($mUser)) {
                $oUser  = $this->getByEmail($mUser);
                $sError = 'Invalid User email.';
            } else {
                $this->setError('Invalid User email.');
                return false;
            }

        } else {

            $this->setError('Invalid user ID or email.');
            return false;
        }

        // --------------------------------------------------------------------------

        //  Test user
        if (!$oUser) {

            $this->setError($sError);
            return false;

        } elseif ($oUser->is_suspended) {

            $this->setError('User is suspended.');
            return false;

        } else {

            //  Set the flag
            $this->bIsLoggedIn = true;

            //  Set session variables
            if ($bSetSessionData) {
                /** @var Session $oSession */
                $oSession = Factory::service('Session');
                $oSession->setUserData([
                    'id'       => $oUser->id,
                    'email'    => $oUser->email,
                    'group_id' => $oUser->group_id,
                ]);
            }

            //  Set the active user
            $this->setActiveUser($oUser);

            /** @var Event $oEventService */
            $oEventService = Factory::service('Event');
            $oEventService->trigger(
                Events::USER_LOG_IN,
                Events::getEventNamespace(),
                [$oUser, $bSetSessionData, $bIsOverride]
            );

            return true;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Clears the login data for a user
     *
     * @return  void
     * @throws FactoryException
     * @throws NailsException
     * @throws ReflectionException
     */
    public function clearLoginData()
    {
        $iUserId = $this->activeUser('id');

        //  Clear the session
        /** @var Session $oSession */
        $oSession = Factory::service('Session');
        $oSession
            ->unsetUserData('id')
            ->unsetUserData('email')
            ->unsetUserData('group_id');

        //  Set the flag
        $this->bIsLoggedIn = false;

        //  Reset the activeUser
        $this->clearActiveUser();

        //  Remove any remember me cookie
        $this->clearRememberCookie();

        /** @var Event $oEventService */
        $oEventService = Factory::service('Event');
        $oEventService->trigger(
            Events::USER_LOG_OUT,
            Events::getEventNamespace(),
            [$iUserId]
        );
    }

    // --------------------------------------------------------------------------

    /**
     * Determines whether the active user is logged in or not.
     *
     * @return bool
     */
    public function isLoggedIn(): bool
    {
        return $this->bIsLoggedIn;
    }

    // --------------------------------------------------------------------------

    /**
     * Determines whether the active user is to be remembered
     *
     * @return bool
     */
    public function isRemembered(): bool
    {
        //  Deja vu?
        if (!is_null($this->bIsRemembered)) {
            return $this->bIsRemembered;
        }

        // --------------------------------------------------------------------------

        /**
         * Look for the remember me cookie and explode it, if we're landed with a 2
         * part array then it's likely this is a valid cookie - however, this test
         * is, obviously, not gonna detect a spoof.
         */

        $cookie = get_cookie(static::REMEMBER_ME_COOKIE);
        $cookie = explode('|', $cookie);

        $this->bIsRemembered = count($cookie) == 2;

        return $this->bIsRemembered;
    }

    // --------------------------------------------------------------------------

    // --------------------------------------------------------------------------

    /**
     * Maintain backwards compatability
     *
     * @deprecated Use ->isRemebered()
     */
    public function bIsRemembered(): bool
    {
        deprecatedError(__METHOD__, static::class . '::isRemebered()');
        return $this->bIsRemembered();
    }

    // --------------------------------------------------------------------------

    /**
     * Determines whether the active user group has admin permissions.
     *
     * @param mixed $mUser The user to check, uses activeUser if null
     *
     * @return bool
     * @throws FactoryException
     * @throws ModelException
     */
    public function isAdmin($mUser = null): bool
    {
        return $this->hasPermission('admin:.+', $mUser);
    }

    // --------------------------------------------------------------------------

    /**
     * When an admin 'logs in as' another user a hash is added to the session so
     * the system can log them back in. This method is simply a quick and logical
     * way of checking if the session variable exists.
     *
     * @return bool
     * @throws FactoryException
     */
    public function wasAdmin(): bool
    {
        /** @var Session $oSession */
        $oSession = Factory::service('Session');
        return (bool) $oSession->getUserData($this->sAdminRecoveryField);
    }

    // --------------------------------------------------------------------------

    /**
     * Adds to the admin recovery array, allowing suers to login as other users multiple times, and come back
     *
     * @param int    $iloggingInAs The ID of the user who is being imitated
     * @param string $sReturnTo    Where to redirect the user when they log back in
     *
     * @return $this
     * @throws FactoryException
     */
    public function setAdminRecoveryData(int $iloggingInAs, string $sReturnTo = ''): self
    {
        /** @var Session $oSession */
        $oSession = Factory::service('Session');
        /** @var Input $oInput */
        $oInput = Factory::service('Input');

        $aExistingRecoveryData = $oSession->getUserData($this->sAdminRecoveryField);
        if (empty($aExistingRecoveryData)) {
            $aExistingRecoveryData = [];
        }

        /** @var Resource\User\AdminRecovery $oAdminRecoveryData */
        $oAdminRecoveryData = Factory::resource('UserAdminRecovery', Constants::MODULE_SLUG, [
            'oldUserId' => activeUser('id'),
            'newUserId' => $iloggingInAs,
            'hash'      => activeUser('password_md5'),
            'name'      => activeUser('name'),
            'email'     => activeUser('email'),
            'returnTo'  => empty($sReturnTo) ? $oInput->server('REQUEST_URI') : $sReturnTo,
            'loginUrl'  => siteUrl(
                sprintf(
                    'auth/override/login_as/%s/%s?returningAdmin=1',
                    activeUser('id_md5'),
                    activeUser('password_md5')
                )
            ),
        ]);

        //  Put the new session onto the stack and save to the session
        $aExistingRecoveryData[] = $oAdminRecoveryData;

        $oSession->setUserData($this->sAdminRecoveryField, $aExistingRecoveryData);

        return $this;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the recovery data at the bottom of the stack, i.e the most recently added
     *
     * @return Resource\User\AdminRecovery|null
     * @throws FactoryException
     */
    public function getAdminRecoveryData(): ?Resource\User\AdminRecovery
    {
        /** @var Session $oSession */
        $oSession              = Factory::service('Session');
        $aExistingRecoveryData = $oSession->getUserData($this->sAdminRecoveryField);

        if (empty($aExistingRecoveryData)) {
            return null;
        }
        return end($aExistingRecoveryData) ?: null;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the admin recovery URL
     *
     * @return string|null
     * @throws FactoryException
     */
    public function getAdminRecoveryUrl(): ?string
    {
        $oData = $this->getAdminRecoveryData();
        return $oData->loginUrl ?? null;
    }

    // --------------------------------------------------------------------------

    /**
     * Removes the most recently added recovery data from the stack
     *
     * @return $this
     * @throws FactoryException
     */
    public function unsetAdminRecoveryData(): self
    {
        /** @var Session $oSession */
        $oSession = Factory::service('Session');

        $aExistingRecoveryData = $oSession->getUserData($this->sAdminRecoveryField);

        if (empty($aExistingRecoveryData)) {
            $aExistingRecoveryData = [];
        } else {
            array_pop($aExistingRecoveryData);
        }

        $oSession->setUserData($this->sAdminRecoveryField, $aExistingRecoveryData);

        return $this;
    }

    // --------------------------------------------------------------------------

    /**
     * Determines whether the active user is a superuser. Extend this method to
     * alter it's response.
     *
     * @param mixed $mUser The user to check, uses activeUser if null
     *
     * @return bool
     * @throws FactoryException
     * @throws ModelException
     */
    public function isSuperuser($mUser = null): bool
    {
        return $this->hasPermission('admin:superuser', $mUser);
    }

    // --------------------------------------------------------------------------

    /**
     * Determines whether the specified user has a certain ACL permission
     *
     * @param string $sSearch The permission to check for
     * @param mixed  $mUser   The user to check for; if null uses activeUser, if numeric, fetches user, if object uses that object
     *
     * @return bool
     * @throws FactoryException
     * @throws ModelException
     */
    public function hasPermission(string $sSearch, $mUser = null): bool
    {
        //  Fetch the correct ACL
        if (is_numeric($mUser)) {

            $oUser = $this->getById($mUser);

            if (isset($oUser->acl)) {
                $aAcl = $oUser->acl;
                unset($oUser);

            } else {
                return false;
            }

        } elseif (isset($mUser->acl)) {
            $aAcl = $mUser->acl;

        } else {
            $aAcl = (array) $this->activeUser('acl');
        }

        // --------------------------------------------------------------------------

        // Super users or CLI users can do anything their heart desires
        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        if (in_array('admin:superuser', $aAcl) || $oInput::isCli()) {
            return true;
        }

        // --------------------------------------------------------------------------

        /**
         * Test the ACL
         * We're going to use regular expressions here so we can allow for some
         * flexibility in the search, i.e admin:* would return true if the user has
         * access to any of admin.
         */

        /**
         * Replace :* with :.* - this is a common mistake when using the permission
         * system (i.e., assuming that star on it's own will match)
         */

        $sSearch = strtolower(preg_replace('/:\*/', ':.*', $sSearch));

        foreach ($aAcl as $sPermission) {

            $sPattern = '/^' . $sSearch . '$/';
            $bMatch   = preg_match($sPattern, $sPermission);

            if ($bMatch) {
                return true;
            }
        }

        return false;
    }

    // --------------------------------------------------------------------------

    /**
     * This method applies the conditionals which are common across the get_*()
     * methods and the count() method.
     *
     * @param array $aData Data passed from the calling method
     *
     * @return void
     * @throws FactoryException
     * @throws ModelException
     */
    protected function getCountCommon(array &$aData = []): void
    {
        //  Define the selects
        /** @var Database $oDb */
        $oDb = Factory::service('Database');
        $oDb->select($this->getTableAlias() . '.*');
        $oDb->select([
            $this->oEmailModel->getTableAlias() . '.email',
            $this->oEmailModel->getTableAlias() . '.code email_verification_code',
            $this->oEmailModel->getTableAlias() . '.is_verified email_is_verified',
            $this->oEmailModel->getTableAlias() . '.date_verified email_is_verified_on',
        ]);
        $oDb->select(
            array_values(
                array_map(
                    function (Field $oField) {
                        return $this->getMetaTableAlias(true) . $oField->key;
                    },
                    $this->describeMetaFields()
                )
            )
        );
        $oDb->select([
            $this->oGroupModel->getTableAlias() . '.slug group_slug',
            $this->oGroupModel->getTableAlias() . '.label group_name',
            $this->oGroupModel->getTableAlias() . '.default_homepage group_homepage',
            $this->oGroupModel->getTableAlias() . '.acl group_acl',
        ]);

        // --------------------------------------------------------------------------

        //  Define the joins
        $oDb->join(
            $this->oEmailModel->getTableName() . ' ' . $this->oEmailModel->getTableAlias(),
            $this->getTableAlias() . '.id = ' . $this->oEmailModel->getTableAlias() . '.user_id AND ' . $this->oEmailModel->getTableAlias() . '.is_primary = 1',
            'LEFT'
        );

        $oDb->join(
            $this->getMetaTableName(true),
            $this->getTableAlias() . '.id = ' . $this->getMetaTableAlias() . '.user_id',
            'LEFT'
        );

        $oDb->join(
            $this->oGroupModel->getTableName() . ' ' . $this->oGroupModel->getTableAlias(),
            $this->getTableAlias() . '.group_id = ' . $this->oGroupModel->getTableAlias() . '.id',
            'LEFT'
        );

        //  Let the parent method handle sorting, etc
        parent::getCountCommon($aData);
    }

    // --------------------------------------------------------------------------

    /**
     * Filter out duplicates and prefix column names if necessary
     *
     * @param string $sPrefix
     * @param array  $aCols
     *
     * @return array
     */
    protected function prepareDbColumns($sPrefix = '', $aCols = [])
    {
        //  Clean up
        $aCols = array_unique($aCols);
        $aCols = array_filter($aCols);

        //  Prefix all the values, if needed
        if ($sPrefix) {
            foreach ($aCols as $key => &$value) {
                $value = $sPrefix . '.' . $value;
            }
        }

        return $aCols;
    }

    // --------------------------------------------------------------------------

    /**
     * Look up a user by their identifier
     *
     * @param string $sIdentifier The user's identifier, either an email address or a username
     * @param array  $aData       Any additional data to pass in
     *
     * @return Resource\User|null
     * @throws FactoryException
     * @throws ModelException
     */
    public function getByIdentifier(string $sIdentifier, array $aData = []): ?Resource\User
    {
        switch (Config::get('APP_NATIVE_LOGIN_USING')) {

            case 'EMAIL':
                return $this->getByEmail($sIdentifier, $aData);
                break;

            case 'USERNAME':
                return $this->getByUsername($sIdentifier, $aData);
                break;

            default:
                Factory::helper('email');
                if (valid_email($sIdentifier)) {
                    return $this->getByEmail($sIdentifier, $aData);
                } else {
                    return $this->getByUsername($sIdentifier, $aData);
                }
                break;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Get a user by their email address
     *
     * @param string $sEmail The user's email address
     * @param array  $aData  Any additional data to pass in
     *
     * @return Resource\User|null
     * @throws FactoryException
     * @throws ModelException
     */
    public function getByEmail(string $sEmail, array $aData = []): ?Resource\User
    {
        //  Look up the email, and if we find an ID then fetch that user
        /** @var Database $oDb */
        $oDb = Factory::service('Database');
        $oDb->select('user_id');
        $oDb->where('email', trim($sEmail));
        $oUser = $oDb->get($this->oEmailModel->getTableName())->row();

        return $oUser ? $this->getById($oUser->user_id, $aData) : null;
    }

    // --------------------------------------------------------------------------

    /**
     * Get a user by their username
     *
     * @param string $sUsername The user's username
     * @param array  $aData     Any additional data to pass in
     *
     * @return Resource\User|null
     * @throws ModelException
     */
    public function getByUsername(string $sUsername, array $aData = []): ?Resource\User
    {
        return $this->getByColumn($this->getColumn('slug'), $sUsername, $aData, false);
    }

    // --------------------------------------------------------------------------

    /**
     * Get a specific user by a MD5 hash of their ID and password
     *
     * @param string $sMd5Id The MD5 hash of their ID
     * @param string $sMd5Pw The MD5 hash of their password
     * @param array  $aData  Any additional data to pass in
     *
     * @return Resource\User|null
     * @throws ModelException
     */
    public function getByHashes(string $sMd5Id, string $sMd5Pw, array $aData = []): ?Resource\User
    {
        if (empty($sMd5Id) || empty($sMd5Pw)) {
            return null;
        }

        /** @var Database $oDb */
        $oDb = Factory::service('Database');
        $oDb->select('id');
        $oDb->where('id_md5', $sMd5Id);
        $oDb->where('password_md5', $sMd5Pw);
        $oUser = $oDb->get($this->getTableName())->row();

        return $oUser
            ? $this->getById($oUser->id, $aData)
            : null;
    }

    // --------------------------------------------------------------------------

    /**
     * Get a user by their referral code
     *
     * @param string $sReferralCode The user's referral code
     * @param array  $aData         Any additional data to pass in
     *
     * @return Resource\User|null
     * @throws ModelException
     */
    public function getByReferral(string $sReferralCode, array $aData = []): ?Resource\User
    {
        return $this->getByColumn($this->getColumn('referral'), $sReferralCode, $aData, false);
    }

    // --------------------------------------------------------------------------

    /**
     * Get all the email addresses which are registered to a particular user ID
     *
     * @param int $iId The user's ID
     *
     * @return \Nails\Common\Resource[]
     * @throws ModelException
     */
    public function getEmailsForUser($iId)
    {
        return $this->oEmailModel->getAll([
            'where' => [
                ['user_id', $iId],
            ],
            'sort'  => [
                ['date_added', 'DESC'],
                ['email', 'ASC'],
            ],
        ]);
    }

    // --------------------------------------------------------------------------

    /**
     * Update a user, if $iUserId is not set method will attempt to update the
     * active user. If $data is passed then the method will attempt to update
     * the user and/or user_meta_* tables
     *
     * @param int   $iUserId The ID of the user to update
     * @param array $aData   Any data to be updated
     *
     * @return bool
     * @throws FactoryException
     * @throws ModelException
     */
    public function update($iUserId = null, array $aData = null): bool
    {
        /** @var \DateTime $oDate */
        $oDate   = Factory::factory('DateTime');
        $aData   = (array) $aData;
        $iUserId = $this->getUserId($iUserId);
        if (empty($iUserId)) {
            return false;
        }

        // --------------------------------------------------------------------------

        $oOldUser = $this->getById($iUserId);
        if (empty($oOldUser)) {
            $this->setError('Invalid user ID');
            return false;
        }

        //  Deep clone so we're sure it isn't inadvertently updated
        $oOldUser = unserialize(serialize($oOldUser));

        // --------------------------------------------------------------------------

        //  If there's some data we'll need to know the columns of `user`
        //  We also want to unset any 'dangerous' items then set it for the query

        /** @var Database $oDb */
        $oDb = Factory::service('Database');
        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        /** @var Password $oUserPasswordModel */
        $oUserPasswordModel = Factory::model('UserPassword', Constants::MODULE_SLUG);

        if ($aData) {

            //  Set the cols in `user` (rather than querying the DB)
            $aCols = array_keys($this->describeFields());

            //  Safety first, no updating of sensitive fields
            unset($aData['id']);
            unset($aData['id_md5']);
            unset($aData['password_md5']);
            unset($aData['password_engine']);
            unset($aData['password_changed']);
            unset($aData['salt']);

            $sNewPassword = getFromArray('password', $aData);
            unset($aData['password']);

            //  Set the data
            $aDataUser         = [];
            $aDataMeta         = [];
            $sDataEmail        = '';
            $sDataUsername     = '';
            $bResetMfaQuestion = false;
            $bResetMfaDevice   = false;

            foreach ($aData as $key => $val) {

                //  user or user_meta?
                if (array_search($key, $aCols) !== false) {

                    //  Careful now, some items cannot be blank and must be null
                    switch ($key) {

                        //  Int or null
                        case 'profile_img':
                            $aDataUser[$key] = (int) $val ?: null;
                            break;

                        //  Null if empty
                        case 'dob':
                            $aDataUser[$key] = $val ?: null;
                            break;

                        //  Boolean
                        case 'temp_pw':
                            $aDataUser[$key] = (bool) $val;
                            break;

                        default:
                            $aDataUser[$key] = $val;
                            break;
                    }

                } elseif ($key == 'email') {
                    $sDataEmail = strtolower(trim($val));
                } elseif ($key == 'username') {
                    $sDataUsername = strtolower(trim($val));
                } elseif ($key == 'reset_mfa_question') {
                    $bResetMfaQuestion = $val;
                } elseif ($key == 'reset_mfa_device') {
                    $bResetMfaDevice = $val;
                } else {
                    $aDataMeta[$key] = $val;
                }
            }

            // --------------------------------------------------------------------------

            //  If a username has been passed then check if it's available
            if (!empty($sDataUsername)) {
                //  Check username is valid
                if (!$this->isValidUsername($sDataUsername, true, $iUserId)) {
                    //  isValidUsername will set the error message
                    return false;
                } else {
                    $aDataUser['username'] = $sDataUsername;
                }
            }

            // --------------------------------------------------------------------------

            if (!empty($sDataEmail)) {
                Factory::helper('email');
                if (!valid_email($sDataEmail)) {
                    $this->setError('"' . $sDataEmail . '" is not a valid email address.');
                    return false;
                }
            }

            // --------------------------------------------------------------------------

            //  Begin transaction
            try {

                $oDb->transaction()->start();

                // --------------------------------------------------------------------------

                //  Resetting 2FA?
                if ($bResetMfaQuestion || $bResetMfaDevice) {

                    /** @var \Nails\Common\Service\Config $oConfig */
                    $oConfig = Factory::service('Config');
                    $oConfig->load('auth/auth');
                    $sTwoFactorMode = $oConfig->item('authTwoFactorMode');

                    if ($sTwoFactorMode == 'QUESTION' && $bResetMfaQuestion) {

                        $oDb->where('user_id', $iUserId);
                        if (!$oDb->delete(Config::get('NAILS_DB_PREFIX') . 'user_auth_two_factor_question')) {
                            $oDb->transaction()->rollback();
                            $this->setError('Could not reset user\'s Multi Factor Authentication questions.');
                            return false;
                        }

                    } elseif ($sTwoFactorMode == 'DEVICE' && $bResetMfaDevice) {

                        $oDb->where('user_id', $iUserId);
                        if (!$oDb->delete(Config::get('NAILS_DB_PREFIX') . 'user_auth_two_factor_device_secret')) {
                            $oDb->transaction()->rollback();
                            $this->setError('Could not reset user\'s Multi Factor Authentication device.');
                            return false;
                        }
                    }
                }

                // --------------------------------------------------------------------------

                //  Update the user table
                $oDb->where('id', $iUserId);
                $oDb->set('last_update', 'NOW()', false);

                if ($aDataUser) {
                    $oDb->set($aDataUser);
                }

                if (!$oDb->update($this->getTableName())) {
                    throw new NailsException('Failed to update user table.');
                }

                // --------------------------------------------------------------------------

                //  Update the meta table
                if ($aDataMeta) {
                    $oDb->where('user_id', $iUserId);
                    $oDb->set($aDataMeta);
                    if (!$oDb->update($this->getMetaTableName())) {
                        throw new NailsException('Failed to update user meta table.');
                    }
                }

                // --------------------------------------------------------------------------

                //  Update the password if it has been supplied
                if (!empty($sNewPassword)) {
                    $bIsTemp = (bool) getFromArray('temp_pw', $aData);
                    if (!$oUserPasswordModel->change($iUserId, $sNewPassword, $bIsTemp)) {
                        throw new NailsException(
                            'Failed to change password. ' .
                            $oUserPasswordModel->lastError()
                        );
                    }
                }

                // --------------------------------------------------------------------------

                //  If an email has been passed then attempt to update the user's email too
                if ($sDataEmail) {

                    //  Check if the email is already being used
                    $oDb->where('email', $sDataEmail);
                    $oEmail = $oDb->get($this->oEmailModel->getTableName())->row();

                    if ($oEmail) {

                        /**
                         * Email is in use, if it's in use by the ID of this user then set
                         * it as the primary email for this account. If it's in use by
                         * another user then error
                         */

                        if ($oEmail->user_id == $iUserId) {
                            $this->emailMakePrimary($oEmail->email);
                        } else {
                            throw new NailsException('Email is already in use.');
                        }

                    } else {

                        /**
                         * Doesn't appear to be in use, add as a new email address and
                         * make it the primary one
                         */
                        $this->emailAdd($sDataEmail, $iUserId, true, false, true, false);
                    }
                }

                $oDb->transaction()->commit();

            } catch (\Exception $e) {
                $oDb->transaction()->rollback();
                $this->setError($e->getMessage());
                return false;
            }

        } else {

            /**
             * If there was no data then run an update anyway on just user table. We need to
             * do this as some methods will use $oDb->set() before calling update(); not
             * sure if this is a bad design or not... sorry.
             */

            $oDb->set('last_update', 'NOW()', false);
            $oDb->where('id', $iUserId);
            $oDb->update($this->getTableName());
        }

        // --------------------------------------------------------------------------

        //  If we just updated the active user we should probably update their session info
        if ($iUserId == $this->activeUser('id')) {
            $this->oActiveUser->last_update = $oDate->format('Y-m-d H:i:s');
            if ($aData) {
                foreach ($aData as $key => $val) {
                    $this->oActiveUser->{$key} = $val;
                }
            }

            // --------------------------------------------------------------------------

            //  Do we need to update any timezone/date/time preferences?
            if (isset($aData['timezone'])) {
                /** @var DateTime $oDateTimeService */
                $oDateTimeService = Factory::service('DateTime');
                $oDateTimeService->setUserTimezone($aData['timezone']);
            }

            if (isset($aData['datetime_format_date'])) {
                /** @var DateTime $oDateTimeService */
                $oDateTimeService = Factory::service('DateTime');
                $oDateTimeService->setUserDateFormat($aData['datetime_format_date']);
            }

            if (isset($aData['datetime_format_time'])) {
                /** @var DateTime $oDateTimeService */
                $oDateTimeService = Factory::service('DateTime');
                $oDateTimeService->setUserTimeFormat($aData['datetime_format_time']);
            }

            // --------------------------------------------------------------------------

            //  If there's a remember me cookie then update that too, but only if the password
            //  or email address has changed

            if ((isset($aData['email']) || !empty($bPasswordUpdated)) && $this->isRemembered()) {
                $this->setRememberCookie();
            }
        }

        //  Clear the caches for this user
        $this->unsetCacheUser($iUserId);

        $this->triggerEvent(
            Events::USER_MODIFIED,
            [$iUserId, $oOldUser]
        );

        return true;
    }

    // --------------------------------------------------------------------------

    /**
     * Works out the correct user ID, falls back to activeUser()
     *
     * @param int|null $iUserId The user ID to use
     *
     * @return int
     */
    protected function getUserId($iUserId = null)
    {
        if (!empty($iUserId)) {
            $iUid = (int) $iUserId;
        } elseif ($this->activeUser('id')) {
            $iUid = $this->activeUser('id');
        } else {
            $this->setError('No user ID set');
            return false;
        }

        return $iUid;
    }

    // --------------------------------------------------------------------------

    /**
     * Saves a user object to the persistent cache
     *
     * @param int   $iUserId The user ID to cache
     * @param array $aData   The data array
     *
     * @return bool
     * @throws ModelException
     */
    public function setCacheUser($iUserId, $aData = [])
    {
        $this->unsetCacheUser($iUserId);
        $oUser = $this->getById($iUserId);

        if (empty($oUser)) {
            return false;
        }

        $this->setCache(
            $this->prepareCacheKey($this->getColumn('id'), $oUser->id, $aData),
            $oUser
        );

        return true;
    }

    // --------------------------------------------------------------------------

    /**
     * Removes a user from the cache
     *
     * @param int $iUserId The User ID to remove
     */
    public function unsetCacheUser($iUserId)
    {
        $this->unsetCachePrefix(
            $this->prepareCacheKey($this->getColumn('id'), $iUserId)
        );
    }

    // --------------------------------------------------------------------------

    /**
     * Adds a new email to the user email table. Will optionally send the verification email, too.
     *
     * @param string   $sEmail        The email address to add
     * @param int|null $iUserId       The ID of the user to add for, defaults to $this->activeUser('id')
     * @param bool     $bIsPrimary    Whether or not the email address should be the primary email address for the user
     * @param bool     $bIsVerified   Whether or not the email should be marked as verified
     * @param bool     $bSendEmail    If unverified, whether or not the verification email should be sent
     * @param bool     $bTriggerEvent Whether to trigger the user modified event
     *
     * @return bool|string          String containing verification code on success, false on failure
     * @throws FactoryException
     * @throws ModelException
     */
    public function emailAdd(
        string $sEmail,
        int $iUserId = null,
        bool $bIsPrimary = false,
        bool $bIsVerified = false,
        bool $bSendEmail = true,
        bool $bTriggerEvent = true
    ) {

        $iUserId = empty($iUserId) ? $this->activeUser('id') : $iUserId;
        $sEmail  = trim(strtolower($sEmail));
        $oUser   = $this->getById($iUserId);

        if (empty($oUser)) {
            $this->setError('Invalid User ID');
            return false;
        }

        // --------------------------------------------------------------------------

        //  Make sure the email is valid
        Factory::helper('email');
        if (!valid_email($sEmail)) {
            $this->setError('"' . $sEmail . '" is not a valid email address');
            return false;
        }

        // --------------------------------------------------------------------------

        /**
         * Test email, if it's in use and for the same user then return true. If it's
         * in use by a different user then return an error.
         */

        /** @var Database $oDb */
        $oDb = Factory::service('Database');
        $oDb->select('id, user_id, is_verified, code');
        $oDb->where('email', $sEmail);
        $oTest = $oDb->get($this->oEmailModel->getTableName())->row();

        if ($oTest) {

            if ($oTest->user_id == $oUser->id) {

                /**
                 * In use, but belongs to the same user - return the code (imitates
                 * behavior of newly added email)
                 */

                if ($bIsPrimary) {
                    $this->emailMakePrimary($oTest->id, $oUser->id, false);
                }

                //  Resend verification email?
                if ($bSendEmail && !$oTest->is_verified) {
                    $this->emailAddSendVerify($oTest->id);
                }

                $this->unsetCacheUser($oUser->id);

                if ($bTriggerEvent) {
                    $this->triggerEvent(
                        Events::USER_MODIFIED,
                        [$oUser->id, $oUser]
                    );
                }

                return $oTest->code;

            } else {

                //  In use, but belongs to another user
                $this->setError('Email in use by another user.');
                return false;
            }
        }

        // --------------------------------------------------------------------------

        /** @var Password $oPasswordModel */
        $oPasswordModel = Factory::model('UserPassword', Constants::MODULE_SLUG);
        $sCode          = $oPasswordModel->salt();

        $oDb->set('user_id', $oUser->id);
        $oDb->set('email', $sEmail);
        $oDb->set('code', $sCode);
        $oDb->set('is_verified', (bool) $bIsVerified);
        $oDb->set('date_added', 'NOW()', false);

        if ((bool) $bIsVerified) {
            $oDb->set('date_verified', 'NOW()', false);
        }

        $oDb->insert($this->oEmailModel->getTableName());

        if ($oDb->affected_rows()) {

            //  Email ID
            $iEmailId = $oDb->insert_id();

            //  Make it the primary email address?
            if ($bIsPrimary) {
                $this->emailMakePrimary($iEmailId, $oUser->id, false);
            }

            //  Send off the verification email
            if ($bSendEmail && !$bIsVerified) {
                $this->emailAddSendVerify($iEmailId);
            }

            //  Cache the user
            $this->unsetCacheUser($oUser->id);

            //  Update the activeUser
            if ($oUser->id == $this->activeUser('id')) {

                /** @var \DateTime $oDate */
                $oDate                          = Factory::factory('DateTime');
                $this->oActiveUser->last_update = $oDate->format('Y-m-d H:i:s');

                if ($bIsPrimary) {
                    $this->oActiveUser->email                   = $sEmail;
                    $this->oActiveUser->email_verification_code = $sCode;
                    $this->oActiveUser->email_is_verified       = (bool) $bIsVerified;
                    $this->oActiveUser->email_is_verified_on    = (bool) $bIsVerified ? $oDate->format('Y-m-d H:i:s') : null;
                }
            }

            if ($bTriggerEvent) {
                $this->triggerEvent(
                    Events::USER_MODIFIED,
                    [$oUser->id, $oUser]
                );
            }

            return $sCode;

        } else {
            return false;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Send, or resend, the verify email for a particular email address
     *
     * @param string|int $mEmailId The email or email  ID
     * @param int|null   $iUserId  The user's ID
     *
     * @return bool
     * @throws FactoryException
     * @throws ModelException
     */
    public function emailAddSendVerify($mEmailId, $iUserId = null)
    {
        if (!Config::get('NAILS_AUTH_EMAIL_VERIFY_ON_ADD', true)) {
            return true;
        }

        //  Fetch the email and the user's group
        /** @var Database $oDb */
        $oDb = Factory::service('Database');
        $oDb->select(
            [
                $this->oEmailModel->getTableAlias() . '.id',
                $this->oEmailModel->getTableAlias() . '.code',
                $this->oEmailModel->getTableAlias() . '.is_verified',
                $this->oEmailModel->getTableAlias() . '.user_id',
                $this->getTableAlias() . '.group_id',
            ]
        );

        if (is_numeric($mEmailId)) {
            $oDb->where($this->oEmailModel->getTableAlias() . '.id', $mEmailId);
        } else {
            $oDb->where($this->oEmailModel->getTableAlias() . '.email', $mEmailId);
        }

        if (!empty($iUserId)) {
            $oDb->where($this->oEmailModel->getTableAlias() . '.user_id', $iUserId);
        }

        $oDb->join(
            $this->getTableName(true),
            $this->getTableAlias() . '.id = ' . $this->oEmailModel->getTableAlias() . '.user_id'
        );

        $oEmailRow = $oDb->get($this->oEmailModel->getTableName() . ' ' . $this->oEmailModel->getTableAlias())->row();

        if (!$oEmailRow) {
            $this->setError('Invalid Email.');
            return false;
        }

        if ($oEmailRow->is_verified) {
            $this->setError('Email is already verified.');
            return false;
        }

        // --------------------------------------------------------------------------

        try {
            //  Allows the app to define a group specific verify email
            /** @var VerifyEmail $oEmail */
            $oEmail = Factory::factory('EmailVerifyEmail' . $oEmailRow->group_id, Components::$sAppSlug);
        } catch (FactoryException $e) {
            /** @var VerifyEmail $oEmail */
            $oEmail = Factory::factory('EmailVerifyEmail', Constants::MODULE_SLUG);
        }

        $oEmail
            ->to((int) $oEmailRow->user_id)
            ->data('verifyUrl', siteUrl('email/verify/' . $oEmailRow->user_id . '/' . $oEmailRow->code));

        try {

            $oEmail->send();

        } catch (\Exception $e) {
            $this->setError('The verification email failed to send.');
            return false;
        }

        return true;
    }

    // --------------------------------------------------------------------------

    /**
     * Deletes a non-primary email from the user email table, optionally filtering
     * by $iUserId
     *
     * @param int|string $mEmailId      The email address, or the ID of the email address to remove
     * @param int|null   $iUserId       The ID of the user to restrict to
     * @param bool       $bTriggerEvent Whether to trigger the user modified event
     *
     * @return bool
     * @throws FactoryException
     * @throws ModelException
     */
    public function emailDelete($mEmailId, int $iUserId = null, bool $bTriggerEvent = true)
    {
        /** @var Database $oDb */
        $oDb = Factory::service('Database');

        if (is_numeric($mEmailId)) {
            $oDb->where('id', $mEmailId);
        } else {
            $oDb->where('email', $mEmailId);
        }

        if (!empty($iUserId)) {
            $oDb->where('user_id', $iUserId);
        }

        $oDb->where('is_primary', false);
        $oRow = $oDb->get($this->oEmailModel->getTableName())->row();

        if (empty($oRow)) {
            $this->setError('"' . $mEmailId . '" is not a known email or ID');
            return false;
        }

        $oUser = $this->getById($oRow->user_id);

        $oDb->where('id', $oRow->id);
        $oDb->delete($this->oEmailModel->getTableName());

        if ((bool) $oDb->affected_rows()) {

            $this->unsetCacheUser($oUser->id);
            if ($bTriggerEvent) {
                $this->triggerEvent(
                    Events::USER_MODIFIED,
                    [$oUser->id, $oUser]
                );
            }

            return true;

        } else {
            return false;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Verifies whether the supplied $code is valid for the requested user ID or email
     * address. If it is then the email is marked as verified.
     *
     * @param int|string $mIdEmail      The numeric ID of the user, or the email address
     * @param string     $sCode         The verification code as generated by emailAdd()
     * @param bool       $bTriggerEvent Whether to trigger the user modified event
     *
     * @return bool
     * @throws FactoryException
     * @throws ModelException
     */
    public function emailVerify($mIdEmail, string $sCode, bool $bTriggerEvent = true)
    {
        //  Check user exists
        if (is_numeric($mIdEmail)) {
            $oUser = $this->getById($mIdEmail);
        } else {
            $oUser = $this->getByEmail($mIdEmail);
        }

        if (!$oUser) {
            $this->setError('User does not exist.');
            return false;
        }

        // --------------------------------------------------------------------------

        //  Check if email has already been verified
        /** @var Database $oDb */
        $oDb = Factory::service('Database');
        $oDb->where('user_id', $oUser->id);
        $oDb->where('is_verified', true);
        $oDb->where('code', $sCode);

        if ($oDb->count_all_results($this->oEmailModel->getTableName())) {
            $this->setError('Email has already been verified.');
            return false;
        }

        // --------------------------------------------------------------------------

        //  Go ahead and set as verified
        $oDb->set('is_verified', true);
        $oDb->set('date_verified', 'NOW()', false);
        $oDb->where('user_id', $oUser->id);
        $oDb->where('is_verified', false);
        $oDb->where('code', $sCode);

        $oDb->update($this->oEmailModel->getTableName());

        if ((bool) $oDb->affected_rows()) {

            $this->unsetCacheUser($oUser->id);

            //  Update the activeUser
            if ($oUser->id == $this->activeUser('id')) {

                /** @var \DateTime $oDate */
                $oDate                          = Factory::factory('DateTime');
                $this->oActiveUser->last_update = $oDate->format('Y-m-d H:i:s');

                //  @todo: update the rest of the activeUser
            }

            if ($bTriggerEvent) {
                $this->triggerEvent(
                    Events::USER_MODIFIED,
                    [$oUser->id, $oUser]
                );
            }

            return true;

        } else {
            return false;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Sets an email address as the primary email address for that user.
     *
     * @param int|string $mIdEmail      The numeric  ID of the email address, or the email address itself
     * @param int|Null   $iUserId       Specify the user ID which this should apply to
     * @param bool       $bTriggerEvent Whether to trigger the user modified event
     *
     * @return bool
     * @throws FactoryException
     * @throws ModelException
     */
    public function emailMakePrimary($mIdEmail, int $iUserId = null, bool $bTriggerEvent = true): bool
    {
        //  Fetch email
        /** @var Database $oDb */
        $oDb = Factory::service('Database');
        $oDb->select('id,user_id,email');

        if (is_numeric($mIdEmail)) {
            $oDb->where('id', $mIdEmail);
        } else {
            $oDb->where('email', $mIdEmail);
        }

        if (!is_null($iUserId)) {
            $oDb->where('user_id', $iUserId);
        }

        $oEmail = $oDb->get($this->oEmailModel->getTableName())->row();

        if (empty($oEmail)) {
            return false;
        }

        $oUser = $this->getById($oEmail->user_id);

        //  Update
        $oDb->transaction()->start();
        try {
            $oDb->set('is_primary', false);
            $oDb->where('user_id', $oEmail->user_id);
            $oDb->update($this->oEmailModel->getTableName());

            $oDb->set('is_primary', true);
            $oDb->where('id', $oEmail->id);
            $oDb->update($this->oEmailModel->getTableName());

            $this->unsetCacheUser($oEmail->user_id);

            //  Update the activeUser
            if ($oEmail->user_id == $this->activeUser('id')) {

                $oDate                          = Factory::factory('DateTime');
                $this->oActiveUser->last_update = $oDate->format('Y-m-d H:i:s');

                //  @todo: update the rest of the activeUser
            }

            $oDb->transaction()->commit();

            if ($bTriggerEvent) {
                $this->triggerEvent(
                    Events::USER_MODIFIED,
                    [$oUser->id, $oUser]
                );
            }

            return true;

        } catch (\Exception $e) {
            $this->setError('Failed to set primary email. ' . $e->getMessage());
            $oDb->transaction()->rollback();
            return false;
        }

    }

    // --------------------------------------------------------------------------

    /**
     * Increment the user's failed logins
     *
     * @param int $iUserId  The user ID to increment
     * @param int $iExpires How long till the block, if the threshold is reached, expires.
     *
     * @return bool
     * @throws FactoryException
     * @throws ModelException
     */
    public function incrementFailedLogin(int $iUserId, int $iExpires = 300): bool
    {
        /** @var \DateTime $oDate */
        $oDate = Factory::factory('DateTime');
        $oDate->add(new \DateInterval('PT' . $iExpires . 'S'));

        /** @var Database $oDb */
        $oDb = Factory::service('Database');
        $oDb->set('failed_login_count', '`failed_login_count`+1', false);
        $oDb->set('failed_login_expires', $oDate->format('Y-m-d H:i:s'));
        return $this->update($iUserId);
    }

    // --------------------------------------------------------------------------

    /**
     * Reset a user's failed login
     *
     * @param int $iUserId The user ID to reset
     *
     * @return bool
     * @throws FactoryException
     * @throws ModelException
     */
    public function resetFailedLogin(int $iUserId): bool
    {
        /** @var Database $oDb */
        $oDb = Factory::service('Database');
        $oDb->set('failed_login_count', 0);
        $oDb->set('failed_login_expires', 'null', false);
        return $this->update($iUserId);
    }

    // --------------------------------------------------------------------------

    /**
     * Update a user's `last_login` field
     *
     * @param int $iUserId The user ID to update
     *
     * @return bool
     * @throws FactoryException
     * @throws ModelException
     */
    public function updateLastLogin($iUserId)
    {
        /** @var Database $oDb */
        $oDb = Factory::service('Database');
        $oDb->set('last_login', 'NOW()', false);
        $oDb->set('login_count', 'login_count+1', false);
        return $this->update($iUserId);
    }

    // --------------------------------------------------------------------------

    /**
     * Set the user's 'rememberMe' cookie, nom nom nom
     *
     * @param int|null    $iId       The User's ID
     * @param string|null $sPassword The user's password, hashed
     * @param string|null $sEmail    The user's email\
     *
     * @return bool
     * @return bool
     * @throws FactoryException
     * @throws EnvironmentException
     */
    public function setRememberCookie($iId = null, $sPassword = null, $sEmail = null)
    {
        //  Is remember me functionality enabled?
        /** @var \Nails\Common\Service\Config $oConfig */
        $oConfig = Factory::service('Config');
        $oConfig->load('auth/auth');

        if (!$oConfig->item('authEnableRememberMe')) {
            return false;
        }

        // --------------------------------------------------------------------------

        if (empty($iId) || empty($sPassword) || empty($sEmail)) {

            if (!activeUser('id') || !activeUser('password') || !activeUser('email')) {

                return false;

            } else {
                $iId       = $this->activeUser('id');
                $sPassword = $this->activeUser('password');
                $sEmail    = $this->activeUser('email');
            }
        }

        // --------------------------------------------------------------------------

        //  Generate a code to remember the user by and save it to the DB
        /** @var Encrypt $oEncrypt */
        $oEncrypt = Factory::service('Encrypt');
        $sSalt    = $oEncrypt->encode(sha1($iId . $sPassword . $sEmail . Config::get('PRIVATE_KEY') . time()));

        /** @var Database $oDb */
        $oDb = Factory::service('Database');
        $oDb->set('remember_code', $sSalt);
        $oDb->where('id', $iId);
        $oDb->update($this->getTableName());

        // --------------------------------------------------------------------------

        //  Set the cookie
        set_cookie([
            'name'   => static::REMEMBER_ME_COOKIE,
            'value'  => $sEmail . '|' . $sSalt,
            'expire' => 1209600, //   2 weeks
        ]);

        // --------------------------------------------------------------------------

        $this->bIsRemembered = true;

        return true;
    }

    // --------------------------------------------------------------------------

    /**
     * Clear the active user's 'rememberMe' cookie
     *
     * @return void
     */
    public function clearRememberCookie()
    {
        delete_cookie(static::REMEMBER_ME_COOKIE);
        $this->bIsRemembered = false;
    }

    // --------------------------------------------------------------------------

    /**
     * Refresh the user's session from the database
     *
     * @return bool
     * @throws FactoryException
     * @throws ModelException
     * @throws NailsException
     * @throws ReflectionException
     */
    protected function refreshSession()
    {
        //  Get the user; be wary of admins logged in as other people
        /** @var Session $oSession */
        $oSession = Factory::service('Session');
        if ($this->wasAdmin()) {

            $recoveryData = $this->getAdminRecoveryData();

            if (!empty($recoveryData->newUserId)) {
                $me = $recoveryData->newUserId;
            } else {
                $me = $oSession->getUserData('id');
            }

        } else {
            $me = $oSession->getUserData('id');
        }

        //  Is anybody home? Hello...?
        if (!$me) {
            $me = $this->iActiveUserId;
            if (!$me) {
                return false;
            }
        }

        $me = $this->getById($me);

        // --------------------------------------------------------------------------

        /**
         * If the user is isn't found (perhaps deleted) or has been suspended then
         * obviously don't proceed with the log in
         */

        if (!$me || !empty($me->is_suspended)) {
            $this->clearRememberCookie();
            $this->clearActiveUser();
            $this->clearLoginData();
            $this->bIsLoggedIn = false;
            return false;
        }

        // --------------------------------------------------------------------------

        //  Store this entire user in memory
        $this->setActiveUser($me);

        // --------------------------------------------------------------------------

        //  Set the user's logged in flag
        $this->bIsLoggedIn = true;

        // --------------------------------------------------------------------------

        //  Update user's `last_seen` and `last_ip` properties
        if (!wasAdmin()) {
            /** @var Database $oDb */
            $oDb = Factory::service('Database');
            /** @var Input $oInput */
            $oInput = Factory::service('Input');

            $oDb->set('last_seen', 'NOW()', false);
            if (Config::get('NAILS_AUTH_LOG_IP', true)) {
                $oDb->set('last_ip', $oInput->ipAddress());
            }
            $oDb->where('id', $me->id);
            $oDb->update($this->getTableName());
        }

        return true;
    }

    // --------------------------------------------------------------------------

    /**
     * Create a new user
     *
     * @param array $data         An array of data to create the user with
     * @param bool  $bSendWelcome Whether to send the welcome email
     *
     * @return mixed                StdClass on success, false on failure
     * @throws FactoryException
     * @throws ModelException
     */
    public function create(array $data = [], $bSendWelcome = true)
    {
        /** @var \DateTime $oDate */
        $oDate = Factory::factory('DateTime');
        /** @var Database $oDb */
        $oDb = Factory::service('Database');
        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        /** @var Group $oUserGroupModel */
        $oUserGroupModel = Factory::model('UserGroup', Constants::MODULE_SLUG);
        /** @var Password $oUserPasswordModel */
        $oUserPasswordModel = Factory::model('UserPassword', Constants::MODULE_SLUG);

        //  Has an email or a username been submitted?
        if (Config::get('APP_NATIVE_LOGIN_USING') == 'EMAIL') {

            //  Email defined?
            if (empty($data['email'])) {
                $this->setError('An email address must be supplied.');
                return false;
            }

            //  Check email against DB
            $oDb->where('email', $data['email']);
            if ($oDb->count_all_results($this->oEmailModel->getTableName())) {
                $this->setError('This email is already in use.');
                return false;
            }

        } elseif (Config::get('APP_NATIVE_LOGIN_USING') == 'USERNAME') {

            //  Username defined?
            if (empty($data['username'])) {
                $this->setError('A username must be supplied.');
                return false;
            }

            if (!$this->isValidUsername($data['username'], true)) {
                return false;
            }

        } else {

            //  Both a username and an email must be supplied
            if (empty($data['email']) || empty($data['username'])) {
                $this->setError('An email address and a username must be supplied.');
                return false;
            }

            //  Check email against DB
            $oDb->where('email', $data['email']);
            if ($oDb->count_all_results($this->oEmailModel->getTableName())) {
                $this->setError('This email is already in use.');
                return false;
            }

            //  Check username
            if (!$this->isValidUsername($data['username'], true)) {
                return false;
            }
        }

        // --------------------------------------------------------------------------

        //  All should be ok, go ahead and create the account
        $aUserData = [];

        // --------------------------------------------------------------------------

        //  Check that we're dealing with a valid group
        if (empty($data['group_id'])) {
            $aUserData['group_id'] = $oUserGroupModel->getDefaultGroupId();
        } else {
            $aUserData['group_id'] = $data['group_id'];
        }

        $oGroup = $oUserGroupModel->getById($aUserData['group_id']);

        if (empty($oGroup)) {
            $this->setError('Invalid Group ID specified.');
            return false;
        } else {
            $aUserData['group_id'] = $oGroup->id;
        }

        // --------------------------------------------------------------------------

        /**
         * If a password has been passed then generate the encrypted strings, otherwise
         * have a null password - the user won't be able to login and will be informed
         * that they need to set a password using forgotten password.
         */

        try {

            if (empty($data['password'])) {
                $oPassword = $oUserPasswordModel->generateNullHash();
            } else {
                $oPassword = $oUserPasswordModel->generateHash($aUserData['group_id'], $data['password']);
            }

        } catch (NailsException $e) {
            $this->setError($e->getMessage());
            return false;
        }

        /**
         * Do we need to inform the user of their password? This might be set if an
         * admin created the account, or if the system generated a new password
         */

        $bInformUserPw = !empty($data['inform_user_pw']);

        // --------------------------------------------------------------------------

        if (!empty($data['username'])) {
            $aUserData['username'] = strtolower($data['username']);
        }

        if (!empty($data['email'])) {
            $sEmail           = $data['email'];
            $bEmailIsVerified = !empty($data['email_is_verified']);
        }

        $aUserData['password']        = $oPassword->password;
        $aUserData['password_md5']    = $oPassword->password_md5;
        $aUserData['password_engine'] = $oPassword->engine;
        $aUserData['salt']            = $oPassword->salt;
        $aUserData['created']         = $oDate->format('Y-m-d H:i:s');
        $aUserData['last_update']     = $oDate->format('Y-m-d H:i:s');
        $aUserData['is_suspended']    = !empty($data['is_suspended']);
        $aUserData['temp_pw']         = !empty($data['temp_pw']);

        if (Config::get('NAILS_AUTH_LOG_IP', true)) {
            $aUserData['ip_address'] = $oInput->ipAddress();
            $aUserData['last_ip']    = $oInput->ipAddress();
        }

        //  Referral code
        $aUserData['referral']    = $this->generateReferral();
        $aUserData['referred_by'] = !empty($data['referred_by']) ? $data['referred_by'] : null;

        //  Other data
        $aUserData['salutation'] = !empty($data['salutation']) ? $data['salutation'] : null;
        $aUserData['first_name'] = !empty($data['first_name']) ? $data['first_name'] : null;
        $aUserData['last_name']  = !empty($data['last_name']) ? $data['last_name'] : null;

        if (isset($data['gender'])) {
            $aUserData['gender'] = $data['gender'];
        }

        if (isset($data['timezone'])) {
            $aUserData['timezone'] = $data['timezone'];
        }

        if (isset($data['datetime_format_date'])) {
            $aUserData['datetime_format_date'] = $data['datetime_format_date'];
        }

        if (isset($data['datetime_format_time'])) {
            $aUserData['datetime_format_time'] = $data['datetime_format_time'];
        }

        if (isset($data['language'])) {
            $aUserData['language'] = $data['language'];
        }

        if (isset($data['profile_img'])) {
            $aUserData['profile_img'] = $data['profile_img'];
        }

        if (isset($data['dob'])) {
            $aUserData['dob'] = $data['dob'];
        }

        // --------------------------------------------------------------------------

        //  Set Meta data
        $aMetaCols = array_keys($this->describeMetaFields());
        $aMetaData = [];

        foreach ($data as $key => $val) {
            if (array_search($key, $aMetaCols) !== false) {
                $aMetaData[$key] = $val;
            }
        }

        // --------------------------------------------------------------------------

        try {

            $oDb->transaction()->start();

            $oDb->set($aUserData);

            if (!$oDb->insert($this->getTableName())) {
                throw new NailsException('Failed to create base user object.');
            }

            $iId = $oDb->insert_id();

            // --------------------------------------------------------------------------

            /**
             * Update the user table with an MD5 hash of the user ID; a number of functions
             * make use of looking up this hashed information; this should be quicker.
             */

            $oDb->set('id_md5', md5($iId));
            $oDb->where('id', $iId);

            if (!$oDb->update($this->getTableName())) {
                throw new NailsException('Failed to update base user object.');
            }

            // --------------------------------------------------------------------------

            //  Create the meta record, add any extra data if needed
            $oDb->set('user_id', $iId);

            if ($aMetaData) {
                $oDb->set($aMetaData);
            }

            if (!$oDb->insert($this->getMetaTableName())) {
                throw new NailsException('Failed to create user meta data object.');
            }

            // --------------------------------------------------------------------------

            //  Finally add the email address to the user email table
            if (!empty($sEmail)) {

                $sCode = $this->emailAdd($sEmail, $iId, true, !empty($bEmailIsVerified), false, false);

                if (!$sCode) {
                    //  Error will be set by emailAdd();
                    throw new NailsException($this->lastError());
                }

                //  Send the user the welcome email
                if ($bSendWelcome) {

                    try {
                        //  Allows the app to define a group specific welcome email
                        /** @var NewUser $oEmail */
                        $oEmail = Factory::factory('EmailNewUser' . $oGroup->id, Components::$sAppSlug);
                    } catch (FactoryException $e) {
                        /** @var NewUser $oEmail */
                        $oEmail = Factory::factory('EmailNewUser', Constants::MODULE_SLUG);
                    }

                    $oEmail->to($iId);

                    //  If this user is created by an admin then take note of that.
                    if ($this->isAdmin() && $this->activeUser('id') != $iId) {
                        $oEmail->data('admin', [
                            'id'         => $this->activeUser('id'),
                            'first_name' => $this->activeUser('first_name'),
                            'last_name'  => $this->activeUser('last_name'),
                            'group'      => (object) [
                                'id'   => $oGroup->id,
                                'name' => $oGroup->label,
                            ],
                        ]);
                    }

                    if (!empty($data['password']) && $bInformUserPw) {

                        $oEmail->data('password', $data['password']);

                        //  Is this a temp password? We should let them know that too
                        if ($aUserData['temp_pw']) {
                            $oEmail->data('isTemp', $data['temp_pw']);
                        }
                    }

                    try {

                        $oEmail->send();

                    } catch (\Exception $e) {
                        throw new NailsException(sprintf(
                            'Failed to send welcome email. %s',
                            $bInformUserPw
                                ? 'Inform the user their password is <strong>' . $data['password'] . '</strong>'
                                : ''
                        ));
                    }
                }
            }

            // --------------------------------------------------------------------------

            $oDb->transaction()->commit();

            $this->triggerEvent(
                Events::USER_CREATED,
                [$iId]
            );

            return $this->getById($iId);

        } catch (\Exception $e) {
            $oDb->transaction()->rollback();
            $this->setError($e->getMessage());
            return false;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Delete a user
     *
     * @param int $iUserId The ID of the user to delete
     *
     * @return bool
     * @throws FactoryException
     * @throws ModelException
     */
    public function destroy($iUserId): bool
    {
        /** @var Database $oDb */
        $oDb = Factory::service('Database');

        /**
         * Delete the meta table first as it is the most likely to have FK's on it
         * which might fail as part of the delete.
         */

        $oDb->where('user_id', $iUserId);
        $oDb->delete($this->getMetaTableName());

        if ((bool) $oDb->affected_rows()) {

            $oDb->where('id', $iUserId);
            $oDb->delete($this->getTableName());

            if ((bool) $oDb->affected_rows()) {
                $this->unsetCacheUser($iUserId);
                $this->triggerEvent(
                    Events::USER_DESTROYED,
                    [$iUserId]
                );
                return true;
            }
        }

        return false;
    }

    // --------------------------------------------------------------------------

    /**
     * Alias to destroy()
     *
     * @param int $iUserId The ID of the user to delete
     *
     * @return bool
     * @throws FactoryException
     * @throws ModelException
     */
    public function delete($iUserId): bool
    {
        $this->triggerEvent(
            Events::USER_DELETED,
            [$iUserId]
        );
        return $this->destroy($iUserId);
    }

    // --------------------------------------------------------------------------

    /**
     * Generates a valid referral code
     *
     * @return string
     * @throws FactoryException
     */
    protected function generateReferral()
    {
        Factory::helper('string');
        /** @var Database $oDb */
        $oDb       = Factory::service('Database');
        $sReferral = '';

        while (1 > 0) {

            $sReferral = random_string('alnum', 8);
            $oQuery    = $oDb->get_where($this->getTableName(), ['referral' => $sReferral]);

            if ($oQuery->num_rows() == 0) {
                break;
            }
        }

        return $sReferral;
    }

    // --------------------------------------------------------------------------

    /**
     * Rewards a user for their referral
     *
     * @param int $iUserId     The ID of the user who signed up
     * @param int $iReferrerId The ID of the user who made the referral
     *
     * @return void
     */
    public function rewardReferral($iUserId, $iReferrerId)
    {
        // @todo Implement this method where appropriate
    }

    // --------------------------------------------------------------------------

    /**
     * Suspend a user
     *
     * @param int $iUserId The ID of the user to suspend
     *
     * @return bool
     * @throws FactoryException
     * @throws ModelException
     */
    public function suspend($iUserId)
    {
        return $this->update($iUserId, ['is_suspended' => true]);
    }

    // --------------------------------------------------------------------------

    /**
     * Unsuspend a user
     *
     * @param int $iUserId The ID of the user to unsuspend
     *
     * @return bool
     * @throws FactoryException
     * @throws ModelException
     */
    public function unsuspend($iUserId)
    {
        return $this->update($iUserId, ['is_suspended' => false]);
    }

    // --------------------------------------------------------------------------

    /**
     * Checks whether a username is valid
     *
     * @param string   $sUsername     The username to check
     * @param bool     $bCheckDb      Whether to test against the database
     * @param int|null $iIgnoreUserId The ID of a user to ignore when checking the database
     *
     * @return bool
     * @throws FactoryException
     */
    public function isValidUsername($sUsername, $bCheckDb = false, $iIgnoreUserId = null): bool
    {
        /**
         * Check username doesn't contain invalid characters - we're actively looking
         * for characters which are invalid so we can say "Hey! The following
         * characters are invalid" rather than making the user guess, y'know, 'cause
         * we're good guys.
         */

        $sInvalidChars = '/[^a-zA-Z0-9\-_\.]/';

        //  Minimum length of the username
        $iMinLength = 2;

        // --------------------------------------------------------------------------

        if (preg_match($sInvalidChars, $sUsername)) {

            $this->setError(
                'Username can only contain alpha numeric characters, underscores, periods and dashes (no spaces).'
            );
            return false;

        } elseif (strlen($sUsername) < $iMinLength) {

            $this->setError(
                'Usernames must be at least ' . $iMinLength . ' characters long.'
            );
            return false;
        }

        // --------------------------------------------------------------------------

        if ($bCheckDb) {

            /** @var Database $oDb */
            $oDb = Factory::service('Database');
            $oDb->where('username', $sUsername);

            if (!empty($iIgnoreUserId)) {
                $oDb->where('id !=', $iIgnoreUserId);
            }

            if ($oDb->count_all_results($this->getTableName())) {
                $this->setError('Username is already in use.');
                return false;
            }
        }

        return true;
    }

    // --------------------------------------------------------------------------

    /**
     * Merges items into an original
     *
     * @param int   $iKeepId   The ID of the item to keep
     * @param int[] $aMergeIds An array of IDs to merge into $iKeepId
     *
     * @return $this
     * @throws \InvalidArgumentException
     * @throws \Nails\Auth\Exception\User\MergeException
     * @throws \Nails\Common\Exception\FactoryException
     */
    public function merge(int $iKeepId, array $aMergeIds): self
    {
        for ($i = 0; $i < count($aMergeIds); $i++) {
            if (!is_numeric($aMergeIds[$i])) {
                throw new \InvalidArgumentException(
                    '"$aMergeIds" must contain only numerical values.'
                );
            }
        }

        if (in_array($iKeepId, $aMergeIds)) {
            throw new \InvalidArgumentException(sprintf(
                'Target user "%s" cannot be merged into itself.',
                $iKeepId
            ));
        }

        // --------------------------------------------------------------------------

        /** @var Database $oDb */
        $oDb = Factory::service('Database');

        try {

            $this->triggerEvent(
                Events::USER_MERGE_PRE,
                [$iKeepId, $aMergeIds]
            );

            $oDb->transaction()->start();

            $this->mergeUpdateColumns(
                $this->mergeCompileMap(),
                $iKeepId,
                $aMergeIds
            );

            $this->triggerEvent(
                Events::USER_MERGE_POST,
                [$iKeepId, $aMergeIds]
            );

            $this->mergeDeleteMergedUsers($aMergeIds);

            $this->triggerEvent(
                Events::USER_MERGE_COMPLETE,
                [$iKeepId, $aMergeIds]
            );

            $oDb->transaction()->commit();

        } catch (\Throwable $e) {
            $oDb->transaction()->rollback();
            throw new MergeException(
                $e->getMessage(),
                $e->getCode(),
                $e,
            );
        }

        return $this;
    }

    // --------------------------------------------------------------------------

    /**
     * Leverage foreign keys to determine which columns should be re-mapped.
     * Anything pointing to nails_user.id needs updated to point to $iKeepUser.
     * We'll want to exclude certain tables which contain sensitive data or
     * personal preferences and shouldn't be merged.
     *
     * @return array
     * @throws \Nails\Common\Exception\FactoryException
     * @throws \Nails\Common\Exception\ModelException
     */
    protected function mergeCompileMap(): array
    {
        /** @var Database $oDb */
        $oDb = Factory::service('Database');

        $aMap     = [];
        $aExclude = [
            $this->getMetaTableName(),
            Factory::model('UserAccessToken', Constants::MODULE_SLUG)->getTableName(),
            Factory::model('UserEvent', Constants::MODULE_SLUG)->getTableName(),
            Factory::service('SocialSignOn', Constants::MODULE_SLUG)::TABLE,
            Factory::service('Authentication', Constants::MODULE_SLUG)::TABLE_TWO_FACTOR_DEVICE_SECRET,
            Factory::service('Authentication', Constants::MODULE_SLUG)::TABLE_TWO_FACTOR_QUESTION,
            Factory::service('Authentication', Constants::MODULE_SLUG)::TABLE_TWO_FACTOR_TOKEN,
        ];

        $aResult = $oDb
            ->select(['TABLE_NAME', 'COLUMN_NAME'])
            ->from('INFORMATION_SCHEMA.KEY_COLUMN_USAGE')
            ->where('REFERENCED_TABLE_SCHEMA', $oDb->getDbDatabase())
            ->where('REFERENCED_TABLE_NAME', $this->getTableName())
            ->where('REFERENCED_COLUMN_NAME', $this->getColumnId())
            ->where_not_in('TABLE_NAME', $aExclude)
            ->get()
            ->result();

        foreach ($aResult as $oRow) {

            if (!array_key_exists($oRow->TABLE_NAME, $aMap)) {
                $aMap[$oRow->TABLE_NAME] = [];
            }

            $aMap[$oRow->TABLE_NAME][] = $oRow->COLUMN_NAME;
        }

        return $aMap;
    }

    // --------------------------------------------------------------------------

    /**
     * Update merge columns
     *
     * @param array $aMap
     *
     * @return $this
     * @throws \Nails\Auth\Exception\User\MergeException
     * @throws \Nails\Common\Exception\FactoryException
     * @throws \Nails\Common\Exception\ModelException
     */
    protected function mergeUpdateColumns(array $aMap, int $iKeepId, array $aMergeIds): self
    {
        /** @var Database $oDb */
        $oDb = Factory::service('Database');

        foreach ($aMap as $sTable => $aColumns) {

            foreach ($aColumns as $sColumn) {
                $oDb->set($sColumn, $iKeepId);
            }

            //  Catch the email table, additional logic required
            if ($sTable === $this->oEmailModel->getTableName()) {
                $oDb->set('is_primary', false);
            }

            $oDb->where_in($sColumn, $aMergeIds);

            if (!$oDb->update($sTable)) {
                throw new MergeException(sprintf(
                    'Failed to migrate column "%s" in table "%s"',
                    $sColumn,
                    $sTable
                ));
            }
        }

        return $this;
    }

    // --------------------------------------------------------------------------

    /**
     * Deletes merged users
     *
     * @return $this
     * @throws \Nails\Auth\Exception\User\MergeException
     * @throws \Nails\Common\Exception\FactoryException
     * @throws \Nails\Common\Exception\ModelException
     */
    protected function mergeDeleteMergedUsers(array $aMergeIds): self
    {
        foreach ($aMergeIds as $iMergeId) {
            if (!$this->destroy($iMergeId)) {
                throw new MergeException(sprintf(
                    'Failed to delete user "%s"',
                    $iMergeId,
                ));
            }
        }

        return $this;
    }

    // --------------------------------------------------------------------------

    /**
     * Describe the model's fields
     *
     * @param string|null $sTable
     *
     * @return Field[]
     * @throws FactoryException
     */
    public function describeFields($sTable = null)
    {
        $aFields = parent::describeFields($sTable);

        //  Data types
        $aFields['profile_img']->type          = 'cdn_object_picker';
        $aFields['timezone']->type             = 'dropdown';
        $aFields['datetime_format_date']->type = 'dropdown';
        $aFields['datetime_format_time']->type = 'dropdown';

        //  Labels
        $aFields['first_name']->label           = 'First Name';
        $aFields['last_name']->label            = 'Surname';
        $aFields['profile_img']->label          = 'Profile Image';
        $aFields['dob']->label                  = 'Date of Birth';
        $aFields['datetime_format_date']->label = 'Date Format';
        $aFields['datetime_format_time']->label = 'Time Format';
        $aFields['ip_address']->label           = 'Registration IP';
        $aFields['last_ip']->label              = 'Last IP';
        $aFields['last_update']->label          = 'Modified';
        $aFields['referral']->label             = 'Referral Code';

        //  Validation rules
        $aRules = [
            'required' => [
                'first_name',
                'last_name',
            ],
        ];

        foreach ($aRules as $sRule => $aProperties) {
            foreach ($aProperties as $sProperty) {
                $aFields[$sProperty]->validation[] = $sRule;
            }
        }

        //  Notes
        $aFields['username']->info = 'Username can only contain alpha numeric characters, underscores, periods and dashes (no spaces).';

        //  Dropdown values
        /** @var DateTime $oDateTimeService */
        $oDateTimeService                         = Factory::service('DateTime');
        $aFields['timezone']->options             = $oDateTimeService->getAllTimezoneFlat();
        $aFields['datetime_format_date']->options = $oDateTimeService->getAllDateFormatFlat();
        $aFields['datetime_format_time']->options = $oDateTimeService->getAllTimeFormatFlat();

        //  Dropdown validation
        $aFields['timezone']->validation[]             = 'in_list[' . implode(',', array_keys($aFields['timezone']->options)) . ']';
        $aFields['datetime_format_date']->validation[] = 'in_list[' . implode(',', array_keys($aFields['datetime_format_date']->options)) . ']';
        $aFields['datetime_format_time']->validation[] = 'in_list[' . implode(',', array_keys($aFields['datetime_format_time']->options)) . ']';

        //  Defaults
        $aFields['timezone']->default             = $oDateTimeService->getTimezoneDefault();
        $aFields['datetime_format_date']->default = $oDateTimeService->getDateFormatDefaultSlug();
        $aFields['datetime_format_time']->default = $oDateTimeService->gettimeFormatDefaultSlug();

        //  Misc
        $aFields['timezone']->class             = 'select2';
        $aFields['datetime_format_date']->class = 'select2';
        $aFields['datetime_format_time']->class = 'select2';

        return $aFields;
    }

    // --------------------------------------------------------------------------

    /**
     * Describes the meta fields of the user object
     *
     * @return array
     */
    public function describeMetaFields(): array
    {
        $aFields = parent::describeFields($this->getMetaTableName());
        unset($aFields['user_id']);
        return $aFields;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the name of the user meta table
     *
     * @return string
     */
    public function getMetaTableName($bIncludeAlias = false): string
    {
        return $bIncludeAlias
            ? trim(static::TABLE_META . ' as `' . $this->getMetaTableAlias() . '`')
            : static::TABLE_META;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the meta table alias
     *
     * @param bool $bIncludeSeparator Whether to include the separator
     *
     * @return
     */
    public function getMetaTableAlias($bIncludeSeparator = false)
    {
        $sTable = strtolower($this->getMetaTableName());
        $sTable = preg_replace('/[^a-z_]/', '', $sTable);
        $sTable = preg_replace('/_/', ' ', $sTable);
        $aTable = explode(' ', $sTable);

        $sOut = '';
        foreach ($aTable as $sWord) {
            $sOut .= $sWord[0];
        }

        return !empty($sOut) && $bIncludeSeparator
            ? $sOut . '.'
            : $sOut;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the name of the user email table
     *
     * @return string
     * @throws ModelException
     * @deprecated
     */
    public function getEmailTableName(): string
    {
        return $this->oEmailModel->getTableName();
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the name of the user group table
     *
     * @return string
     * @throws ModelException
     * @deprecated
     */
    public function getGroupTableName(): string
    {
        return $this->oGroupModel->getTableName();
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the supported genders
     *
     * @return string[]
     */
    public function getGenders(): array
    {
        return [
            static::GENDER_UNDISCLOSED => 'Undisclosed',
            static::GENDER_MALE        => 'Male',
            static::GENDER_FEMALE      => 'Female',
            static::GENDER_TRANSGENDER => 'Transgender',
            static::GENDER_OTHER       => 'Other',
        ];
    }

    // --------------------------------------------------------------------------

    /**
     * Formats a single object
     *
     * The getAll() method iterates over each returned item with this method so as to
     * correctly format the output. Use this to cast ints and bools and/or organise data into objects.
     *
     * @param object $oObj      A reference to the object being formatted.
     * @param array  $aData     The same data array which is passed to getCountCommon(), for reference if needed
     * @param array  $aIntegers Fields which should be cast as ints if numerical and not null
     * @param array  $aBools    Fields which should be cast as bools if not null
     * @param array  $aFloats   Fields which should be cast as floats if not null
     *
     * @return void
     */
    protected function formatObject(
        &$oObj,
        array $aData = [],
        array $aIntegers = [],
        array $aBools = [],
        array $aFloats = []
    ) {

        parent::formatObject($oObj, $aData, $aIntegers, $aBools, $aFloats);

        // --------------------------------------------------------------------------

        $oObj->group_acl = json_decode((string) $oObj->group_acl);

        //  If the user has an ACL set then we'll need to extract and merge that
        if ($oObj->user_acl) {

            $oObj->user_acl = json_decode($oObj->user_acl);
            $oObj->acl      = array_merge($oObj->group_acl, $oObj->user_acl);
            $oObj->acl      = array_filter($oObj->acl);
            $oObj->acl      = array_unique($oObj->acl);

        } else {
            $oObj->acl = $oObj->group_acl;
        }

        // --------------------------------------------------------------------------

        //  Bools
        $oObj->email_is_verified = (bool) $oObj->email_is_verified;

        // --------------------------------------------------------------------------

        //  Tidy User meta
        unset($oObj->user_id);
    }
}

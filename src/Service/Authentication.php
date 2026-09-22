<?php

/**
 * This class provides authentication functionality
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    service
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Auth\Service;

use DateTime;
use Nails\Auth\Constants;
use Nails\Auth\Exception\Login\InvalidCredentialsException;
use Nails\Auth\Exception\Login\IsLockedOutException;
use Nails\Auth\Exception\Login\IsSuspendedException;
use Nails\Auth\Exception\Login\NoUserException;
use Nails\Auth\Exception\Login\RequiresPasswordResetExpiredException;
use Nails\Auth\Exception\Login\RequiresPasswordResetTempException;
use Nails\Auth\Exception\Login\NoPasswordException;
use Nails\Auth\Exception\Passkey\PasskeyException;
use Nails\Auth\Model\User\Password;
use Nails\Auth\Resource;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\ModelException;
use Nails\Common\Exception\NailsException;
use Nails\Common\Service\Database;
use Nails\Common\Service\Session;
use Nails\Common\Traits\ErrorHandling;
use Nails\Environment;
use Nails\Factory;
use ReflectionException;
use stdClass;

/**
 * Class Authentication
 *
 * @package Nails\Auth\Model
 */
class Authentication
{
    use ErrorHandling;

    // --------------------------------------------------------------------------

    /**
     * The minimum length of time to wait between attempts, in microseconds
     *
     * @var int
     */
    const BRUTE_FORCE_DELAY = 500000;

    /**
     * The number of failed attempts before lockout occurs
     *
     * @var int
     */
    const LOCKOUT_THRESHOLD = 5;

    /**
     * How long the lockout should last, in seconds
     *
     * @var int
     */
    const LOCKOUT_DURATION = 300;

    /**
     * The session key recording how the current session authenticated
     *
     * @var string
     */
    const SESSION_KEY_LOGIN_METHOD = 'auth-login-method';

    /**
     * Login methods reported by the above signal
     *
     * @var string
     */
    const LOGIN_METHOD_PASSWORD = 'password';
    const LOGIN_METHOD_PASSKEY  = 'passkey';

    // --------------------------------------------------------------------------

    /**
     * Logs a user in
     *
     * @param Resource\User|string|int $oUser The user's Resource, ID, or identifier
     *
     * @return Resource\User
     * @throws FactoryException
     * @throws ModelException
     * @throws NailsException
     * @throws ReflectionException
     */
    public function login($oUser): Resource\User
    {
        $oUser = $this->getUser($oUser);

        //  @todo (Pablo - 2020-01-10) - Move logic from user model to this method
        //  @todo (Pablo - 2020-01-10) - Move logic from login controller to this method?

        /** @var \Nails\Auth\Model\User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);
        $oUserModel->setLoginData($oUser);

        return $oUser;
    }

    // --------------------------------------------------------------------------

    /**
     * Log a user in
     *
     * @param Resource\User|string|int $oUser     The user's Resource, ID, or identifier
     * @param string                   $sPassword The user's password
     * @param bool                     $bRemember Whether to 'remember' the user or not
     *
     * @return Resource\User
     * @throws FactoryException
     * @throws InvalidCredentialsException
     * @throws IsLockedOutException
     * @throws IsSuspendedException
     * @throws ModelException
     * @throws NailsException
     * @throws NoUserException
     * @throws ReflectionException
     * @throws RequiresPasswordResetExpiredException
     * @throws RequiresPasswordResetTempException
     * @throws NoPasswordException
     */
    public function loginWithCredentials(
        $oUser,
        string $sPassword,
        bool $bRemember = false
    ): Resource\User {

        //  Delay execution for a moment (reduces brute force efficiently)
        if (Environment::not(Environment::ENV_DEV)) {
            usleep(static::BRUTE_FORCE_DELAY);
        }

        // --------------------------------------------------------------------------

        /** @var \Nails\Auth\Model\User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);
        /** @var Password $oUserPasswordModel */
        $oUserPasswordModel = Factory::model('UserPassword', Constants::MODULE_SLUG);

        $oUser = $this->getUser($oUser);

        if (empty($oUser)) {

            throw new NoUserException(lang('auth_login_fail_general'));

        } elseif (is_null($oUser->password)) {

            $oUserModel->incrementFailedLogin($oUser->id, static::LOCKOUT_DURATION);
            $this->logLoginFailure($oUser, 'no_password');

            switch (\Nails\Config::get('APP_NATIVE_LOGIN_USING')) {

                case 'USERNAME':
                    $sIdentifier = $oUser->username;
                    break;

                case 'EMAIL':
                default:
                    $sIdentifier = $oUser->email;
                    break;
            }

            throw new NoPasswordException(
                lang('auth_login_fail_no_password', siteUrl('auth/password/forgotten?identifier=' . $sIdentifier))
            );

        } elseif ($this->isLockedOut($oUser)) {

            $oUserModel->incrementFailedLogin($oUser->id, static::LOCKOUT_DURATION);
            $this->logLoginFailure($oUser, 'brute_force_block_in_affect');

            throw new IsLockedOutException(
                lang('auth_login_fail_blocked', ceil(static::LOCKOUT_DURATION / 60))
            );

        } elseif ($this->isSuspended($oUser)) {

            $oUserModel->incrementFailedLogin($oUser->id, static::LOCKOUT_DURATION);
            $this->logLoginFailure($oUser, 'suspended');

            throw new IsSuspendedException(
                lang('auth_login_fail_suspended')
            );

        } elseif (!$oUserPasswordModel->isCorrect($oUser, $sPassword)) {

            $oUserModel->incrementFailedLogin($oUser->id, static::LOCKOUT_DURATION);
            $this->logLoginFailure($oUser, 'password_incorrect');

            $iTimeSinceChanged = $oUserPasswordModel->timeSinceChange($oUser);
            $iTimeChanged      = time() - $iTimeSinceChanged;
            $iTimeTwoWeeksAgo  = strtotime('-2 weeks');

            if ($iTimeSinceChanged !== null && $iTimeChanged > $iTimeTwoWeeksAgo) {
                throw new InvalidCredentialsException(
                    lang('auth_login_fail_general_recent', niceTime($iTimeChanged))
                );
            } else {
                throw new InvalidCredentialsException(lang('auth_login_fail_general'));
            }
        }

        //  Successful login means we can forget about failures
        $oUserModel->resetFailedLogin($oUser->id);

        //  Check if password needs changed
        if ($oUserPasswordModel->isTemporary($oUser)) {
            throw new RequiresPasswordResetTempException();
        } elseif ($oUserPasswordModel->isExpired($oUser->id)) {
            throw new RequiresPasswordResetExpiredException();
        }

        //  Set the remember me cookie
        if ($bRemember) {
            $oUserModel->setRememberCookie($oUser->id, $oUser->password, $oUser->email);
        }

        /**
         * Must be recorded before setLoginData(), which fires USER_LOG_IN synchronously;
         * listeners on that event read this signal.
         */
        $this->recordLoginMethod(static::LOGIN_METHOD_PASSWORD, (int) $oUser->id);

        $oUserModel->setLoginData($oUser->id);
        $oUserModel->updateLastLogin($oUser->id);

        return $oUser;
    }

    // --------------------------------------------------------------------------

    /**
     * Log a user in using a passkey
     *
     * Mirrors loginWithCredentials(), less the password: the temporary and expired
     * password checks are bypassed because no password took part in this login (J5).
     *
     * @param array<string, mixed> $aAssertion The PublicKeyCredential.toJSON() payload
     * @param string               $sChallenge The challenge the assertion answers
     * @param bool                 $bRemember  Whether to 'remember' the user or not
     *
     * @throws FactoryException
     * @throws InvalidCredentialsException
     * @throws IsLockedOutException
     * @throws IsSuspendedException
     * @throws ModelException
     * @throws NailsException
     * @throws NoUserException
     * @throws ReflectionException
     */
    public function loginWithPasskey(
        array $aAssertion,
        string $sChallenge,
        bool $bRemember = false
    ): Resource\User {

        //  Delay execution for a moment (reduces brute force efficiently)
        if (Environment::not(Environment::ENV_DEV)) {
            usleep(static::BRUTE_FORCE_DELAY);
        }

        // --------------------------------------------------------------------------

        /** @var \Nails\Auth\Model\User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);
        /** @var Passkey $oPasskeyService */
        $oPasskeyService = Factory::service('Passkey', Constants::MODULE_SLUG);

        $oPasskey = $oPasskeyService->findByAssertion($aAssertion);
        $oUser    = $oPasskey ? $oPasskey->user() : null;

        /**
         * An unrecognised credential cannot be attributed to a user, so there is nobody
         * to rate limit; it gets the delay above and the same message as every other
         * failure (J6).
         */
        if (empty($oUser)) {
            throw new NoUserException(lang('auth_login_fail_general'));

        } elseif ($this->isLockedOut($oUser)) {

            $oUserModel->incrementFailedLogin($oUser->id, static::LOCKOUT_DURATION);
            $this->logLoginFailure($oUser, 'brute_force_block_in_affect');

            throw new IsLockedOutException(
                lang('auth_login_fail_blocked', ceil(static::LOCKOUT_DURATION / 60))
            );

        } elseif ($this->isSuspended($oUser)) {

            $oUserModel->incrementFailedLogin($oUser->id, static::LOCKOUT_DURATION);
            $this->logLoginFailure($oUser, 'suspended');

            throw new IsSuspendedException(
                lang('auth_login_fail_suspended')
            );
        }

        try {

            $oPasskeyService->completeAuthentication($aAssertion, $sChallenge, $oUser, true);

        } catch (PasskeyException $e) {

            $oUserModel->incrementFailedLogin($oUser->id, static::LOCKOUT_DURATION);
            $this->logLoginFailure($oUser, 'passkey_invalid');

            throw new InvalidCredentialsException(lang('auth_login_fail_general'));
        }

        //  Successful login means we can forget about failures
        $oUserModel->resetFailedLogin($oUser->id);

        /**
         * The assertion above required user verification, so this login satisfies an
         * MFA challenge; see the MFA module's requiresAuthentication().
         */
        $this->recordLoginMethod(static::LOGIN_METHOD_PASSKEY, (int) $oUser->id, true);

        //  Note: a no-op for users without a password, as it always has been (J10)
        if ($bRemember) {
            $oUserModel->setRememberCookie($oUser->id, $oUser->password, $oUser->email);
        }

        $oUserModel->setLoginData($oUser->id);
        $oUserModel->updateLastLogin($oUser->id);

        return $oUser;
    }

    // --------------------------------------------------------------------------

    /**
     * Records how the current session authenticated
     *
     * Must be called before setLoginData() so that USER_LOG_IN listeners can read it.
     *
     * @throws FactoryException
     */
    public function recordLoginMethod(string $sMethod, int $iUserId, bool $bUserVerified = false): void
    {
        /** @var Session $oSession */
        $oSession = Factory::service('Session');
        $oSession->setUserData(static::SESSION_KEY_LOGIN_METHOD, (object) [
            'method'        => $sMethod,
            'user_id'       => $iUserId,
            'user_verified' => $bUserVerified,
            'at'            => time(),
        ]);
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the signal describing how the current session authenticated, if any
     *
     * @throws FactoryException
     */
    public function getLoginMethod(): ?stdClass
    {
        /** @var Session $oSession */
        $oSession = Factory::service('Session');

        $mSignal = $oSession->getUserData(static::SESSION_KEY_LOGIN_METHOD);

        return is_object($mSignal) ? (object) $mSignal : null;
    }

    // --------------------------------------------------------------------------

    /**
     * Forgets how the current session authenticated
     *
     * @throws FactoryException
     */
    public function clearLoginMethod(): void
    {
        /** @var Session $oSession */
        $oSession = Factory::service('Session');
        $oSession->unsetUserData(static::SESSION_KEY_LOGIN_METHOD);
    }

    // --------------------------------------------------------------------------

    /**
     * Whether the current session authenticated with a user-verified credential
     *
     * @throws FactoryException
     */
    public function isLoginUserVerified(): bool
    {
        $oSignal = $this->getLoginMethod();

        return !empty($oSignal->user_verified);
    }

    // --------------------------------------------------------------------------

    /**
     * Determines whether a user is currently locked out
     *
     * @param Resource\User|string|int $mUser The user's Resource, ID, or identifier
     *
     * @return bool
     * @throws FactoryException
     * @throws ModelException
     */
    public function isLockedOut($mUser): bool
    {
        $oUser = $this->getUser($mUser);

        if (empty($oUser)) {
            return false;
        }

        /** @var DateTime $oNow */
        $oNow     = Factory::factory('DateTime');
        $oExpires = new DateTime($oUser->failed_login_expires ?? '');

        return $oUser->failed_login_count >= static::LOCKOUT_THRESHOLD && $oNow < $oExpires;
    }

    // --------------------------------------------------------------------------

    /**
     * Determines whether a user is currently suspended
     *
     * @param Resource\User|string|int $mUser The user's Resource, ID, or identifier
     *
     * @return bool
     * @throws FactoryException
     * @throws ModelException
     */
    public function isSuspended($mUser): bool
    {
        $oUser = $this->getUser($mUser);

        if (empty($oUser)) {
            return false;
        }

        return $oUser->is_suspended;
    }

    // --------------------------------------------------------------------------

    /**
     * Logs a login failure
     *
     * @param Resource\User $oUser   The user to log against
     * @param string        $sReason The reason for failure
     */
    protected function logLoginFailure(Resource\User $oUser, string $sReason): void
    {
        createUserEvent(
            'did_login_fail',
            ['reason' => $sReason],
            null,
            $oUser->id
        );
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the user resource
     *
     * @param Resource\User|int|string $mUser The user's Resource, ID, or identifier
     *
     * @return Resource\User|null
     * @throws FactoryException
     * @throws ModelException
     */
    protected function getUser($mUser): ?Resource\User
    {
        /** @var \Nails\Auth\Model\User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);

        if ($mUser instanceof Resource\User) {
            return $mUser;
        } elseif (is_numeric($mUser)) {
            return $oUserModel->getById($mUser);
        } elseif (is_string($mUser)) {
            return $oUserModel->getByIdentifier($mUser);
        }

        return null;
    }

    // --------------------------------------------------------------------------

    /**
     * Log a user out
     *
     * @return bool
     * @throws FactoryException
     * @throws NailsException
     * @throws ReflectionException
     */
    public function logout()
    {
        /** @var \Nails\Auth\Model\User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);
        $oUserModel->clearRememberCookie();

        // --------------------------------------------------------------------------

        //  null the remember_code so that auto-login stops
        /** @var Database $oDb */
        $oDb = Factory::service('Database');
        $oDb->set('remember_code', null);
        $oDb->where('id', activeUser('id'));
        $oDb->update(\Nails\Config::get('NAILS_DB_PREFIX') . 'user');

        // --------------------------------------------------------------------------

        //  Destroy key parts of the session (enough for user_model to report user as logged out)
        $oUserModel->clearLoginData();

        // --------------------------------------------------------------------------

        //  Destroy CI session
        /** @var \Nails\Common\Service\Session $oSession */
        $oSession = Factory::service('Session');
        $oSession->destroy();

        // --------------------------------------------------------------------------

        //  Destroy PHP session if it exists
        if (session_id()) {
            session_destroy();
        }

        // --------------------------------------------------------------------------

        return true;
    }
}

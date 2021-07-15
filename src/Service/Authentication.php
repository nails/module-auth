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

use App\Auth\Model\User;
use DateInterval;
use DateTime;
use Google\Authenticator\GoogleAuthenticator;
use Nails\Auth\Constants;
use Nails\Auth\Exception\AuthException;
use Nails\Auth\Exception\Login\InvalidCredentialsException;
use Nails\Auth\Exception\Login\IsLockedOutException;
use Nails\Auth\Exception\Login\IsSuspendedException;
use Nails\Auth\Exception\Login\NoUserException;
use Nails\Auth\Exception\Login\RequiresMfaException;
use Nails\Auth\Exception\Login\RequiresPasswordResetExpiredException;
use Nails\Auth\Exception\Login\RequiresPasswordResetTempException;
use Nails\Auth\Exception\Login\RequiresSocialException;
use Nails\Auth\Model\User\Password;
use Nails\Auth\Resource;
use Nails\Common\Exception\Encrypt\DecodeException;
use Nails\Common\Exception\EnvironmentException;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\ModelException;
use Nails\Common\Exception\NailsException;
use Nails\Common\Helper\Url;
use Nails\Common\Model\Base;
use Nails\Common\Service\Config;
use Nails\Common\Service\Database;
use Nails\Common\Service\Encrypt;
use Nails\Common\Service\Input;
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

    const TABLE_TWO_FACTOR_DEVICE_SECRET = NAILS_DB_PREFIX . 'user_auth_two_factor_device_secret';
    const TABLE_TWO_FACTOR_QUESTION      = NAILS_DB_PREFIX . 'user_auth_two_factor_question';
    const TABLE_TWO_FACTOR_TOKEN         = NAILS_DB_PREFIX . 'user_auth_two_factor_token';

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
     * @param bool                     $bMfaCheck
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
     * @throws RequiresMfaException
     * @throws RequiresPasswordResetExpiredException
     * @throws RequiresPasswordResetTempException
     * @throws RequiresSocialException
     */
    public function loginWithCredentials(
        $oUser,
        string $sPassword,
        bool $bRemember = false,
        bool $bMfaCheck = true
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

            throw new RequiresSocialException(
                lang('auth_login_fail_social', siteUrl('auth/password/forgotten?identifier=' . $sIdentifier))
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

        //  Check if MFA is required
        //  @todo (Pablo - 2019-12-10) - This should consider trusted devices
        //  @todo (Pablo - 2019-12-10) - This should consider time since last checked (i.e a passed MFA is valid for a short valid and won't be asked for again)

        /** @var Config $oConfig */
        $oConfig = Factory::service('Config');
        if ($bMfaCheck && !empty($oConfig->item('authTwoFactorMode'))) {
            throw new RequiresMfaException();
        }

        //  Check if password needs changed
        if ($oUserPasswordModel->isTemporary($oUser)) {
            throw new RequiresPasswordResetTempException();
        } elseif ($oUserPasswordModel->isExpired($oUser->id)) {
            throw new RequiresPasswordResetExpiredException();
        }

        //  Set the remember me cookie
        //  @todo (Pablo - 2019-12-10) - Check this is respected as part of 2FA
        if ($bRemember) {
            $oUserModel->setRememberCookie($oUser->id, $oUser->password, $oUser->email);
        }

        $oUserModel->setLoginData($oUser->id);
        $oUserModel->updateLastLogin($oUser->id);

        return $oUser;
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
        $oExpires = new DateTime($oUser->failed_login_expires);

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

    // --------------------------------------------------------------------------

    /**
     * Generate an MFA token
     *
     * @param int $iUserId The user ID to generate the token for
     *
     * @return array|false
     * @throws FactoryException
     */
    public function mfaTokenGenerate($iUserId)
    {
        /** @var Password $oPasswordModel */
        $oPasswordModel = Factory::model('UserPassword', Constants::MODULE_SLUG);
        /** @var DateTime $oNow */
        $oNow = Factory::factory('DateTime');
        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        /** @var Database $oDb */
        $oDb = Factory::service('Database');

        $sSalt    = $oPasswordModel->salt();
        $sIp      = $oInput->ipAddress();
        $sCreated = $oNow->format('Y-m-d H:i:s');
        $sExpires = $oNow->add(new DateInterval('PT10M'))->format('Y-m-d H:i:s');
        $aToken   = [
            'token' => sha1(sha1(\Nails\Config::get('PRIVATE_KEY') . $iUserId . $sCreated . $sExpires . $sIp) . $sSalt),
            'salt'  => md5($sSalt),
        ];

        //  Add this to the DB
        $oDb->set('user_id', $iUserId);
        $oDb->set('token', $aToken['token']);
        $oDb->set('salt', $aToken['salt']);
        $oDb->set('created', $sCreated);
        $oDb->set('expires', $sExpires);
        $oDb->set('ip', $sIp);

        if ($oDb->insert(static::TABLE_TWO_FACTOR_TOKEN)) {
            $aToken['id'] = $oDb->insert_id();
            return $aToken;
        } else {
            $error = lang('auth_twofactor_token_could_not_generate');
            $this->setError($error);
            return false;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Validate a MFA token
     *
     * @param int    $iUserId The ID of the user the token belongs to
     * @param string $sSalt   The token's salt
     * @param string $sToken  The token's hash
     * @param string $sIp     The user's IP address
     *
     * @return bool
     * @throws FactoryException
     */
    public function mfaTokenValidate($iUserId, $sSalt, $sToken, $sIp)
    {
        /** @var Database $oDb */
        $oDb = Factory::service('Database');
        $oDb->where('user_id', $iUserId);
        $oDb->where('salt', $sSalt);
        $oDb->where('token', $sToken);

        $oToken  = $oDb->get(static::TABLE_TWO_FACTOR_TOKEN)->row();
        $bReturn = true;

        if (!$oToken) {

            $this->setError(lang('auth_twofactor_token_invalid'));
            return false;

        } elseif (strtotime($oToken->expires) <= time()) {

            $this->setError(lang('auth_twofactor_token_expired'));
            $bReturn = false;

        } elseif ($oToken->ip != $sIp) {

            $this->setError(lang('auth_twofactor_token_bad_ip'));
            $bReturn = false;
        }

        //  Delete the token
        $this->mfaTokenDelete($oToken->id);

        return $bReturn;
    }

    // --------------------------------------------------------------------------

    /**
     * Delete an MFA token
     *
     * @param int $iTokenId The token's ID
     *
     * @return bool
     * @throws FactoryException
     */
    public function mfaTokenDelete($iTokenId)
    {
        /** @var Database $oDb */
        $oDb = Factory::service('Database');
        $oDb->where('id', $iTokenId);
        $oDb->delete(static::TABLE_TWO_FACTOR_TOKEN);
        return (bool) $oDb->affected_rows();
    }

    // --------------------------------------------------------------------------

    /**
     * Fetches a random MFA question for a user
     *
     * @param int $iUserId The user's ID
     *
     * @return bool|stdClass
     * @throws DecodeException
     * @throws EnvironmentException
     * @throws FactoryException
     */
    public function mfaQuestionGet($iUserId)
    {
        /** @var Database $oDb */
        $oDb = Factory::service('Database');
        /** @var Input $oInput */
        $oInput = Factory::service('Input');

        $oDb->where('user_id', $iUserId);
        $oDb->order_by('last_requested', 'DESC');
        $aQuestions = $oDb->get(static::TABLE_TWO_FACTOR_QUESTION)->result();

        if (!$aQuestions) {
            $this->setError('No security questions available for this user.');
            return false;
        }

        // --------------------------------------------------------------------------

        //  Choose a question to return
        if (count($aQuestions) == 1) {

            //  No choice, just return the lonely question
            $oOut = reset($aQuestions);

        } elseif (count($aQuestions) > 1) {

            /**
             * Has the most recently asked question been asked in the last 10 minutes?
             * If so, return that one again (to make harvesting all the user's questions
             * a little more time consuming). If not randomly choose one.
             */

            $oOut = reset($aQuestions);
            if (strtotime($oOut->last_requested) < strtotime('-10 MINS')) {
                $oOut = $aQuestions[array_rand($aQuestions)];
            }

        } else {
            $this->setError('Could not determine security question.');
            return false;
        }

        //  Decode the question
        /** @var Encrypt $oEncrypt */
        $oEncrypt       = Factory::service('Encrypt');
        $oOut->question = $oEncrypt->decode($oOut->question, \Nails\Config::get('PRIVATE_KEY') . $oOut->salt);

        //  Update the last requested details
        $oDb->set('last_requested', 'NOW()', false);
        $oDb->set('last_requested_ip', $oInput->ipAddress());
        $oDb->where('id', $oOut->id);
        $oDb->update(static::TABLE_TWO_FACTOR_QUESTION);

        return $oOut;
    }

    // --------------------------------------------------------------------------

    /**
     * Validates the answer to an MFA Question
     *
     * @param int    $iQuestionId The question's ID
     * @param int    $iUserId     The user's ID
     * @param string $answer      The user's answer
     *
     * @return bool
     * @throws FactoryException
     */
    public function mfaQuestionValidate($iQuestionId, $iUserId, $answer)
    {
        /** @var Database $oDb */
        $oDb = Factory::service('Database');
        $oDb->select('answer, salt');
        $oDb->where('id', $iQuestionId);
        $oDb->where('user_id', $iUserId);
        $oQuestion = $oDb->get(static::TABLE_TWO_FACTOR_QUESTION)->row();

        if (!$oQuestion) {
            return false;
        }

        $hash = sha1(sha1(strtolower($answer)) . \Nails\Config::get('PRIVATE_KEY') . $oQuestion->salt);

        return $hash === $oQuestion->answer;
    }

    // --------------------------------------------------------------------------

    /**
     * Sets MFA questions for a user
     *
     * @param int   $iUserId   The user's ID
     * @param array $aData     An array of question and answers
     * @param bool  $bClearOld Whether or not to clear old questions
     *
     * @return bool
     * @throws FactoryException
     * @throws EnvironmentException
     */
    public function mfaQuestionSet($iUserId, $aData, $bClearOld = true)
    {
        //  Check input
        foreach ($aData as $oDatum) {
            if (empty($oDatum->question) || empty($oDatum->answer)) {
                $this->setError('Malformed question/answer data.');
                return false;
            }
        }

        //  Begin transaction
        /** @var Database $oDb */
        $oDb = Factory::service('Database');
        $oDb->transaction()->start();

        //  Delete old questions?
        if ($bClearOld) {
            $oDb->where('user_id', $iUserId);
            $oDb->delete(static::TABLE_TWO_FACTOR_QUESTION);
        }

        /** @var Password $oPasswordModel */
        $oPasswordModel = Factory::model('UserPassword', Constants::MODULE_SLUG);
        /** @var Encrypt $oEncrypt */
        $oEncrypt = Factory::service('Encrypt');

        $aQuestionData = [];
        $iCounter      = 0;
        $oNow          = Factory::factory('DateTime');
        $sDateTime     = $oNow->format('Y-m-d H:i:s');

        foreach ($aData as $oDatum) {
            $sSalt                    = $oPasswordModel->salt();
            $aQuestionData[$iCounter] = [
                'user_id'        => $iUserId,
                'salt'           => $sSalt,
                'question'       => $oEncrypt->encode($oDatum->question, \Nails\Config::get('PRIVATE_KEY') . $sSalt),
                'answer'         => sha1(sha1(strtolower($oDatum->answer)) . \Nails\Config::get('PRIVATE_KEY') . $sSalt),
                'created'        => $sDateTime,
                'last_requested' => null,
            ];
            $iCounter++;
        }

        if ($aQuestionData) {

            $oDb->insert_batch(static::TABLE_TWO_FACTOR_QUESTION, $aQuestionData);

            if ($oDb->transaction()->status() !== false) {
                $oDb->transaction()->commit();
                return true;
            } else {
                $oDb->transaction()->rollback();
                return false;
            }

        } else {
            $oDb->transaction()->rollback();
            $this->setError('No data to save.');
            return false;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Gets the user's MFA device if there is one
     *
     * @param int $iUserId The user's ID
     *
     * @return bool|stdClass         \stdClass on success, false on failure
     * @throws EnvironmentException
     * @throws FactoryException
     * @throws DecodeException
     */
    public function mfaDeviceSecretGet($iUserId)
    {
        /** @var Database $oDb */
        $oDb = Factory::service('Database');
        /** @var Encrypt $oEncrypt */
        $oEncrypt = Factory::service('Encrypt');

        $oDb->where('user_id', $iUserId);
        $oDb->limit(1);
        $aResult = $oDb->get(static::TABLE_TWO_FACTOR_DEVICE_SECRET)->result();

        if (empty($aResult)) {
            return false;
        }

        $oReturn         = reset($aResult);
        $oReturn->secret = $oEncrypt->decode($oReturn->secret, \Nails\Config::get('PRIVATE_KEY'));

        return $oReturn;
    }

    // --------------------------------------------------------------------------

    /**
     * Generates a MFA Device Secret
     *
     * @param int    $iUserId         The user ID to generate for
     * @param string $sExistingSecret The existing secret to use instead of generating a new one
     *
     * @return bool|array
     * @throws FactoryException
     * @throws ModelException
     */
    public function mfaDeviceSecretGenerate($iUserId, $sExistingSecret = null)
    {
        //  Get an identifier for the user
        /** @var \Nails\Auth\Model\User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);
        $oUser      = $oUserModel->getById($iUserId);

        if (!$oUser) {
            $this->setError('User does not exist.');
            return false;
        }

        $oGoogleAuth = new GoogleAuthenticator();

        //  Generate the secret
        if (empty($sExistingSecret)) {
            $sSecret = $oGoogleAuth->generateSecret();
        } else {
            $sSecret = $sExistingSecret;
        }

        //  Get the hostname
        $sHostname = Url::extractRegistrableDomain(\Nails\Config::get('BASE_URL'));

        //  User identifier
        $sUsername = $oUser->username;
        $sUsername = empty($sUsername) ? preg_replace('/[^a-z]/', '', strtolower($oUser->first_name . $oUser->last_name)) : $sUsername;
        $sUsername = empty($sUsername) ? preg_replace('/[^a-z]/', '', strtolower($oUser->email)) : $sUsername;

        return [
            'secret' => $sSecret,
            //  @todo (Pablo - 2020-03-02) - Refactor deprecated functionality
            'url'    => $oGoogleAuth->getUrl($sUsername, $sHostname, $sSecret),
        ];
    }

    // --------------------------------------------------------------------------

    /**
     * Validates a secret against two given codes, if valid adds as a device for
     * the user
     *
     * @param int    $iUserId The user's ID
     * @param string $sSecret The secret being used
     * @param int    $iCode   The first code to be generate
     *
     * @return bool
     * @throws FactoryException
     * @throws EnvironmentException
     */
    public function mfaDeviceSecretValidate($iUserId, $sSecret, $iCode)
    {
        //  Tidy up codes so that they only contain digits
        $sCode = preg_replace('/[^\d]/', '', $iCode);

        //  New instance of the authenticator
        $oGoogleAuth = new GoogleAuthenticator();

        if ($oGoogleAuth->checkCode($sSecret, $sCode)) {

            /** @var Database $oDb */
            $oDb = Factory::service('Database');
            /** @var Encrypt $oEncrypt */
            $oEncrypt = Factory::service('Encrypt');

            $oDb->set('user_id', $iUserId);
            $oDb->set('secret', $oEncrypt->encode($sSecret, \Nails\Config::get('PRIVATE_KEY')));
            $oDb->set('created', 'NOW()', false);

            if ($oDb->insert(static::TABLE_TWO_FACTOR_DEVICE_SECRET)) {

                $iSecretId = $oDb->insert_id();
                $oNow      = Factory::factory('DateTime');

                $oDb->set('secret_id', $iSecretId);
                $oDb->set('code', $sCode);
                $oDb->set('used', $oNow->format('Y-m-d H:i:s'));
                $oDb->insert(\Nails\Config::get('NAILS_DB_PREFIX') . 'user_auth_two_factor_device_code');

                return true;

            } else {
                $this->setError('Could not save secret.');
                return false;
            }

        } else {
            $this->setError('Codes did not validate.');
            return false;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Validates an MFA Device code
     *
     * @param int    $iUserId The user's ID
     * @param string $sCode   The code to validate
     *
     * @return bool
     * @throws DecodeException
     * @throws EnvironmentException
     * @throws FactoryException
     */
    public function mfaDeviceCodeValidate($iUserId, $sCode)
    {
        //  Get the user's secret
        $oSecret = $this->mfaDeviceSecretGet($iUserId);

        if (!$oSecret) {
            $this->setError('Invalid User');
            return false;
        }

        //  Has the code been used before?
        /** @var Database $oDb */
        $oDb = Factory::service('Database');
        $oDb->where('secret_id', $oSecret->id);
        $oDb->where('code', $sCode);

        if ($oDb->count_all_results(\Nails\Config::get('NAILS_DB_PREFIX') . 'user_auth_two_factor_device_code')) {
            $this->setError('Code has already been used.');
            return false;
        }

        //  Tidy up codes so that they only contain digits
        $sCode = preg_replace('/[^\d]/', '', $sCode);

        //  New instance of the authenticator
        $oGoogleAuth = new GoogleAuthenticator();
        $checkCode   = $oGoogleAuth->checkCode($oSecret->secret, $sCode);

        if ($checkCode) {

            //  Log the code so it can't be used again
            $oDb->set('secret_id', $oSecret->id);
            $oDb->set('code', $sCode);
            $oDb->set('used', 'NOW()', false);

            $oDb->insert(\Nails\Config::get('NAILS_DB_PREFIX') . 'user_auth_two_factor_device_code');

            return true;

        } else {
            return false;
        }
    }
}

<?php

/**
 * This service provides methods for the import user system, allowing the app to hook into the import process.
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    Service
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Auth\Service\User;

use Closure;
use Nails\Auth\Constants;
use Nails\Auth\Model\User;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\NailsException;
use Nails\Common\Exception\ValidationException;
use Nails\Common\Factory\Model\Field;
use Nails\Common\Service\DateTime;
use Nails\Common\Service\FormValidation;
use Nails\Config;
use Nails\Factory;

/**
 * Class Import
 *
 * @package Nails\Auth\Service\User
 */
class Import
{
    /**
     * Returns the columns which can be imported
     *
     * @return string[]
     */
    public function getKeys(): array
    {
        return [
            'email',
            'username',
            'group_id',
            'password',
            'temp_pw',
            'send_email',
            'salutation',
            'first_name',
            'last_name',
            'gender',
            'dob',
            'timezone',
        ];
    }

    /**
     * Returns the keys whose values must be unique across the entire import
     *
     * @return string[]
     */
    public function getUniqueKeys(): array
    {
        return [
            'email',
            'username',
        ];
    }

    /**
     * Returns the validation rules for a given key
     *
     * @param string $key The key to lookup
     *
     * @return array<string, string|Closure>
     * @throws FactoryException
     */
    public function getValidationRules(string $key): array
    {
        /** @var User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);
        /** @var User\Group $oUserGroupModel */
        $oUserGroupModel = Factory::model('UserGroup', Constants::MODULE_SLUG);
        /** @var User\Password $oUserPasswordModel */
        $oUserPasswordModel = Factory::model('UserPassword', Constants::MODULE_SLUG);
        /** @var DateTime $oDateTimeService */
        $oDateTimeService = Factory::service('DateTime');
        /** @var FormValidation $oFormValidationService */
        $oFormValidationService = Factory::service('FormValidation');

        return match ($key) {
            'email' => array_filter([
                in_array(Config::get('APP_NATIVE_LOGIN_USING'), ['EMAIL', 'BOTH'])
                    ? FormValidation::RULE_REQUIRED
                    : null,
                FormValidation::RULE_VALID_EMAIL,
                function ($email) use ($oUserModel) {
                    if ($email && $oUserModel->getByEmail($email)) {
                        throw new ValidationException(sprintf(
                            '"%s" is already registered',
                            $email
                        ));
                    }
                },
            ]),
            'username' => array_filter([
                in_array(Config::get('APP_NATIVE_LOGIN_USING'), ['USERNAME', 'BOTH'])
                    ? FormValidation::RULE_REQUIRED
                    : null,
                function ($username) use ($oUserModel) {
                    if ($username && $oUserModel->getByUsername($username)) {
                        throw new ValidationException(sprintf(
                            '"%s" is already registered',
                            $username
                        ));
                    }
                },
                function ($username) use ($oUserModel) {
                    if ($username && !$oUserModel->isValidUsername($username)) {
                        throw new ValidationException(sprintf(
                            '"%s" is not a valid username; %s',
                            $username,
                            $oUserModel->lastError()
                        ));
                    }
                },
            ]),
            'group_id' => [
                FormValidation::RULE_INTEGER,
                function ($groupId) use ($oUserGroupModel) {
                    if ($groupId && !$oUserGroupModel->getById((int) $groupId)) {
                        throw new ValidationException(sprintf(
                            '"%s" is not a valid user group ID; %s',
                            $groupId,
                            $oUserGroupModel->lastError()
                        ));
                    }
                },
            ],
            'password' => [
                function ($password) use ($oUserPasswordModel, $oUserGroupModel, $oFormValidationService) {

                    if (!$password) {
                        return;
                    }

                    $groupId = $oFormValidationService->validation_data['group_id'] ?? null;
                    $group   = $oUserGroupModel->getById((int) $groupId) ?? $oUserGroupModel->getDefaultGroup();

                    if (!$oUserPasswordModel->isAcceptable($group, $password)) {
                        throw new ValidationException(
                            'Password does not meet requirements.'
                        );
                    }
                },
            ],
            'temp_pw',
            'send_email' => [
                FormValidation::RULE_REQUIRED,
                FormValidation::RULE_IS_BOOL,
            ],
            'salutation' => [
                FormValidation::rule(FormValidation::RULE_MAX_LENGTH, 15),
            ],
            'first_name',
            'last_name' => [
                FormValidation::rule(FormValidation::RULE_MAX_LENGTH, 150),
            ],
            'gender' => [
                FormValidation::rule(FormValidation::RULE_IN_LIST, implode(',', array_keys($oUserModel->getGenders()))),
            ],
            'dob' => [
                FormValidation::RULE_VALID_DATE,
            ],
            'timezone' => [
                function ($timezone) use ($oDateTimeService) {
                    if (!in_array($timezone, array_keys($oDateTimeService->getAllTimezoneFlat()))) {
                        throw new ValidationException(sprintf(
                            '"%s" is not a valid PHP timezone value',
                            $timezone,
                        ));
                    }
                },
            ],
            default => [],
        };
    }

    /**
     * @param string $key
     *
     * @return string|int|bool|null
     * @throws FactoryException
     * @throws NailsException
     */
    public function getDefaultValue(string $key): null|string|int|bool
    {
        /** @var User\Group $oUserGroupModel */
        $oUserGroupModel = Factory::model('UserGroup', Constants::MODULE_SLUG);

        return match ($key) {
            'group_id' => $oUserGroupModel->getDefaultGroupId(),
            default => null,
        };
    }

    /**
     * Returns the placeholder/example value for the given key (used in the template))
     *
     * @param string $key The key to lookup
     *
     * @return string
     * @throws FactoryException
     */
    public function getExample(string $key): string
    {
        /** @var User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);

        return match ($key) {
            'email' => 'user@example.com',
            'username' => 'user_example',
            'group_id' => 'If not set, default user group is used',
            'password' => 'Automatically generated if not set',
            'temp_pw',
            'send_email' => '1 for yes, 0 for no',
            'gender' => 'Blank, or one of: ' . implode(', ', array_keys($oUserModel->getGenders())),
            'dob' => 'Blank, or date in format YYYY-MM-DD',
            'timezone' => 'Blank, or PHP timezone (as documented https://www.php.net/manual/en/timezones.php)',
            default => '',
        };
    }

    /**
     * Return an array of additional fields which will be applied to each user
     * Important: POST keys must be in the format `additional[key]`
     *
     * @return array<int, string|Closure|Field>
     */
    public function getAdditionalFields(): array
    {
        return [];
    }

    /**
     * Provides an opportunity to mutate an additional field value
     *
     * @param string $sKey
     * @param mixed  $mValue
     *
     * @return mixed
     */
    public function parseAdditionalFields(string $sKey, mixed $mValue): mixed
    {
        return $mValue;
    }
}

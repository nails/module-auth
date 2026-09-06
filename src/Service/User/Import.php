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
use Nails\Auth\Exception\User\Import\TemplateException;
use Nails\Auth\Model\User;
use Nails\Auth\Resource;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\NailsException;
use Nails\Common\Exception\ValidationException;
use Nails\Common\Factory\Model\Field;
use Nails\Common\Service\Database;
use Nails\Common\Service\DateTime;
use Nails\Common\Service\FormValidation;
use Nails\Common\Validation\Context;
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
     * The number of values whichExist() puts in a single IN clause
     *
     * CodeIgniter's query builder runs a preg_match over the whole compiled
     * condition, so one enormous IN clause fails outright with "regular
     * expression is too large" - a 1,362 value list compiles to roughly 35KB,
     * past what PCRE will accept. Chunking also keeps the statement well inside
     * max_allowed_packet. Still one query per few hundred values rather than one
     * per row, which is the point.
     *
     * @var int
     */
    const WHICH_EXIST_CHUNK_SIZE = 250;


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
     * Returns which of the given values are already taken
     *
     * Batched on purpose: this is asked once per unique key for a whole file, or
     * a whole chunk, rather than once per row. One indexed lookup answers it,
     * where the per-row closures this replaced cost a query each.
     *
     * An app which adds a unique key must handle it here; there is deliberately
     * no silent fallback, because a uniqueness check which quietly answers "none
     * of them" is worse than one which fails. The key is checked before the
     * empty-value short circuit so an unsupported key is caught even when there
     * is nothing to look up.
     *
     * @param string   $sKey    The unique key being checked
     * @param string[] $aValues The values to look for
     *
     * @return string[] The values which are already taken, lowercased
     * @throws TemplateException If the key cannot be looked up
     * @throws FactoryException
     */
    public function whichExist(string $sKey, array $aValues): array
    {
        /** @var User\Email $oUserEmailModel */
        $oUserEmailModel = Factory::model('UserEmail', Constants::MODULE_SLUG);
        /** @var User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);

        [$sTable, $sColumn] = match ($sKey) {
            'email'    => [$oUserEmailModel->getTableName(), 'email'],
            'username' => [$oUserModel->getTableName(), 'username'],
            default    => throw new TemplateException(sprintf(
                '"%s" is listed by %s::getUniqueKeys() but %s::whichExist() does not know how to '
                . 'look it up; override it to say where the value lives.',
                $sKey,
                static::class,
                static::class
            )),
        };

        $aValues = array_values(
            array_unique(
                array_filter(
                    array_map(fn($mValue): string => strtolower(trim((string) $mValue)), $aValues),
                    fn(string $sValue): bool => $sValue !== ''
                )
            )
        );

        if (empty($aValues)) {
            return [];
        }

        /** @var Database $oDb */
        $oDb = Factory::service('Database');

        $aExisting = [];

        foreach (array_chunk($aValues, static::WHICH_EXIST_CHUNK_SIZE) as $aChunk) {

            /**
             * Queried directly rather than through the model: this is an
             * existence probe, and Model\User::getCountCommon() would hydrate
             * every match across three joins to answer a question about a single
             * column.
             */
            $aRows = $oDb
                ->select($sColumn)
                ->where_in($sColumn, $aChunk)
                ->get($sTable)
                ->result();

            foreach ($aRows as $oRow) {
                $aExisting[] = strtolower(trim((string) $oRow->{$sColumn}));
            }
        }

        return $aExisting;
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

        return match ($key) {
            /**
             * Note that uniqueness is deliberately absent from here and from
             * 'username'. It used to be a closure calling getByEmail() per row,
             * which cost a query - two, on a hit - for every line of the file.
             * It is now a batched whole-of-file concern; see whichExist() and
             * Import\Validator::detectRegistered().
             */
            'email' => array_filter([
                in_array(Config::get('APP_NATIVE_LOGIN_USING'), ['EMAIL', 'BOTH'])
                    ? FormValidation::RULE_REQUIRED
                    : null,
                FormValidation::RULE_VALID_EMAIL,
            ]),
            'username' => array_filter([
                in_array(Config::get('APP_NATIVE_LOGIN_USING'), ['USERNAME', 'BOTH'])
                    ? FormValidation::RULE_REQUIRED
                    : null,
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
                function ($password, Context $oContext) use ($oUserPasswordModel, $oUserGroupModel) {

                    if (!$password) {
                        return;
                    }

                    $groupId = $oContext->getValue('group_id');
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

    // --------------------------------------------------------------------------
    //  Lifecycle hooks
    //
    //  All five are no-ops here, and an override should call parent:: as
    //  whichExist() documents. Read the failure semantics before overriding:
    //  they are not the same for each hook, because what the processor can still
    //  do about a failure is not the same at each point.
    // --------------------------------------------------------------------------

    /**
     * Fired once, when validation has passed and before the first account is created
     *
     * The one hook which may refuse the whole job: this runs while nothing has
     * been created, so a throw here is deliberately left to reach the processor,
     * which fails the job with the reason folded into its composed error. Throw
     * a ValidationException with a sentence an admin can act on.
     *
     * Note that it fires once per job, not once per chunk - run() is called
     * many times, across processes, but this is not.
     *
     * @param Resource\User\Import $oImport The job which is about to start
     */
    public function onImportStart(Resource\User\Import $oImport): void
    {
    }

    // --------------------------------------------------------------------------

    /**
     * Fired for every row, immediately before the account is created
     *
     * The data is returned rather than taken by reference; whatever comes back
     * is what reaches Model\User::create(), so an override which forgets to
     * return imports nothing. Keys create() does not recognise - anything which
     * is neither a user column nor a meta field - are silently discarded by it,
     * so app-specific values belong in afterUserCreate() rather than here.
     *
     * This runs *after* the job's additional fields have been applied, so an
     * override sees, and may overrule, what parseAdditionalFields() decided.
     *
     * A throw marks the row ERROR and leaves no account behind; the rest of the
     * job carries on.
     *
     * @param array<string, mixed>  $aUserData The data create() will be given
     * @param Resource\User\Import  $oImport   The job the row belongs to
     * @param array<string, string> $aRow      The CSV row, aligned to the header,
     *                                        including columns create() will drop
     *
     * @return array<string, mixed> The data to create the account with
     */
    public function prepareUserData(array $aUserData, Resource\User\Import $oImport, array $aRow): array
    {
        return $aUserData;
    }

    // --------------------------------------------------------------------------

    /**
     * Fired for every row, immediately after the account is created
     *
     * This is where app-specific follow-on work belongs - applying data a user
     * column cannot hold, starting a subscription, and so on.
     *
     * The account is committed by the time this is called: Model\User::create()
     * commits, fires USER_CREATED and queues the welcome email before it
     * returns, so nothing here can undo it. A throw is therefore recorded
     * rather than retried - the row is marked WARNING against the new account's
     * ID, the exception is reported, and the job finishes PARTIAL so that a
     * silent failure cannot pass as a clean import.
     *
     * @param Resource\User        $oUser     The account which was just created
     * @param array<string, mixed> $aUserData The data it was created with
     * @param Resource\User\Import $oImport   The job the row belongs to
     */
    public function afterUserCreate(
        Resource\User $oUser,
        array $aUserData,
        Resource\User\Import $oImport
    ): void {
    }

    // --------------------------------------------------------------------------

    /**
     * Fired once, when the job has finished
     *
     * Every account which was going to be created already exists, so this must
     * not pretend the job can be undone. A throw is reported and appended to the
     * job's stored error - which is what the details modal and the notification
     * email show - but it cannot re-brand the job: a finished import stays
     * COMPLETE or PARTIAL.
     *
     * @param Resource\User\Import $oImport The job, as it now stands
     */
    public function onImportComplete(Resource\User\Import $oImport): void
    {
    }

    // --------------------------------------------------------------------------

    /**
     * Fired once, when the job has been rejected or abandoned
     *
     * Note that this fires for a job rejected during validation, where no
     * account was ever created, as well as for one brought down part way
     * through, where some exist; read `$oImport->success_count` rather than
     * assuming either.
     *
     * A throw is reported and otherwise swallowed. It cannot be rethrown: the
     * processor's failure path is reached from its own catch-all, so an
     * exception escaping here would recurse straight back into it.
     *
     * @param Resource\User\Import $oImport The job, as it now stands
     */
    public function onImportFailed(Resource\User\Import $oImport): void
    {
    }
}

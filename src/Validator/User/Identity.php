<?php

/**
 * Validates a user's identity fields (email and/or username, per
 * `APP_NATIVE_LOGIN_USING`), including that they are not already registered.
 *
 * Use it on its own, or as the base of a larger form:
 *
 *     (new Identity())
 *         ->addRules(['first_name' => [FormValidation::RULE_REQUIRED]])
 *         ->run($oInput->post());
 *
 * Pass a user ID to exempt that user's own email/username from the uniqueness check
 * when validating an edit. Override a field with `[]` to leave it unvalidated.
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    Validator
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Auth\Validator\User;

use Nails\Auth\Constants;
use Nails\Auth\Model\User;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Factory\Service\FormValidation\Validator;
use Nails\Common\Service\FormValidation;
use Nails\Config;
use Nails\Factory;

class Identity extends Validator
{
    /**
     * @param int|null $iIgnoreUserId A user whose own email/username should not count as taken
     */
    public function __construct(private readonly ?int $iIgnoreUserId = null)
    {
        parent::__construct();
    }

    // --------------------------------------------------------------------------

    /**
     * Whether the app identifies users by email
     */
    public static function usesEmail(): bool
    {
        return in_array(Config::get('APP_NATIVE_LOGIN_USING'), ['EMAIL', 'BOTH'], true);
    }

    /**
     * Whether the app identifies users by username
     */
    public static function usesUsername(): bool
    {
        return in_array(Config::get('APP_NATIVE_LOGIN_USING'), ['USERNAME', 'BOTH'], true);
    }

    // --------------------------------------------------------------------------

    /**
     * @throws FactoryException
     */
    protected function rules(): array
    {
        $aRules = [];

        if (static::usesEmail()) {
            /** @var User\Email $oUserEmailModel */
            $oUserEmailModel = Factory::model('UserEmail', Constants::MODULE_SLUG);

            $aRules['email'] = [
                'trim',
                FormValidation::RULE_REQUIRED,
                FormValidation::RULE_VALID_EMAIL,
                FormValidation::rule(FormValidation::RULE_MAX_LENGTH, 255),
                $this->uniqueRule($oUserEmailModel->getTableName(), 'email', 'user_id'),
            ];
        }

        if (static::usesUsername()) {
            /** @var User $oUserModel */
            $oUserModel = Factory::model('User', Constants::MODULE_SLUG);

            $aRules['username'] = [
                'trim',
                FormValidation::RULE_REQUIRED,
                FormValidation::rule(FormValidation::RULE_MAX_LENGTH, 150),
                FormValidation::RULE_ALPHA_DASH_PERIOD,
                $this->uniqueRule($oUserModel->getTableName(), 'username', 'id'),
            ];
        }

        return $aRules;
    }

    // --------------------------------------------------------------------------

    protected function messages(): array
    {
        $sKey = match (Config::get('APP_NATIVE_LOGIN_USING')) {
            'EMAIL'    => 'auth_register_email_is_unique',
            'USERNAME' => 'auth_register_username_is_unique',
            default    => 'auth_register_identity_is_unique',
        };

        return [
            FormValidation::RULE_IS_UNIQUE => lang($sKey, siteUrl('auth/password/forgotten')),
        ];
    }

    // --------------------------------------------------------------------------

    /**
     * Compiles an `is_unique` rule, exempting the ignored user if one was given
     *
     * @param string $sTable        The table to check
     * @param string $sColumn       The column holding the value
     * @param string $sUserIdColumn The column holding the user ID in that table
     */
    protected function uniqueRule(string $sTable, string $sColumn, string $sUserIdColumn): string
    {
        return $this->iIgnoreUserId
            ? FormValidation::rule(FormValidation::RULE_IS_UNIQUE, $sTable, $sColumn, $this->iIgnoreUserId, $sUserIdColumn)
            : FormValidation::rule(FormValidation::RULE_IS_UNIQUE, $sTable, $sColumn);
    }
}

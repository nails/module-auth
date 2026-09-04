<?php

/**
 * Validates the `identifier` a user logs in with: an email address when the app
 * identifies users by email, otherwise any non-empty value.
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    Validator
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Auth\Validator\User;

use Nails\Common\Factory\Service\FormValidation\Validator;
use Nails\Common\Service\FormValidation;
use Nails\Config;

class Identifier extends Validator
{
    public const FIELD = 'identifier';

    // --------------------------------------------------------------------------

    protected function rules(): array
    {
        return [
            static::FIELD => Config::get('APP_NATIVE_LOGIN_USING') === 'EMAIL'
                ? [FormValidation::RULE_REQUIRED, FormValidation::RULE_VALID_EMAIL]
                : [FormValidation::RULE_REQUIRED],
        ];
    }
}

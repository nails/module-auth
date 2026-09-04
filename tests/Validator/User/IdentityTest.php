<?php

namespace Tests\Validator\User;

use Nails\Auth\Validator\User\Identity;
use Nails\Common\Exception\ValidationException;
use Nails\Common\Service\FormValidation;
use Nails\Common\Validation\Context;
use Nails\Config;
use PHPUnit\Framework\TestCase;

class IdentityTest extends TestCase
{
    protected function setUp(): void
    {
        Config::set('APP_NATIVE_LOGIN_USING', 'BOTH');
    }

    /**
     * A validator whose uniqueness check is answered by $aTaken (column => taken values)
     */
    private function validator(array $aTaken = [], ?int $iIgnoreUserId = null): Identity
    {
        return (new Identity($iIgnoreUserId))
            ->stubRule(FormValidation::RULE_IS_UNIQUE, function ($mValue, Context $oContext) use ($aTaken) {
                [, $sColumn] = $oContext->getParams();
                return !in_array($mValue, $aTaken[$sColumn] ?? [], true);
            });
    }

    private function errorsFor(Identity $oValidator, array $aData): array
    {
        try {
            $oValidator->run($aData);
            return [];
        } catch (ValidationException $e) {
            return $e->getData();
        }
    }

    // --------------------------------------------------------------------------

    public function test_both_fields_are_required_when_logging_in_with_both(): void
    {
        self::assertSame(
            ['email', 'username'],
            array_keys($this->errorsFor($this->validator(), []))
        );
    }

    public function test_a_clean_identity_passes_and_is_trimmed(): void
    {
        $oValidator = $this->validator();
        $oValidator->run(['email' => ' ada@example.com ', 'username' => ' ada.lovelace ']);

        self::assertSame(
            ['email' => 'ada@example.com', 'username' => 'ada.lovelace'],
            $oValidator->getValidatedData()
        );
    }

    public function test_a_taken_identity_reports_the_already_registered_message(): void
    {
        $aErrors = $this->errorsFor(
            $this->validator(['email' => ['ada@example.com']]),
            ['email' => 'ada@example.com', 'username' => 'ada']
        );

        self::assertSame(['email'], array_keys($aErrors));
        self::assertStringContainsString('already registered', $aErrors['email']);
    }

    public function test_the_ignored_user_is_passed_to_the_uniqueness_rule(): void
    {
        $aSeen      = [];
        $oValidator = (new Identity(42))
            ->stubRule(FormValidation::RULE_IS_UNIQUE, function ($mValue, Context $oContext) use (&$aSeen) {
                $aSeen[$oContext->getField()] = $oContext->getParams();
                return true;
            });

        $oValidator->run(['email' => 'ada@example.com', 'username' => 'ada']);

        self::assertSame(['42', 'user_id'], array_slice($aSeen['email'], 2));
        self::assertSame(['42', 'id'], array_slice($aSeen['username'], 2));
    }

    public function test_malformed_values_fail_before_uniqueness_is_checked(): void
    {
        $aErrors = $this->errorsFor(
            $this->validator(),
            ['email' => 'not-an-email', 'username' => 'has spaces!']
        );

        self::assertSame('This must be a valid email.', $aErrors['email']);
        self::assertStringContainsString('alpha-numeric', $aErrors['username']);
    }

    public function test_only_the_configured_identity_is_validated(): void
    {
        Config::set('APP_NATIVE_LOGIN_USING', 'EMAIL');

        self::assertSame(['email'], array_keys($this->validator()->getRules()));
        self::assertSame([], $this->errorsFor($this->validator(), ['email' => 'ada@example.com']));
    }

    public function test_a_field_can_be_switched_off_by_overriding_it_with_no_rules(): void
    {
        $oValidator = $this->validator()->setRules(['username' => []]);

        self::assertSame([], $this->errorsFor($oValidator, ['email' => 'ada@example.com']));
    }

    public function test_extra_rules_merge_with_the_identity_rules(): void
    {
        $oValidator = $this->validator()->addRules(['first_name' => [FormValidation::RULE_REQUIRED]]);

        self::assertSame(
            ['email', 'username', 'first_name'],
            array_keys($this->errorsFor($oValidator, []))
        );
    }
}

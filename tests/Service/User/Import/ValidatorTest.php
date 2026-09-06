<?php

namespace Tests\Auth\Service\User\Import;

use Nails\Auth\Constants;
use Nails\Auth\Service\User\Import\Validator;
use Nails\Common\Exception\ValidationException;
use Nails\Common\Service\FormValidation;
use Nails\Auth\Exception\User\Import\TemplateException;
use Nails\Config;
use Nails\Factory;
use PHPUnit\Framework\TestCase;
use Tests\Auth\Stub\ImportServiceStub;
use Tests\Auth\Stub\ValidatorWithStub;

/**
 * @covers \Nails\Auth\Service\User\Import\Validator
 *
 * validateRows() is exercised with columns whose rules do not touch the
 * database (email/username/group/password/timezone rules need models); those
 * are covered by the end to end verification of an import.
 */
class ValidatorTest extends TestCase
{
    private Validator $oValidator;

    /**
     * @var string[]
     */
    private array $aPaths = [];

    // --------------------------------------------------------------------------

    private mixed $mLoginUsing = null;

    // --------------------------------------------------------------------------

    protected function setUp(): void
    {
        /** @var Validator $oValidator */
        $oValidator       = Factory::service('UserImportValidator', Constants::MODULE_SLUG);
        $this->oValidator = $oValidator;

        $this->mLoginUsing = Config::get('APP_NATIVE_LOGIN_USING');
    }

    // --------------------------------------------------------------------------

    protected function tearDown(): void
    {
        foreach ($this->aPaths as $sPath) {
            @unlink($sPath);
        }
        $this->aPaths = [];

        Config::set('APP_NATIVE_LOGIN_USING', $this->mLoginUsing);
    }

    // --------------------------------------------------------------------------

    private function write(string $sContents): string
    {
        $sPath = tempnam(sys_get_temp_dir(), 'nails-user-import-test-');
        file_put_contents($sPath, $sContents);
        $this->aPaths[] = $sPath;
        return $sPath;
    }

    // --------------------------------------------------------------------------

    /**
     * Builds a validator over a template an app has amended
     *
     * @param string[]|null           $aKeys
     * @param string[]|null           $aUniqueKeys
     * @param array<string, string[]> $aExisting
     */
    private function withTemplate(
        ?array $aKeys = null,
        ?array $aUniqueKeys = null,
        array $aExisting = []
    ): array {
        $oService   = new ImportServiceStub($aKeys, $aUniqueKeys, $aExisting);
        $oValidator = new ValidatorWithStub($oService);

        return [$oValidator, $oService];
    }

    // --------------------------------------------------------------------------
    //  validateTemplate()
    // --------------------------------------------------------------------------

    public function test_the_stock_template_is_usable(): void
    {
        Config::set('APP_NATIVE_LOGIN_USING', 'BOTH');

        $this->expectNotToPerformAssertions();
        $this->oValidator->validateTemplate();
    }

    // --------------------------------------------------------------------------

    /**
     * The template is the app's to amend, so it can remove the very column an
     * account is identified by. Left unguarded that is not noticed until every
     * row fails at Model\User::create().
     */
    public function test_a_template_without_the_identity_key_is_refused(): void
    {
        Config::set('APP_NATIVE_LOGIN_USING', 'EMAIL');

        [$oValidator] = $this->withTemplate(
            aKeys: ['username', 'first_name'],
            aUniqueKeys: ['username']
        );

        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('getKeys()');

        $oValidator->validateTemplate();
    }

    // --------------------------------------------------------------------------

    public function test_a_template_which_does_not_hold_the_identity_key_unique_is_refused(): void
    {
        Config::set('APP_NATIVE_LOGIN_USING', 'EMAIL');

        [$oValidator] = $this->withTemplate(
            aKeys: ['email', 'first_name'],
            aUniqueKeys: []
        );

        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('getUniqueKeys()');

        $oValidator->validateTemplate();
    }

    // --------------------------------------------------------------------------

    /**
     * Removing username is legitimate when nobody logs in with it; the guard
     * must not forbid customisation, only breakage.
     */
    public function test_a_template_may_drop_a_key_the_login_mode_does_not_need(): void
    {
        Config::set('APP_NATIVE_LOGIN_USING', 'EMAIL');

        [$oValidator] = $this->withTemplate(
            aKeys: ['email', 'first_name'],
            aUniqueKeys: ['email']
        );

        $this->expectNotToPerformAssertions();
        $oValidator->validateTemplate();
    }

    // --------------------------------------------------------------------------

    public function test_both_identity_keys_are_required_of_the_template_when_logging_in_with_either(): void
    {
        Config::set('APP_NATIVE_LOGIN_USING', 'BOTH');

        [$oValidator] = $this->withTemplate(
            aKeys: ['email', 'first_name'],
            aUniqueKeys: ['email']
        );

        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('username');

        $oValidator->validateTemplate();
    }

    // --------------------------------------------------------------------------
    //  detectRegistered()
    // --------------------------------------------------------------------------

    public function test_registered_values_are_reported_against_their_line(): void
    {
        [$oValidator] = $this->withTemplate(
            aExisting: ['email' => ['a.ahmad6@nhs.net', 'a.botros@nhs.net']]
        );

        self::assertSame(
            [
                2 => ['email: "a.ahmad6@nhs.net" is already registered'],
                3 => ['email: "a.botros@nhs.net" is already registered'],
            ],
            $oValidator->detectRegistered(
                ['email', 'first_name'],
                [
                    2 => ['a.ahmad6@nhs.net', 'Ahmad'],
                    3 => ['a.botros@nhs.net', 'Botros'],
                    4 => ['new@example.com', 'New'],
                ]
            )
        );
    }

    // --------------------------------------------------------------------------

    public function test_registration_is_matched_regardless_of_case_or_padding(): void
    {
        [$oValidator] = $this->withTemplate(
            aExisting: ['email' => ['ada@example.com']]
        );

        self::assertArrayHasKey(
            2,
            $oValidator->detectRegistered(['email'], [2 => ['  ADA@Example.com  ']])
        );
    }

    // --------------------------------------------------------------------------

    public function test_a_blank_value_is_not_looked_up(): void
    {
        [$oValidator, $oService] = $this->withTemplate(
            aExisting: ['email' => ['ada@example.com']]
        );

        self::assertSame([], $oValidator->detectRegistered(['email'], [2 => [''], 3 => ['   ']]));
        self::assertSame([[]], array_column($oService->aLookups, 'values'));
    }

    // --------------------------------------------------------------------------

    /**
     * The whole point of the pass: one lookup per key, not one per row. The
     * closures this replaced cost a query - two, on a hit - every line.
     */
    public function test_one_lookup_is_made_per_key_however_many_rows(): void
    {
        [$oValidator, $oService] = $this->withTemplate(
            aUniqueKeys: ['email', 'username'],
            aExisting: ['email' => ['ada@example.com']]
        );

        $aRows = [];
        for ($i = 2; $i <= 500; $i++) {
            $aRows[$i] = [sprintf('user%d@example.com', $i), sprintf('user%d', $i)];
        }

        $oValidator->detectRegistered(['email', 'username'], $aRows);

        self::assertSame(['email', 'username'], array_column($oService->aLookups, 'key'));
    }

    // --------------------------------------------------------------------------

    public function test_nothing_is_looked_up_when_no_unique_column_is_present(): void
    {
        [$oValidator, $oService] = $this->withTemplate();

        self::assertSame([], $oValidator->detectRegistered(['first_name'], [2 => ['Ada']]));
        self::assertSame([], $oService->aLookups);
    }

    // --------------------------------------------------------------------------

    /**
     * A value may recur inside a chunk even though the upload's duplicate check
     * would reject it across the whole file, and both lines must be reported.
     */
    public function test_a_value_repeated_within_the_rows_is_reported_on_every_line(): void
    {
        [$oValidator] = $this->withTemplate(
            aExisting: ['email' => ['ada@example.com']]
        );

        self::assertSame(
            [2, 7],
            array_keys($oValidator->detectRegistered(
                ['email'],
                [2 => ['ada@example.com'], 5 => ['new@example.com'], 7 => ['ada@example.com']]
            ))
        );
    }

    // --------------------------------------------------------------------------

    /**
     * A uniqueness check which quietly answers "none of them" is worse than one
     * which fails, so an app adding a unique key must say where it lives.
     */
    public function test_a_unique_key_which_cannot_be_looked_up_is_refused(): void
    {
        $oService               = new ImportServiceStub(null, ['staff_number']);
        $oService->aUnsupported = ['staff_number'];
        $oValidator             = new ValidatorWithStub($oService);

        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('staff_number');

        $oValidator->detectRegistered(['staff_number'], [2 => ['12345']]);
    }

    // --------------------------------------------------------------------------

    public function test_a_valid_header_is_accepted(): void
    {
        Config::set('APP_NATIVE_LOGIN_USING', 'EMAIL');

        $this->expectNotToPerformAssertions();
        $this->oValidator->validateHeader(['email', 'first_name']);
    }

    // --------------------------------------------------------------------------

    public function test_the_header_need_not_use_every_key(): void
    {
        Config::set('APP_NATIVE_LOGIN_USING', 'EMAIL');

        $this->expectNotToPerformAssertions();
        $this->oValidator->validateHeader(['email']);
    }

    // --------------------------------------------------------------------------

    /**
     * A header may omit almost anything, but not the column an account is
     * identified by - Model\User::create() cannot make an account without it, so
     * such a file used to fail once per row instead of once.
     */
    public function test_a_header_missing_the_identity_column_is_rejected(): void
    {
        Config::set('APP_NATIVE_LOGIN_USING', 'EMAIL');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('email');

        $this->oValidator->validateHeader(['first_name', 'last_name']);
    }

    // --------------------------------------------------------------------------

    public function test_the_identity_column_follows_the_login_mode(): void
    {
        Config::set('APP_NATIVE_LOGIN_USING', 'USERNAME');

        //  Username alone is enough...
        $this->oValidator->validateHeader(['username']);

        //  ...and email alone is not
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('username');

        $this->oValidator->validateHeader(['email']);
    }

    // --------------------------------------------------------------------------

    /**
     * BOTH means both, matching the else branch of Model\User::create(); an
     * unset config falls here too.
     */
    public function test_both_identity_columns_are_required_when_logging_in_with_either(): void
    {
        Config::set('APP_NATIVE_LOGIN_USING', 'BOTH');

        $this->oValidator->validateHeader(['email', 'username']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('username');

        $this->oValidator->validateHeader(['email']);
    }

    // --------------------------------------------------------------------------

    public function test_a_missing_header_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Missing header row');

        $this->oValidator->validateHeader([]);
    }

    // --------------------------------------------------------------------------

    public function test_an_unknown_column_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Header row contains the following invalid values: shoe_size');

        $this->oValidator->validateHeader(['email', 'shoe_size']);
    }

    // --------------------------------------------------------------------------

    public function test_duplicates_are_detected(): void
    {
        $sPath = $this->write(implode("\n", [
            'email,first_name',
            'a@example.com,Ada',
            'b@example.com,Grace',
            'a@example.com,Anita',
            '',
        ]));

        self::assertSame(
            ['Line 4: email: "a@example.com" must only appear once; it is also on line 2'],
            $this->oValidator->detectDuplicates($sPath)
        );
    }

    // --------------------------------------------------------------------------

    public function test_duplicate_detection_is_case_insensitive(): void
    {
        $sPath = $this->write("email\na@example.com\nA@EXAMPLE.COM\n");

        self::assertCount(1, $this->oValidator->detectDuplicates($sPath));
    }

    // --------------------------------------------------------------------------

    public function test_duplicate_detection_covers_every_unique_key(): void
    {
        $sPath = $this->write(implode("\n", [
            'email,username',
            'a@example.com,ada',
            'b@example.com,ada',
            'b@example.com,grace',
            '',
        ]));

        self::assertSame(
            [
                //  Reported in line order, not grouped by key
                'Line 3: username: "ada" must only appear once; it is also on line 2',
                'Line 4: email: "b@example.com" must only appear once; it is also on line 3',
            ],
            $this->oValidator->detectDuplicates($sPath)
        );
    }

    // --------------------------------------------------------------------------

    public function test_blank_values_are_not_duplicates(): void
    {
        $sPath = $this->write("email,username\na@example.com,\nb@example.com,\n");

        self::assertSame([], $this->oValidator->detectDuplicates($sPath));
    }

    // --------------------------------------------------------------------------

    public function test_a_file_without_unique_columns_has_no_duplicates(): void
    {
        $sPath = $this->write("first_name\nAda\nAda\n");

        self::assertSame([], $this->oValidator->detectDuplicates($sPath));
    }

    // --------------------------------------------------------------------------

    public function test_a_clean_file_has_no_duplicates(): void
    {
        $sPath = $this->write("email\na@example.com\nb@example.com\n");

        self::assertSame([], $this->oValidator->detectDuplicates($sPath));
    }
    // --------------------------------------------------------------------------

    public function test_form_validation_is_available_without_codeigniter(): void
    {
        self::assertFalse(function_exists('get_instance'));
        self::assertInstanceOf(FormValidation::class, Factory::service('FormValidation'));
    }

    // --------------------------------------------------------------------------

    public function test_clean_rows_have_no_errors(): void
    {
        $aHeader = ['first_name', 'last_name', 'send_email', 'salutation', 'gender', 'dob'];
        $aRows   = [
            2 => ['Ada', 'Lovelace', '1', 'Ms', '', '1815-12-10'],
            3 => ['Grace', 'Hopper', '0', '', '', ''],
        ];

        self::assertSame([], $this->oValidator->validateRows($aHeader, $aRows));
    }

    // --------------------------------------------------------------------------

    public function test_each_error_class_is_reported_with_its_key_and_message(): void
    {
        $aHeader = ['send_email', 'salutation', 'last_name', 'dob'];
        $aRows   = [
            2 => ['maybe', str_repeat('x', 16), str_repeat('y', 151), '2020-13-45'],
        ];

        self::assertSame(
            [
                2 => [
                    'send_email: This field must be a boolean',
                    'salutation: This field is too long, maximum length is 15 characters.',
                    'last_name: This field is too long, maximum length is 150 characters.',
                    'dob: This field must be a valid date.',
                ],
            ],
            $this->oValidator->validateRows($aHeader, $aRows)
        );
    }

    // --------------------------------------------------------------------------

    public function test_required_fields_fail_when_blank_but_optional_fields_do_not(): void
    {
        $aHeader = ['send_email', 'dob', 'salutation'];
        $aRows   = [2 => ['', '', '']];

        self::assertSame(
            [2 => ['send_email: This field is required.']],
            $this->oValidator->validateRows($aHeader, $aRows)
        );
    }

    // --------------------------------------------------------------------------

    public function test_only_the_first_failing_rule_per_field_is_reported(): void
    {
        //  send_email is required|is_bool; blank fails required first
        $aHeader = ['send_email'];
        $aRows   = [2 => ['']];

        self::assertSame(
            [2 => ['send_email: This field is required.']],
            $this->oValidator->validateRows($aHeader, $aRows)
        );
    }

    // --------------------------------------------------------------------------

    public function test_a_column_count_mismatch_is_reported_alongside_field_errors(): void
    {
        $aHeader = ['send_email', 'dob'];
        $aRows   = [2 => ['x']];

        $aErrors = $this->oValidator->validateRows($aHeader, $aRows);

        self::assertSame(['Row has 1 columns, the header has 2', 'send_email: This field must be a boolean'], $aErrors[2]);
    }

    // --------------------------------------------------------------------------

    public function test_errors_are_keyed_by_line_number(): void
    {
        $aHeader = ['send_email'];
        $aRows   = [7 => ['1'], 9 => ['nope'], 12 => ['0']];

        self::assertSame([9], array_keys($this->oValidator->validateRows($aHeader, $aRows)));
    }

    // --------------------------------------------------------------------------

    public function test_errors_do_not_leak_between_rows(): void
    {
        $aHeader = ['send_email'];
        $aRows   = [2 => ['nope'], 3 => ['1']];

        self::assertSame([2], array_keys($this->oValidator->validateRows($aHeader, $aRows)));
    }

    // --------------------------------------------------------------------------

    public function test_the_gender_rule_uses_the_models_genders(): void
    {
        $aHeader = ['gender'];
        $aRows   = [2 => ['not-a-gender']];

        $aErrors = $this->oValidator->validateRows($aHeader, $aRows);

        self::assertCount(1, $aErrors[2]);
        self::assertStringStartsWith('gender: This field must be one of: ', $aErrors[2][0]);
    }
}

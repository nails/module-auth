<?php

namespace Tests\Auth\Service\User;

use Closure;
use Nails\Auth\Constants;
use Nails\Auth\Exception\User\Import\TemplateException;
use Nails\Auth\Resource;
use Nails\Auth\Service\User\Import;
use Nails\Common\Service\FormValidation;
use Nails\Config;
use Nails\Factory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Nails\Auth\Service\User\Import
 */
class ImportTest extends TestCase
{
    private Import $oService;

    private mixed $mLoginUsing = null;

    // --------------------------------------------------------------------------

    protected function setUp(): void
    {
        /** @var Import $oService */
        $oService       = Factory::service('UserImport', Constants::MODULE_SLUG);
        $this->oService = $oService;

        $this->mLoginUsing = Config::get('APP_NATIVE_LOGIN_USING');
    }

    // --------------------------------------------------------------------------

    protected function tearDown(): void
    {
        Config::set('APP_NATIVE_LOGIN_USING', $this->mLoginUsing);
    }

    // --------------------------------------------------------------------------

    /**
     * Counts the closures in a rule set; each one is a potential query per row
     *
     * @param array<int, string|Closure> $aRules
     */
    private function countClosures(array $aRules): int
    {
        return count(array_filter($aRules, fn($mRule): bool => $mRule instanceof Closure));
    }

    // --------------------------------------------------------------------------

    /**
     * Uniqueness used to be a closure calling getByEmail() for every row - a
     * query each, two on a hit. It is now batched; see whichExist().
     */
    public function test_the_email_rules_no_longer_query_per_row(): void
    {
        Config::set('APP_NATIVE_LOGIN_USING', 'EMAIL');

        $aRules = $this->oService->getValidationRules('email');

        self::assertSame(0, $this->countClosures($aRules));
        self::assertSame(
            [FormValidation::RULE_REQUIRED, FormValidation::RULE_VALID_EMAIL],
            array_values($aRules)
        );
    }

    // --------------------------------------------------------------------------

    /**
     * The format check remains, and now actually runs: the validator abandons a
     * field's remaining rules once one fails, so the uniqueness closure used to
     * short-circuit it.
     */
    public function test_the_username_rules_keep_only_the_format_check(): void
    {
        self::assertSame(1, $this->countClosures($this->oService->getValidationRules('username')));
    }

    // --------------------------------------------------------------------------

    public function test_the_identity_keys_are_unique_keys(): void
    {
        foreach (['email', 'username'] as $sKey) {
            self::assertContains($sKey, $this->oService->getKeys(), $sKey);
            self::assertContains($sKey, $this->oService->getUniqueKeys(), $sKey);
        }
    }

    // --------------------------------------------------------------------------

    public function test_nothing_is_looked_up_for_an_empty_value_list(): void
    {
        self::assertSame([], $this->oService->whichExist('email', []));
        self::assertSame([], $this->oService->whichExist('username', ['   ', '']));
    }

    // --------------------------------------------------------------------------

    /**
     * An app which adds a unique key must say where the value lives; answering
     * "none of them" would let a duplicate through while appearing to check.
     * Refused even with nothing to look up, so the misconfiguration cannot hide
     * behind an empty file.
     */
    public function test_an_unsupported_unique_key_is_refused(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('staff_number');

        $this->oService->whichExist('staff_number', []);
    }

    // --------------------------------------------------------------------------
    //  Lifecycle hooks
    // --------------------------------------------------------------------------

    /**
     * Builds a job to hand the hooks
     */
    private function makeImport(): Resource\User\Import
    {
        return new Resource\User\Import((object) [
            'id'              => 6,
            'object_id'       => 2,
            'log_id'          => null,
            'additional'      => '{"cohort":"2026"}',
            'status'          => 'RUNNING',
            'runner'          => 'CRON',
            'claim_token'     => null,
            'claimed'         => null,
            'error'           => null,
            'row_count'       => 3,
            'validated_count' => 3,
            'processed_count' => 0,
            'success_count'   => 0,
            'error_count'     => 0,
            'started'         => null,
            'finished'        => null,
        ]);
    }

    // --------------------------------------------------------------------------

    /**
     * Every hook ships as a no-op, so an app which overrides one of them changes
     * nothing else about how a job runs.
     */
    public function test_the_lifecycle_hooks_do_nothing_by_default(): void
    {
        $oImport = $this->makeImport();
        $oUser   = new Resource\User((object) ['id' => 42, 'email' => 'ada@example.com']);

        $aBefore = (array) $oImport;

        //  None of them throws, and none of them touches the job it was given
        $this->oService->onImportStart($oImport);
        $this->oService->afterUserCreate($oUser, ['email' => 'ada@example.com'], $oImport);
        $this->oService->onImportComplete($oImport);
        $this->oService->onImportFailed($oImport);

        self::assertEquals($aBefore, (array) $oImport);
    }

    // --------------------------------------------------------------------------

    /**
     * The guard against a future edit giving this an opinion: whatever the
     * processor assembled is what reaches Model\User::create(), untouched.
     */
    public function test_the_data_hook_returns_its_input_unchanged(): void
    {
        $aUserData = [
            'email'      => 'ada@example.com',
            'first_name' => 'Ada',
            'group_id'   => 3,
        ];

        self::assertSame(
            $aUserData,
            $this->oService->prepareUserData(
                $aUserData,
                $this->makeImport(),
                ['email' => 'ada@example.com', 'staff_number' => 'A1234']
            )
        );
    }
}

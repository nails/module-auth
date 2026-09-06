<?php

namespace Tests\Auth\Service\User\Import;

use Nails\Auth\Enum\User\Import\ItemStatus;
use Nails\Auth\Enum\User\Import\Status;
use Nails\Auth\Exception\User\Import\LogException;
use Nails\Auth\Resource;
use Nails\Auth\Model\User\Import as ImportModel;
use Nails\Auth\Service\User\Import\Processor;
use Nails\Common\Service\Logger;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Tests\Auth\Stub\CdnSpy;
use Tests\Auth\Stub\ImportModelRecorder;
use Tests\Auth\Stub\ImportServiceStub;
use Tests\Auth\Stub\LoggerSpy;
use Tests\Auth\Stub\UserModelStub;
use Tests\Auth\Stub\ProcessorWithSpies;

/**
 * @covers \Nails\Auth\Service\User\Import\Processor
 */
class ProcessorTest extends TestCase
{
    /**
     * Temporary CSVs written by a test, removed afterwards
     *
     * @var string[]
     */
    private array $aPaths = [];

    private ImportModelRecorder $oModel;
    private CdnSpy $oCdn;
    private LoggerSpy $oLogger;
    private ProcessorWithSpies $oProcessor;

    // --------------------------------------------------------------------------

    protected function setUp(): void
    {
        $this->oModel     = new ImportModelRecorder();
        $this->oCdn       = new CdnSpy();
        $this->oLogger    = new LoggerSpy();
        $this->oProcessor = new ProcessorWithSpies($this->oModel, $this->oCdn, $this->oLogger);
    }

    // --------------------------------------------------------------------------

    protected function tearDown(): void
    {
        foreach ($this->aPaths as $sPath) {
            if (is_file($sPath)) {
                unlink($sPath);
            }
        }

        $this->aPaths = [];
    }

    // --------------------------------------------------------------------------

    /**
     * Builds a job from the columns the model would supply
     *
     * created_by is left empty on purpose: notify() returns early without one,
     * which keeps the email machinery out of these tests.
     */
    private function make(array $aOverrides = []): Resource\User\Import
    {
        return new Resource\User\Import((object) array_merge([
            'id'              => 6,
            'object_id'       => 20102,
            'log_id'          => null,
            'additional'      => '{}',
            'status'          => 'VALIDATING',
            'runner'          => 'CRON',
            'claim_token'     => null,
            'claimed'         => null,
            'error'           => null,
            'row_count'       => 1362,
            'validated_count' => 1362,
            'processed_count' => 0,
            'success_count'   => 0,
            'error_count'     => 2,
            'started'         => null,
            'finished'        => null,
            'created_by'      => null,
        ], $aOverrides));
    }

    // --------------------------------------------------------------------------

    /**
     * Writes a CSV for the log builder to read the source rows back out of
     */
    private function makeCsv(int $iRows = 3): string
    {
        $sPath          = tempnam(sys_get_temp_dir(), 'nails-processor-test-');
        $this->aPaths[] = $sPath;

        $aLines = ['email,first_name,last_name'];
        for ($i = 1; $i <= $iRows; $i++) {
            $aLines[] = sprintf('user%d@example.com,First%d,Last%d', $i, $i, $i);
        }

        file_put_contents($sPath, implode("\n", $aLines) . "\n");

        return $sPath;
    }

    // --------------------------------------------------------------------------

    /**
     * The two rows import #6 actually failed on
     */
    private function importSixSample(): array
    {
        return [
            ['line' => 2, 'message' => 'email: "a.ahmad6@nhs.net" is already registered'],
            ['line' => 3, 'message' => 'email: "a.botros@nhs.net" is already registered'],
        ];
    }

    // --------------------------------------------------------------------------

    /**
     * A row whose account exists but whose after-create work did not happen
     */
    private function importSixWarningSample(): array
    {
        return [
            ['line' => 4, 'message' => 'The account was created, but the subscription could not be started'],
        ];
    }

    // --------------------------------------------------------------------------

    /**
     * Writes a CSV which the real validator will pass
     *
     * The header carries every column an account can be identified by, whichever
     * APP_NATIVE_LOGIN_USING is configured, plus the one other column the
     * template requires; without all three, validate() rejects the file before
     * it ever reaches onImportStart().
     */
    private function makeValidCsv(int $iRows = 1): string
    {
        $sPath          = tempnam(sys_get_temp_dir(), 'nails-processor-test-');
        $this->aPaths[] = $sPath;

        $aLines = ['email,username,send_email'];
        for ($i = 1; $i <= $iRows; $i++) {
            $aLines[] = sprintf('user%d@example.com,user_%d,0', $i, $i);
        }

        file_put_contents($sPath, implode("\n", $aLines) . "\n");

        return $sPath;
    }

    // --------------------------------------------------------------------------
    //  The seams
    // --------------------------------------------------------------------------

    /**
     * The seams are trivial, but a mistake in one is invisible to every other
     * test in here - they all override them - so they are exercised against the
     * real class.
     */
    public function test_the_seams_hand_back_the_real_services(): void
    {
        $oProcessor = new Processor();
        $oClass     = new ReflectionClass($oProcessor);

        /**
         * getCdn() is left out: the real CDN service wants a cache directory
         * this module does not have on its own, which is the same reason CdnSpy
         * replaces its constructor.
         */
        $aSeams = [
            'getModel'  => ImportModel::class,
            'getLogger' => Logger::class,
        ];

        foreach ($aSeams as $sMethod => $sExpected) {
            self::assertInstanceOf(
                $sExpected,
                $oClass->getMethod($sMethod)->invoke($oProcessor),
                $sMethod . '() should return a ' . $sExpected
            );
        }
    }

    // --------------------------------------------------------------------------
    //  Storing the log
    // --------------------------------------------------------------------------

    /**
     * The regression test for import #6.
     *
     * Cdn::objectCreate() hands back a plain stdClass, not a
     * Cdn\Resource\CdnObject; uploadLog() used to declare the resource as its
     * return type, so the TypeError on the way out was swallowed by fail()'s
     * empty catch and the log was silently never attached.
     */
    public function test_the_shape_the_cdn_actually_returns_is_accepted(): void
    {
        $this->oProcessor->sSourcePath = $this->makeCsv();
        $this->oCdn->mObjectCreateReturn = (object) ['id' => '20103'];

        self::assertSame(
            20103,
            $this->oProcessor->exposeUploadLog($this->make(), [])
        );
    }

    // --------------------------------------------------------------------------

    public function test_the_log_is_stored_in_the_import_bucket_as_a_csv(): void
    {
        $this->oProcessor->sSourcePath = $this->makeCsv();

        $this->oProcessor->exposeUploadLog($this->make(), []);

        self::assertCount(1, $this->oCdn->aCreated);

        $aCreated = $this->oCdn->aCreated[0];
        self::assertSame(Processor::IMPORT_BUCKET, $aCreated['bucket']);
        self::assertSame('text/csv', $aCreated['options']['Content-Type']);
        self::assertTrue($aCreated['options']['no-md5-check']);
        self::assertStringEndsWith('.csv', $aCreated['options']['filename_display']);
    }

    // --------------------------------------------------------------------------

    public function test_a_cdn_which_cannot_store_the_log_reports_its_reason(): void
    {
        $this->oProcessor->sSourcePath   = $this->makeCsv();
        $this->oCdn->mObjectCreateReturn = false;
        $this->oCdn->sObjectCreateError  = 'The file is too large, maximum file size is 1 B';

        $this->expectException(LogException::class);
        $this->expectExceptionMessage('The file is too large, maximum file size is 1 B');

        $this->oProcessor->exposeUploadLog($this->make(), []);
    }

    // --------------------------------------------------------------------------

    /**
     * lastError() returns false, not '', for an empty error stack - so without a
     * fallback a silent CDN failure would produce a message which trails off.
     */
    public function test_a_cdn_failure_with_no_reason_still_says_something(): void
    {
        $this->oProcessor->sSourcePath   = $this->makeCsv();
        $this->oCdn->mObjectCreateReturn = false;

        $this->expectException(LogException::class);
        $this->expectExceptionMessage('no reason was reported');

        $this->oProcessor->exposeUploadLog($this->make(), []);
    }

    // --------------------------------------------------------------------------

    public function test_a_log_which_cannot_be_built_is_reported_as_such(): void
    {
        //  Never written, so the log builder cannot read the source rows
        $this->oProcessor->sSourcePath = '/nonexistent/user-import.csv';

        $this->expectException(LogException::class);
        $this->expectExceptionMessage('Failed to build the import log');

        $this->oProcessor->exposeUploadLog($this->make(), []);
    }

    // --------------------------------------------------------------------------

    public function test_a_log_which_cannot_be_attached_is_kept_hold_of_rather_than_swallowed(): void
    {
        $this->oProcessor->sSourcePath   = $this->makeCsv();
        $this->oProcessor->aSample       = $this->importSixSample();
        $this->oCdn->mObjectCreateReturn = false;
        $this->oCdn->sObjectCreateError  = 'Failed to create object on storage service';

        $aLog = $this->oProcessor->exposeAttachLog($this->make(['log_id' => 999]), [ItemStatus::ERROR]);

        //  The reason comes back to the caller...
        self::assertStringContainsString('Failed to create object on storage service', $aLog['error']);

        //  ...it is written to the application log...
        self::assertCount(1, $this->oLogger->aErrors);
        self::assertStringContainsString('User import #6', $this->oLogger->aErrors[0]);
        self::assertStringContainsString('failed to attach the log', $this->oLogger->aErrors[0]);

        //  ...and an existing log is not thrown away in the process
        self::assertSame(999, $aLog['log_id']);
    }

    // --------------------------------------------------------------------------
    //  fail()
    // --------------------------------------------------------------------------

    public function test_a_failure_records_the_reason_the_log_and_the_status(): void
    {
        $this->oProcessor->sSourcePath = $this->makeCsv();
        $this->oProcessor->aSample     = $this->importSixSample();

        $this->oProcessor->exposeFail($this->make(), 'Something went wrong.');

        $aUpdate = $this->oModel->lastUpdate();

        self::assertSame(Status::FAILED->value, $aUpdate['status']);
        self::assertSame('Something went wrong.', $aUpdate['error']);
        self::assertSame(20103, $aUpdate['log_id']);
        self::assertNotEmpty($aUpdate['finished']);
    }

    // --------------------------------------------------------------------------

    public function test_a_failure_notes_on_itself_when_the_log_could_not_be_attached(): void
    {
        $this->oProcessor->sSourcePath   = $this->makeCsv();
        $this->oProcessor->aSample       = $this->importSixSample();
        $this->oCdn->mObjectCreateReturn = false;
        $this->oCdn->sObjectCreateError  = 'The CDN is unreachable';

        $this->oProcessor->exposeFail($this->make(), 'Something went wrong.');

        $sError = $this->oModel->lastUpdate()['error'];

        self::assertStringContainsString('Something went wrong.', $sError);
        self::assertStringContainsString('The error log could not be attached', $sError);
        self::assertStringContainsString('The CDN is unreachable', $sError);
    }

    // --------------------------------------------------------------------------

    /**
     * A job with nothing wrong per-row has no error log to build, so the CDN
     * should not be troubled for one.
     */
    public function test_a_failure_with_no_failing_rows_does_not_build_a_log(): void
    {
        $this->oProcessor->exposeFail($this->make(['error_count' => 0]), 'The CSV had no header.');

        self::assertSame([], $this->oCdn->aCreated);
        self::assertNull($this->oModel->lastUpdate()['log_id']);
    }

    // --------------------------------------------------------------------------

    /**
     * Throwing here would re-enter process()'s catch and recurse back into
     * fail(), so a failed write is reported and left alone.
     */
    public function test_a_failure_which_cannot_be_recorded_is_reported_not_thrown(): void
    {
        $this->oModel->bUpdateReturn = false;
        $this->oModel->sUpdateError  = 'Deadlock found when trying to get lock';

        $this->oProcessor->exposeFail($this->make(['error_count' => 0]), 'Something went wrong.');

        self::assertStringContainsString(
            'Deadlock found when trying to get lock',
            implode("\n", $this->oLogger->aErrors)
        );
    }

    // --------------------------------------------------------------------------
    //  complete()
    // --------------------------------------------------------------------------

    /**
     * complete() used to call the log upload with no try/catch at all, so a log
     * failure reached process()'s catch and re-branded a perfectly good import
     * as FAILED - after the accounts had been created.
     */
    public function test_a_log_failure_does_not_turn_a_finished_import_into_a_failed_one(): void
    {
        $this->oProcessor->sSourcePath   = $this->makeCsv();
        $this->oCdn->mObjectCreateReturn = false;
        $this->oCdn->sObjectCreateError  = 'The CDN is unreachable';

        $oImport = $this->make([
            'status'          => 'RUNNING',
            'processed_count' => 1362,
            'success_count'   => 1362,
            'error_count'     => 0,
            'log_id'          => 555,
        ]);

        $this->oProcessor->exposeComplete($oImport);

        $aUpdate = $this->oModel->lastUpdate();

        self::assertSame(Status::COMPLETE->value, $aUpdate['status']);

        //  An existing log must not be nulled by a failed upload
        self::assertSame(555, $aUpdate['log_id']);

        //  ...and the reason has to be visible somewhere
        self::assertStringContainsString('The error log could not be attached', $aUpdate['error']);
        self::assertStringContainsString('The CDN is unreachable', $aUpdate['error']);
    }

    // --------------------------------------------------------------------------

    public function test_an_import_with_failing_rows_completes_as_partial(): void
    {
        $this->oProcessor->sSourcePath = $this->makeCsv();
        $this->oProcessor->aSample     = $this->importSixSample();

        $this->oProcessor->exposeComplete($this->make([
            'status'          => 'RUNNING',
            'processed_count' => 1362,
            'success_count'   => 1360,
            'error_count'     => 2,
        ]));

        $aUpdate = $this->oModel->lastUpdate();

        self::assertSame(Status::PARTIAL->value, $aUpdate['status']);
        self::assertNull($aUpdate['error']);
        self::assertSame(20103, $aUpdate['log_id']);
    }

    // --------------------------------------------------------------------------
    //  begin()
    // --------------------------------------------------------------------------

    /**
     * The unique key on (import_id, line) would otherwise make every line of a
     * restarted job look as though it had already been handled, and the counts,
     * which are re-derived rather than incremented, would come from the run
     * before.
     */
    public function test_restarting_a_job_clears_the_previous_attempt(): void
    {
        $this->oProcessor->sSourcePath = $this->makeCsv();

        $oImport = $this->make([
            'status'          => 'PENDING',
            'log_id'          => 20103,
            'validated_count' => 1362,
            'error_count'     => 2,
        ]);

        $this->oProcessor->exposeBegin($oImport);

        //  The previous run's items go...
        self::assertSame([6], $this->oModel->aItemsDeleted);

        //  ...as does the log which described them...
        self::assertSame([20103], $this->oCdn->aDestroyed);

        //  ...and the job is reset to the top
        $aUpdate = $this->oModel->lastUpdate();
        self::assertNull($aUpdate['log_id']);
        self::assertNull($aUpdate['error']);
        self::assertSame(0, $aUpdate['validated_count']);
        self::assertSame(0, $aUpdate['error_count']);
        self::assertSame(Status::VALIDATING->value, $aUpdate['status']);
        self::assertSame(3, $aUpdate['row_count']);
    }

    // --------------------------------------------------------------------------

    public function test_a_previous_log_which_cannot_be_destroyed_does_not_stop_the_restart(): void
    {
        $this->oProcessor->sSourcePath = $this->makeCsv();
        $this->oCdn->aFailures         = [20103 => 'Object does not exist'];

        $this->oProcessor->exposeBegin($this->make(['status' => 'PENDING', 'log_id' => 20103]));

        self::assertSame(Status::VALIDATING->value, $this->oModel->lastUpdate()['status']);
        self::assertStringContainsString(
            'Object does not exist',
            implode("\n", $this->oLogger->aWarnings)
        );
    }

    // --------------------------------------------------------------------------
    //  Composing the error
    // --------------------------------------------------------------------------

    public function test_a_validation_failure_names_the_rows_which_failed(): void
    {
        $this->oProcessor->aSample = $this->importSixSample();

        $sError = $this->oProcessor->exposeComposeValidationError($this->make());

        self::assertStringContainsString('2 rows in the CSV could not be validated', $sError);
        self::assertStringContainsString('no user accounts were created', $sError);
        self::assertStringContainsString('validating the CSV (1,362 of 1,362 rows checked)', $sError);
        self::assertStringContainsString('Line 2: email: "a.ahmad6@nhs.net" is already registered', $sError);
        self::assertStringContainsString('Line 3: email: "a.botros@nhs.net" is already registered', $sError);
    }

    // --------------------------------------------------------------------------

    public function test_a_single_failing_row_is_worded_in_the_singular(): void
    {
        $this->oProcessor->aSample = [['line' => 2, 'message' => 'email: is already registered']];

        self::assertStringContainsString(
            '1 row in the CSV could not be validated',
            $this->oProcessor->exposeComposeValidationError($this->make(['error_count' => 1]))
        );
    }

    // --------------------------------------------------------------------------

    /**
     * The symptom which made import #6's error useless: rows failed, but every
     * message was empty, so there was nothing to read either way.
     */
    public function test_a_row_with_no_recorded_reason_is_still_listed(): void
    {
        $this->oProcessor->aSample = [
            ['line' => 2, 'message' => null],
            ['line' => 3, 'message' => ''],
        ];

        $sError = $this->oProcessor->exposeComposeValidationError($this->make());

        self::assertStringContainsString('Line 2: (no reason was recorded)', $sError);
        self::assertStringContainsString('Line 3: (no reason was recorded)', $sError);
    }

    // --------------------------------------------------------------------------

    public function test_only_a_sample_of_a_large_failure_is_quoted(): void
    {
        $aSample = [];
        for ($i = 2; $i <= 200; $i++) {
            $aSample[] = ['line' => $i, 'message' => 'email: is already registered'];
        }

        $this->oProcessor->aSample = $aSample;

        $sError = $this->oProcessor->exposeComposeValidationError($this->make(['error_count' => 199]));

        self::assertStringContainsString(
            sprintf('showing the first %s of 199', Processor::ERROR_SAMPLE_SIZE),
            $sError
        );

        //  One header line plus the sample, and nothing beyond it
        self::assertStringContainsString('Line 11: ', $sError);
        self::assertStringNotContainsString('Line 12: ', $sError);
    }

    // --------------------------------------------------------------------------

    public function test_an_unexpected_failure_names_the_exception_and_where_it_came_from(): void
    {
        $e = new RuntimeException('Something broke');

        $sError = $this->oProcessor->exposeComposeThrowableError($this->make(), $e);

        self::assertStringContainsString('The import stopped unexpectedly', $sError);
        self::assertStringContainsString('Cause: RuntimeException - Something broke', $sError);
        self::assertStringContainsString('Where: ProcessorTest.php line ' . $e->getLine(), $sError);
        self::assertStringContainsString('written to the application log', $sError);

        //  A container's absolute paths are noise in an admin's inbox
        self::assertStringNotContainsString(dirname($e->getFile()), $sError);
    }

    // --------------------------------------------------------------------------
    //  summariseError()
    // --------------------------------------------------------------------------

    /**
     * The notification is an FYI which lands in an inbox, and the quoted rows
     * repeat cell values out of the CSV. The reader is told what happened and
     * sent to the log, which is behind a permission check, for the rest.
     */
    public function test_the_emailed_error_keeps_the_summary_and_the_phase(): void
    {
        $this->oProcessor->aSample = $this->importSixSample();

        $sSummary = $this->oProcessor->exposeSummariseError(
            $this->oProcessor->exposeComposeValidationError($this->make(['error_count' => 2]))
        );

        self::assertStringContainsString('could not be validated', $sSummary);
        self::assertStringContainsString('Phase: validating the CSV', $sSummary);
    }

    // --------------------------------------------------------------------------

    public function test_the_emailed_error_drops_the_rows_it_quoted(): void
    {
        $this->oProcessor->aSample = [
            ['line' => 2, 'message' => 'email: "ada@example.com" is already registered'],
        ];

        $sSummary = $this->oProcessor->exposeSummariseError(
            $this->oProcessor->exposeComposeValidationError($this->make(['error_count' => 1]))
        );

        self::assertStringNotContainsString('ada@example.com', $sSummary);
        self::assertStringNotContainsString('Rows with errors', $sSummary);
        self::assertStringNotContainsString('Line 2', $sSummary);
    }

    // --------------------------------------------------------------------------

    /**
     * A driver reporting a duplicate key repeats the value which collided, so
     * the cause can name somebody even when no row was quoted.
     */
    public function test_the_emailed_error_drops_the_cause_and_where_it_came_from(): void
    {
        $e = new RuntimeException('Duplicate entry \'ada@example.com\' for key \'email\'');

        $sSummary = $this->oProcessor->exposeSummariseError(
            $this->oProcessor->exposeComposeThrowableError($this->make(), $e)
        );

        self::assertStringContainsString('The import stopped unexpectedly', $sSummary);
        self::assertStringNotContainsString('ada@example.com', $sSummary);
        self::assertStringNotContainsString('Cause:', $sSummary);
        self::assertStringNotContainsString('Where:', $sSummary);
    }

    // --------------------------------------------------------------------------

    /**
     * complete() leaves the error unset on the happy path, and the template
     * renders the block only when there is something in it.
     */
    public function test_a_job_with_no_error_emails_no_error(): void
    {
        self::assertNull($this->oProcessor->exposeSummariseError(null));
        self::assertNull($this->oProcessor->exposeSummariseError(''));
    }

    // --------------------------------------------------------------------------

    /**
     * complete() writes a one-block error when the log could not be attached or
     * the app's hook objected; there is no phase line to keep.
     */
    public function test_a_single_block_error_survives_intact(): void
    {
        self::assertSame(
            'The error log could not be attached.',
            $this->oProcessor->exposeSummariseError('The error log could not be attached.')
        );
    }

    // --------------------------------------------------------------------------

    /**
     * validate()'s wording cannot be reused once run() has started: telling an
     * admin no accounts were created when some were is worse than saying nothing.
     */
    public function test_a_failure_after_accounts_were_created_does_not_claim_none_were(): void
    {
        $sError = $this->oProcessor->exposeComposeThrowableError(
            $this->make([
                'status'          => 'RUNNING',
                'processed_count' => 14,
                'success_count'   => 14,
                'error_count'     => 0,
            ]),
            new RuntimeException('Something broke')
        );

        self::assertStringContainsString('14 user accounts had already been created and have been kept', $sError);
        self::assertStringNotContainsString('no user accounts were created', $sError);
        self::assertStringContainsString('creating user accounts (14 of 1,362 rows processed)', $sError);
    }

    // --------------------------------------------------------------------------

    public function test_a_stalled_job_records_why_it_was_abandoned(): void
    {
        $this->oProcessor->aSample = $this->importSixSample();

        $sError = $this->oProcessor->exposeComposeStallError($this->make(), 'no progress was made');

        self::assertStringContainsString('made no progress and was stopped', $sError);
        self::assertStringContainsString('Cause: no progress was made', $sError);
    }

    // --------------------------------------------------------------------------

    public function test_the_summary_is_the_first_line(): void
    {
        $this->oProcessor->aSample = $this->importSixSample();

        $sError = $this->oProcessor->exposeComposeValidationError($this->make());

        self::assertSame(
            '2 rows in the CSV could not be validated, so no user accounts were created. Correct them and upload the file again.',
            $this->oProcessor->exposeFirstLine($sError)
        );
    }

    // --------------------------------------------------------------------------

    public function test_a_pathological_error_is_bounded(): void
    {
        $sError = $this->oProcessor->exposeTruncateError(str_repeat('a', Processor::ERROR_MAX_LENGTH * 2));

        self::assertSame(Processor::ERROR_MAX_LENGTH, mb_strlen(rtrim($sError, '…')));
        self::assertStringEndsWith('…', $sError);
    }

    // --------------------------------------------------------------------------

    /**
     * The error quotes cell values from the CSV, so a byte-wise cut would leave
     * invalid UTF-8 in the column.
     */
    public function test_truncation_does_not_split_a_character(): void
    {
        $sError = $this->oProcessor->exposeTruncateError(str_repeat('é', Processor::ERROR_MAX_LENGTH * 2));

        self::assertSame($sError, mb_convert_encoding($sError, 'UTF-8', 'UTF-8'));
        self::assertSame(Processor::ERROR_MAX_LENGTH, mb_strlen(rtrim($sError, '…')));
    }

    // --------------------------------------------------------------------------

    public function test_a_short_error_is_left_alone(): void
    {
        self::assertSame('Something went wrong.', $this->oProcessor->exposeTruncateError('Something went wrong.'));
    }

    // --------------------------------------------------------------------------
    //  The technical payload
    // --------------------------------------------------------------------------

    /**
     * Mirrors the shape module-queue records for a failed job; see
     * Nails\Queue\Service\Manager::buildErrorPayload().
     */
    public function test_the_error_payload_carries_what_is_needed_to_diagnose(): void
    {
        $aPayload = $this->oProcessor->exposeErrorPayload(new RuntimeException('Something broke', 7));

        self::assertSame(
            ['type', 'message', 'code', 'file', 'line', 'trace', 'occurred_at'],
            array_keys($aPayload)
        );

        self::assertSame(RuntimeException::class, $aPayload['type']);
        self::assertSame('Something broke', $aPayload['message']);
        self::assertSame(7, $aPayload['code']);
        self::assertNotEmpty($aPayload['trace']);
    }

    // --------------------------------------------------------------------------

    public function test_a_long_trace_is_truncated(): void
    {
        $aPayload = $this->oProcessor->exposeErrorPayload(
            new RuntimeException(str_repeat('deeply nested ', 500))
        );

        self::assertLessThanOrEqual(
            Processor::ERROR_TRACE_MAX_LENGTH + 1,
            mb_strlen($aPayload['trace'])
        );
    }

    // --------------------------------------------------------------------------
    //  ItemStatus::WARNING
    // --------------------------------------------------------------------------

    /**
     * A row whose account was created but whose follow-up failed is neither a
     * success nor a failure. 500 silently failed subscriptions must not pass as
     * COMPLETE, which is the whole point of the status existing.
     */
    public function test_a_job_which_only_warned_finishes_partial(): void
    {
        $this->oProcessor->sSourcePath    = $this->makeCsv();
        $this->oProcessor->aWarningSample = $this->importSixWarningSample();

        $this->oProcessor->exposeComplete($this->make([
            'status'          => 'RUNNING',
            'processed_count' => 1362,
            'success_count'   => 1361,
            'warning_count'   => 1,
            'error_count'     => 0,
        ]));

        self::assertSame(Status::PARTIAL->value, $this->oModel->lastUpdate()['status']);
    }

    // --------------------------------------------------------------------------

    public function test_a_job_which_neither_errored_nor_warned_finishes_complete(): void
    {
        $this->oProcessor->sSourcePath = $this->makeCsv();

        $this->oProcessor->exposeComplete($this->make([
            'status'          => 'RUNNING',
            'processed_count' => 1362,
            'success_count'   => 1362,
            'warning_count'   => 0,
            'error_count'     => 0,
        ]));

        self::assertSame(Status::COMPLETE->value, $this->oModel->lastUpdate()['status']);
    }

    // --------------------------------------------------------------------------

    /**
     * The account exists; only the work which was meant to follow it does not.
     * Telling an admin fewer accounts were created than there are is the same
     * mistake as telling them none were.
     */
    public function test_a_warned_account_counts_among_those_created(): void
    {
        $sSurvivors = $this->oProcessor->exposeDescribeSurvivors($this->make([
            'status'          => 'RUNNING',
            'processed_count' => 14,
            'success_count'   => 12,
            'warning_count'   => 2,
            'error_count'     => 0,
        ]));

        self::assertSame('14 user accounts had already been created and have been kept.', $sSurvivors);
    }

    // --------------------------------------------------------------------------

    public function test_a_single_warned_account_is_worded_in_the_singular(): void
    {
        $sSurvivors = $this->oProcessor->exposeDescribeSurvivors($this->make([
            'status'          => 'RUNNING',
            'processed_count' => 1,
            'success_count'   => 0,
            'warning_count'   => 1,
            'error_count'     => 0,
        ]));

        self::assertSame('1 user account had already been created and has been kept.', $sSurvivors);
    }

    // --------------------------------------------------------------------------

    /**
     * fail() used to ask for the errors alone, so a job which warned and then
     * failed dropped the accounts which need attention from the only record
     * which names them.
     */
    public function test_a_failed_job_logs_its_warnings_alongside_its_errors(): void
    {
        $this->oProcessor->sSourcePath    = $this->makeCsv();
        $this->oProcessor->aSample        = $this->importSixSample();
        $this->oProcessor->aWarningSample = $this->importSixWarningSample();

        $this->oProcessor->exposeFail(
            $this->make(['status' => 'RUNNING', 'success_count' => 4, 'warning_count' => 1]),
            'Something went wrong.'
        );

        self::assertSame(
            [[ItemStatus::ERROR, ItemStatus::WARNING]],
            $this->oProcessor->aStreamed
        );
    }

    // --------------------------------------------------------------------------

    /**
     * A job with nothing to say about any of its rows still must not build a
     * log; the warning count is now part of that question.
     */
    public function test_a_failure_with_only_warnings_still_builds_a_log(): void
    {
        $this->oProcessor->sSourcePath    = $this->makeCsv();
        $this->oProcessor->aWarningSample = $this->importSixWarningSample();

        $this->oProcessor->exposeFail(
            $this->make(['status' => 'RUNNING', 'error_count' => 0, 'warning_count' => 1]),
            'Something went wrong.'
        );

        self::assertSame(20103, $this->oModel->lastUpdate()['log_id']);
    }

    // --------------------------------------------------------------------------

    public function test_the_log_carries_warnings_when_they_are_asked_for(): void
    {
        $this->oProcessor->aSample        = $this->importSixSample();
        $this->oProcessor->aWarningSample = $this->importSixWarningSample();

        $aItems = $this->oProcessor->exposeStreamItems(
            $this->make(),
            [ItemStatus::ERROR, ItemStatus::WARNING]
        );

        self::assertSame(
            [ItemStatus::ERROR->value, ItemStatus::ERROR->value, ItemStatus::WARNING->value],
            array_column($aItems, 'status')
        );

        //  ...and only the errors when only they are
        self::assertCount(
            2,
            $this->oProcessor->exposeStreamItems($this->make(), [ItemStatus::ERROR])
        );
    }

    // --------------------------------------------------------------------------
    //  Lifecycle hooks
    // --------------------------------------------------------------------------

    /**
     * Wires a hook onto the processor and hands back the service it is on
     */
    private function withHook(string $sHook, callable $cHook): ImportServiceStub
    {
        $oService                          = new ImportServiceStub();
        $oService->aHooks[$sHook]          = $cHook;
        $this->oProcessor->oImportService  = $oService;

        return $oService;
    }

    // --------------------------------------------------------------------------

    /**
     * The one hook which is allowed to refuse the whole job. It fires while
     * nothing has been created, so it is deliberately left unwrapped and reaches
     * process()'s catch - which means the admin gets the composed reason, with
     * the phase and the cause, rather than a bare exception message.
     */
    public function test_the_app_may_refuse_the_whole_job_before_anything_is_created(): void
    {
        $this->oProcessor->sSourcePath = $this->makeValidCsv();

        $oService = $this->withHook('onImportStart', function (): void {
            throw new RuntimeException('the cohort has not been set up yet');
        });

        $oImport = $this->make([
            'status'          => 'VALIDATING',
            'skip_registered' => true,
            'row_count'       => 1,
            'validated_count' => 0,
            'error_count'     => 0,
        ]);

        //  What validate() will read back after recording its progress
        $this->oModel->oNext = $this->make([
            'status'          => 'VALIDATING',
            'skip_registered' => true,
            'row_count'       => 1,
            'validated_count' => 1,
            'error_count'     => 0,
        ]);

        $this->oProcessor->process($oImport, 100);

        $aUpdate = $this->oModel->lastUpdate();

        self::assertSame(Status::FAILED->value, $aUpdate['status']);
        self::assertStringContainsString('The import stopped unexpectedly', $aUpdate['error']);
        self::assertStringContainsString('No user accounts were created.', $aUpdate['error']);
        self::assertStringContainsString('the cohort has not been set up yet', $aUpdate['error']);

        //  Refused before the job was ever handed to run()
        self::assertNotContains(
            Status::RUNNING->value,
            array_column(array_column($this->oModel->aUpdates, 'data'), 'status')
        );

        self::assertSame(['onImportStart', 'onImportFailed'], $oService->calledHooks());
    }

    // --------------------------------------------------------------------------

    /**
     * By the time complete() runs, every account it was going to create exists,
     * so a hook which objects is a note against a finished import - not a
     * failed one.
     */
    public function test_a_hook_which_objects_to_a_finished_job_cannot_re_brand_it(): void
    {
        $this->oProcessor->sSourcePath = $this->makeCsv();

        $this->withHook('onImportComplete', function (): void {
            throw new RuntimeException('the cohort roll-up could not be rebuilt');
        });

        $this->oProcessor->exposeComplete($this->make([
            'status'          => 'RUNNING',
            'processed_count' => 1362,
            'success_count'   => 1362,
            'warning_count'   => 0,
            'error_count'     => 0,
        ]));

        $aStatuses = array_column(array_column($this->oModel->aUpdates, 'data'), 'status');
        self::assertSame([Status::COMPLETE->value], array_values(array_filter($aStatuses)));

        //  ...and the reason is on the job, where the modal and the email read it
        $aUpdate = $this->oModel->lastUpdate();
        self::assertSame(['error'], array_keys($aUpdate));
        self::assertStringContainsString('the import itself finished normally', strtolower($aUpdate['error']));
        self::assertStringContainsString('the cohort roll-up could not be rebuilt', $aUpdate['error']);
    }

    // --------------------------------------------------------------------------

    /**
     * complete() may already have recorded that the log could not be attached,
     * and both reasons matter, so the hook's is appended rather than substituted.
     */
    public function test_a_hooks_objection_is_appended_to_a_reason_already_recorded(): void
    {
        $this->oProcessor->sSourcePath   = $this->makeCsv();
        $this->oCdn->mObjectCreateReturn = false;
        $this->oCdn->sObjectCreateError  = 'The CDN is unreachable';

        $this->withHook('onImportComplete', function (): void {
            throw new RuntimeException('the cohort roll-up could not be rebuilt');
        });

        $oImport = $this->make([
            'status'          => 'RUNNING',
            'processed_count' => 1362,
            'success_count'   => 1362,
            'warning_count'   => 0,
            'error_count'     => 0,
        ]);

        /**
         * The hook is handed the refreshed job, so this is what it - and the
         * append - see; without it the recorder would hand back the resource
         * this call started with, whose error is still null.
         */
        $this->oModel->oNext = $this->make([
            'status'          => 'COMPLETE',
            'processed_count' => 1362,
            'success_count'   => 1362,
            'warning_count'   => 0,
            'error_count'     => 0,
            'error'           => 'The error log could not be attached: The CDN is unreachable',
        ]);

        $this->oProcessor->exposeComplete($oImport);

        $sError = $this->oModel->lastUpdate()['error'];

        self::assertStringContainsString('The error log could not be attached', $sError);
        self::assertStringContainsString('the cohort roll-up could not be rebuilt', $sError);
    }

    // --------------------------------------------------------------------------

    /**
     * fail() is reached from process()'s catch-all, so an exception escaping a
     * hook here would recurse straight back into it.
     */
    public function test_a_hook_which_objects_to_a_failure_does_not_escape(): void
    {
        $oService = $this->withHook('onImportFailed', function (): void {
            throw new RuntimeException('the cohort could not be released');
        });

        $oImport = $this->oProcessor->exposeFail(
            $this->make(['error_count' => 0]),
            'The CSV had no header.'
        );

        self::assertSame(Status::FAILED->value, $this->oModel->lastUpdate()['status']);
        self::assertSame(['onImportFailed'], $oService->calledHooks());

        //  Swallowed, but never silently
        self::assertNotEmpty(array_filter(
            $this->oLogger->aErrors,
            fn(string $sLine): bool => str_contains($sLine, 'onImportFailed() failed')
        ));
    }

    // --------------------------------------------------------------------------

    /**
     * The hook reads the job as it now stands, not as the call which failed it
     * found it; an app deciding what to unwind needs the terminal counts.
     */
    public function test_the_failure_hook_is_handed_the_job_as_it_now_stands(): void
    {
        $oService = $this->withHook('onImportFailed', function (): void {
        });

        $this->oModel->oNext = $this->make([
            'status'      => 'FAILED',
            'error_count' => 2,
            'error'       => 'The CSV had no header.',
        ]);

        $this->oProcessor->exposeFail($this->make(['error_count' => 0]), 'The CSV had no header.');

        /** @var Resource\User\Import $oGiven */
        $oGiven = $oService->firstCall('onImportFailed')[0];

        self::assertSame(Status::FAILED, $oGiven->status);
        self::assertSame('The CSV had no header.', $oGiven->error);
    }

    // --------------------------------------------------------------------------
    //  run()'s row hooks
    // --------------------------------------------------------------------------

    /**
     * Runs a two row job, with the user model and the hooks stubbed
     *
     * @return array{0: ImportServiceStub, 1: UserModelStub}
     */
    private function runTwoRows(array $aHooks = [], array $aOverrides = []): array
    {
        $oService  = new ImportServiceStub();
        $oUserStub = new UserModelStub();

        foreach ($aHooks as $sHook => $cHook) {
            $oService->aHooks[$sHook] = $cHook;
        }

        $this->oProcessor->sSourcePath    = $this->makeValidCsv(2);
        $this->oProcessor->oImportService = $oService;
        $this->oProcessor->oUserModel     = $oUserStub;

        $oImport = $this->make(array_merge([
            'status'          => 'RUNNING',
            'skip_registered' => false,
            'row_count'       => 2,
            'validated_count' => 2,
            'processed_count' => 0,
            'success_count'   => 0,
            'error_count'     => 0,
        ], $aOverrides));

        /**
         * What run() reads back once it has recorded the chunk; the counts have
         * to say the job is finished, or it never reaches complete().
         */
        $this->oModel->oNext = $this->make(array_merge([
            'status'          => 'RUNNING',
            'skip_registered' => false,
            'row_count'       => 2,
            'validated_count' => 2,
            'processed_count' => 2,
            'success_count'   => 2,
            'error_count'     => 0,
        ], $aOverrides));

        $this->oProcessor->exposeRun($oImport, 100);

        return [$oService, $oUserStub];
    }

    // --------------------------------------------------------------------------

    /**
     * Whatever the hook returns is what create() is given; an app which reaches
     * for a column the CSV carries but create() would discard has nowhere else
     * to put it.
     */
    public function test_the_data_hook_decides_what_reaches_create(): void
    {
        [$oService, $oUserStub] = $this->runTwoRows([
            'prepareUserData' => function (array $aUserData, $oImport, array $aRow): array {
                $aUserData['first_name'] = strtoupper((string) $aRow['username']);
                return $aUserData;
            },
        ]);

        self::assertSame(
            ['USER_1', 'USER_2'],
            array_column(array_column($oUserStub->aCreated, 'data'), 'first_name')
        );

        //  ...and it saw the row as the CSV had it
        self::assertSame('user_1', $oService->firstCall('prepareUserData')[2]['username']);
    }

    // --------------------------------------------------------------------------

    /**
     * The additional fields are applied first and the hook runs after them, so
     * an app which overrides the hook without calling parent:: still gets what
     * it configured - and may overrule it.
     */
    public function test_the_data_hook_runs_after_the_additional_fields(): void
    {
        [, $oUserStub] = $this->runTwoRows(
            [
                'prepareUserData' => function (array $aUserData): array {
                    $aUserData['salutation'] = strtoupper((string) $aUserData['cohort']);
                    return $aUserData;
                },
            ],
            ['additional' => '{"cohort":"autumn"}']
        );

        self::assertSame('autumn', $oUserStub->aCreated[0]['data']['cohort']);
        self::assertSame('AUTUMN', $oUserStub->aCreated[0]['data']['salutation']);
    }

    // --------------------------------------------------------------------------

    /**
     * The hook runs before anything has been written, so a row it refuses is a
     * plain error - and the job carries on to the next one.
     */
    public function test_a_refused_row_errors_and_leaves_no_account(): void
    {
        [, $oUserStub] = $this->runTwoRows([
            'prepareUserData' => function (array $aUserData): array {
                if ($aUserData['username'] === 'user_1') {
                    throw new RuntimeException('no cohort could be resolved for this row');
                }
                return $aUserData;
            },
        ]);

        $aItems = $this->oProcessor->itemsByLine();

        self::assertSame(ItemStatus::ERROR, $aItems[2]['status']);
        self::assertSame('no cohort could be resolved for this row', $aItems[2]['message']);
        self::assertNull($aItems[2]['user_id']);

        //  No account was attempted for it, and the next row was
        self::assertCount(1, $oUserStub->aCreated);
        self::assertSame(ItemStatus::SUCCESS, $aItems[3]['status']);
    }

    // --------------------------------------------------------------------------

    /**
     * The heart of ItemStatus::WARNING. create() commits before it returns, so
     * the account cannot be taken back; the row records that it exists, names
     * it, and says what did not happen.
     */
    public function test_a_created_account_whose_follow_up_failed_warns_against_its_id(): void
    {
        [, $oUserStub] = $this->runTwoRows([
            'afterUserCreate' => function (Resource\User $oUser): void {
                if ($oUser->email === 'user1@example.com') {
                    throw new RuntimeException('the subscription could not be started');
                }
            },
        ]);

        $aItems = $this->oProcessor->itemsByLine();

        self::assertSame(ItemStatus::WARNING, $aItems[2]['status']);
        self::assertSame(
            'The account was created, but the subscription could not be started',
            $aItems[2]['message']
        );

        //  The user_id is the point: it is the thread back to the account to fix
        self::assertSame(100, $aItems[2]['user_id']);

        //  The account was still created, and the rest of the job ran
        self::assertCount(2, $oUserStub->aCreated);
        self::assertSame(ItemStatus::SUCCESS, $aItems[3]['status']);
        self::assertSame(101, $aItems[3]['user_id']);

        //  ...and it was reported, not swallowed
        self::assertNotEmpty(array_filter(
            $this->oLogger->aErrors,
            fn(string $sLine): bool => str_contains($sLine, 'afterUserCreate() failed')
        ));
    }

    // --------------------------------------------------------------------------

    /**
     * A warned job has finished; it is only PARTIAL rather than COMPLETE.
     */
    public function test_a_job_which_warned_still_reaches_complete(): void
    {
        $this->runTwoRows(
            [
                'afterUserCreate' => function (): void {
                    throw new RuntimeException('the subscription could not be started');
                },
            ],
            ['success_count' => 1, 'warning_count' => 1]
        );

        $aUpdate = $this->oModel->lastUpdate();

        self::assertSame(Status::PARTIAL->value, $aUpdate['status']);
        self::assertNotNull($aUpdate['finished']);
    }

    // --------------------------------------------------------------------------

    /**
     * The row is claimed as an error before anything irreversible happens, so a
     * create() which refuses a row leaves the reason it gave, not the claim's
     * placeholder.
     */
    public function test_a_row_create_refuses_keeps_the_reason_create_gave(): void
    {
        $oService  = new ImportServiceStub();
        $oUserStub = new UserModelStub();

        $oUserStub->sFail        = 'false';
        $oUserStub->sCreateError = 'This email is already in use.';

        $this->oProcessor->sSourcePath    = $this->makeValidCsv(1);
        $this->oProcessor->oImportService = $oService;
        $this->oProcessor->oUserModel     = $oUserStub;

        $oImport = $this->make([
            'status'          => 'RUNNING',
            'row_count'       => 1,
            'processed_count' => 0,
            'error_count'     => 0,
        ]);

        $this->oModel->oNext = $oImport;

        $this->oProcessor->exposeRun($oImport, 100);

        $aItems = $this->oProcessor->itemsByLine();

        self::assertSame(ItemStatus::ERROR, $aItems[2]['status']);
        self::assertSame('This email is already in use.', $aItems[2]['message']);
        self::assertNull($aItems[2]['user_id']);

        //  Nothing was created, so there is nothing for the after-create hook to hear about
        self::assertSame(['prepareUserData'], $oService->calledHooks());
    }
}

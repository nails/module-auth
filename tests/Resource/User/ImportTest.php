<?php

namespace Tests\Auth\Resource\User;

use Nails\Auth\Enum\User\Import\Runner;
use Nails\Auth\Enum\User\Import\Status;
use Nails\Auth\Resource\User\Import;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @covers \Nails\Auth\Resource\User\Import
 */
class ImportTest extends TestCase
{
    /**
     * Builds a resource from the columns the model would supply
     */
    private function make(array $aOverrides = []): Import
    {
        return new Import((object) array_merge([
            'id'              => 1,
            'object_id'       => 2,
            'log_id'          => null,
            'additional'      => '{}',
            'status'          => 'RUNNING',
            'runner'          => 'CRON',
            'claim_token'     => null,
            'claimed'         => null,
            'error'           => null,
            'row_count'       => 100,
            'validated_count' => 100,
            'processed_count' => 25,
            'success_count'   => 25,
            'error_count'     => 0,
            'started'         => null,
            'finished'        => null,
        ], $aOverrides));
    }

    // --------------------------------------------------------------------------

    /**
     * Both columns arrived with the feature rather than with the table, so the
     * resource has to tolerate a schema which has not caught up - the `make()`
     * above deliberately omits them for that reason.
     */
    public function test_the_columns_added_after_the_table_tolerate_a_lagging_schema(): void
    {
        $oImport = $this->make();

        self::assertFalse($oImport->skip_registered);
        self::assertSame(0, $oImport->warning_count);
    }

    // --------------------------------------------------------------------------

    public function test_a_warning_count_is_read_when_it_is_there(): void
    {
        self::assertSame(3, $this->make(['warning_count' => '3'])->warning_count);
    }

    // --------------------------------------------------------------------------

    public function test_the_status_is_cast_to_an_enum(): void
    {
        self::assertSame(Status::RUNNING, $this->make()->status);
    }

    // --------------------------------------------------------------------------

    public function test_the_runner_is_cast_to_an_enum(): void
    {
        self::assertSame(Runner::CRON, $this->make()->runner);
        self::assertNull($this->make(['runner' => null])->runner);
    }

    // --------------------------------------------------------------------------

    public function test_the_additional_fields_are_decoded(): void
    {
        $oResource = $this->make(['additional' => '{"source":"crm"}']);

        self::assertInstanceOf(stdClass::class, $oResource->additional);
        self::assertSame('crm', $oResource->additional->source);
    }

    // --------------------------------------------------------------------------

    public function test_missing_additional_fields_decode_to_an_empty_object(): void
    {
        self::assertEquals(new stdClass(), $this->make(['additional' => null])->additional);
    }

    // --------------------------------------------------------------------------

    public function test_progress_is_measured_against_the_running_cursor(): void
    {
        self::assertSame(25, $this->make()->getPercent());
    }

    // --------------------------------------------------------------------------

    public function test_progress_is_measured_against_the_validating_cursor(): void
    {
        $oResource = $this->make([
            'status'          => 'VALIDATING',
            'validated_count' => 40,
            'processed_count' => 0,
        ]);

        self::assertSame(40, $oResource->getPercent());
    }

    // --------------------------------------------------------------------------

    public function test_a_finished_job_is_always_complete(): void
    {
        foreach (Status::terminal() as $oStatus) {
            self::assertSame(
                100,
                $this->make(['status' => $oStatus->value, 'processed_count' => 3])->getPercent(),
                $oStatus->value
            );
        }
    }

    // --------------------------------------------------------------------------

    public function test_a_job_of_unknown_size_reports_no_progress(): void
    {
        self::assertSame(0, $this->make(['row_count' => null])->getPercent());
    }
}

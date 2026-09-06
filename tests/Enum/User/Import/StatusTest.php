<?php

namespace Tests\Auth\Enum\User\Import;

use Nails\Auth\Enum\User\Import\Status;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Nails\Auth\Enum\User\Import\Status
 */
class StatusTest extends TestCase
{
    public function test_finished_statuses_are_terminal(): void
    {
        self::assertTrue(Status::COMPLETE->isTerminal());
        self::assertTrue(Status::PARTIAL->isTerminal());
        self::assertTrue(Status::FAILED->isTerminal());
    }

    // --------------------------------------------------------------------------

    public function test_unfinished_statuses_are_not_terminal(): void
    {
        self::assertFalse(Status::DRAFT->isTerminal());
        self::assertFalse(Status::PENDING->isTerminal());
        self::assertFalse(Status::VALIDATING->isTerminal());
        self::assertFalse(Status::RUNNING->isTerminal());
    }

    // --------------------------------------------------------------------------

    /**
     * A draft has not been approved, so no runner may touch it
     */
    public function test_a_draft_is_not_active(): void
    {
        self::assertFalse(Status::DRAFT->isActive());
        self::assertTrue(Status::PENDING->isActive());
        self::assertTrue(Status::VALIDATING->isActive());
        self::assertTrue(Status::RUNNING->isActive());
    }

    // --------------------------------------------------------------------------

    /**
     * Only a job a runner holds mid-flight is protected; PENDING is approved but
     * untouched, so it is deletable even though it is active
     */
    public function test_only_in_flight_statuses_are_not_deletable(): void
    {
        self::assertFalse(Status::VALIDATING->isDeletable());
        self::assertFalse(Status::RUNNING->isDeletable());

        self::assertTrue(Status::DRAFT->isDeletable());
        self::assertTrue(Status::PENDING->isDeletable());
        self::assertTrue(Status::COMPLETE->isDeletable());
        self::assertTrue(Status::PARTIAL->isDeletable());
        self::assertTrue(Status::FAILED->isDeletable());
    }

    // --------------------------------------------------------------------------

    /**
     * Guards against `isActive()` being mistaken for the deletion rule; it is
     * not, because it includes PENDING
     */
    public function test_deletability_is_not_the_inverse_of_active(): void
    {
        self::assertTrue(Status::PENDING->isActive());
        self::assertTrue(Status::PENDING->isDeletable());
    }

    // --------------------------------------------------------------------------

    public function test_values_maps_onto_the_database_representation(): void
    {
        self::assertSame(
            ['PENDING', 'VALIDATING', 'RUNNING'],
            Status::values(Status::active())
        );

        self::assertSame(
            ['COMPLETE', 'PARTIAL', 'FAILED'],
            Status::values(Status::terminal())
        );
    }
}

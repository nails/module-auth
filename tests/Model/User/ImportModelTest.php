<?php

namespace Tests\Auth\Model\User;

use Nails\Auth\Constants;
use Nails\Auth\Enum\User\Import\Status;
use Nails\Auth\Model\User\Import;
use Nails\Common\Model\Base as BaseModel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Nails\Auth\Resource;
use Tests\Auth\Stub\CdnSpy;
use Tests\Auth\Stub\DatabaseSpy;
use Tests\Auth\Stub\ImportModelWithSpy;

/**
 * @covers \Nails\Auth\Model\User\Import
 */
class ImportModelTest extends TestCase
{
    public function test_newest_jobs_sort_first(): void
    {
        self::assertSame('id', Import::DEFAULT_SORT_COLUMN);
        self::assertSame(BaseModel::SORT_DESC, Import::DEFAULT_SORT_ORDER);
    }

    // --------------------------------------------------------------------------

    public function test_caching_is_disabled(): void
    {
        self::assertFalse(
            (new ReflectionClass(Import::class))
                ->getProperty('CACHING_ENABLED')
                ->getValue()
        );
    }

    // --------------------------------------------------------------------------

    public function test_the_expected_relationships_are_registered(): void
    {
        $aFields = [];
        foreach ((new Import())->getExpandableFields() as $oField) {
            $aFields[$oField->trigger] = $oField;
        }

        self::assertArrayHasKey('user', $aFields);
        self::assertSame('created_by', $aFields['user']->id_column);
        self::assertSame(Constants::MODULE_SLUG, $aFields['user']->provider);

        self::assertArrayHasKey('object', $aFields);
        self::assertSame('object_id', $aFields['object']->id_column);

        self::assertArrayHasKey('log', $aFields);
        self::assertSame('log_id', $aFields['log']->id_column);
    }

    // --------------------------------------------------------------------------

    public function test_claiming_guards_against_a_job_which_is_already_held(): void
    {
        $oSpy   = new DatabaseSpy();
        $oModel = new ImportModelWithSpy($oSpy);

        $oModel->claim(7, 'token-a');

        //  The update only lands if nobody else holds the job...
        self::assertContains(['claim_token', null], $oSpy->callsTo('where'));

        //  ...and only for a status a runner is allowed to pick up
        self::assertContains(
            ['status', ['PENDING', 'VALIDATING', 'RUNNING']],
            $oSpy->callsTo('where_in')
        );

        self::assertSame(
            Status::values(Status::active()),
            ['PENDING', 'VALIDATING', 'RUNNING']
        );

        self::assertContains(['claim_token', 'token-a'], $oSpy->callsTo('set'));
        self::assertContains('update', $oSpy->methods());
    }

    // --------------------------------------------------------------------------

    public function test_a_claim_is_confirmed_by_reading_the_row_back(): void
    {
        $oSpy                   = new DatabaseSpy();
        $oSpy->iCountAllResults = 1;

        self::assertTrue((new ImportModelWithSpy($oSpy))->claim(7, 'token-a'));

        //  The read back must be scoped to this process's own token
        self::assertContains(['id', 7], $oSpy->callsTo('where'));
        self::assertContains(['claim_token', 'token-a'], $oSpy->callsTo('where'));
    }

    // --------------------------------------------------------------------------

    public function test_losing_the_race_yields_no_claim(): void
    {
        $oSpy                   = new DatabaseSpy();
        $oSpy->iCountAllResults = 0;

        self::assertFalse((new ImportModelWithSpy($oSpy))->claim(7, 'token-a'));
    }

    // --------------------------------------------------------------------------

    public function test_releasing_clears_the_claim(): void
    {
        $oSpy = new DatabaseSpy();

        (new ImportModelWithSpy($oSpy))->release(7);

        self::assertContains(['claim_token', null], $oSpy->callsTo('set'));
        self::assertContains(['claimed', null], $oSpy->callsTo('set'));
        self::assertContains(['id', 7], $oSpy->callsTo('where'));
        self::assertContains('update', $oSpy->methods());
    }

    // --------------------------------------------------------------------------

    /**
     * A restarted job has to lose its items: the unique key on
     * (import_id, line) would otherwise make every line look already handled.
     */
    public function test_deleting_a_jobs_items_targets_only_that_job(): void
    {
        $oSpy = new DatabaseSpy();

        self::assertTrue((new ImportModelWithSpy($oSpy))->deleteItems(7));

        self::assertContains(['import_id', 7], $oSpy->callsTo('where'));
        self::assertContains('delete', $oSpy->methods());

        //  The items table, not the jobs table
        self::assertSame(
            [Import\Item::TABLE],
            array_map(fn(array $aArgs): string => $aArgs[0], $oSpy->callsTo('delete'))
        );
    }

    // --------------------------------------------------------------------------

    /**
     * Builds a job resource from the columns the model would supply
     */
    private function makeImport(?int $iObjectId, ?int $iLogId): Resource\User\Import
    {
        return new Resource\User\Import((object) [
            'id'              => 7,
            'object_id'       => $iObjectId,
            'log_id'          => $iLogId,
            'additional'      => '{}',
            'status'          => 'COMPLETE',
            'runner'          => 'CRON',
            'claim_token'     => null,
            'claimed'         => null,
            'error'           => null,
            'row_count'       => 10,
            'validated_count' => 10,
            'processed_count' => 10,
            'success_count'   => 10,
            'error_count'     => 0,
            'started'         => null,
            'finished'        => null,
        ]);
    }

    // --------------------------------------------------------------------------

    public function test_only_the_csv_is_destroyed_when_there_is_no_log(): void
    {
        $oCdn = new CdnSpy();

        $aErrors = (new ImportModelWithSpy(new DatabaseSpy(), $oCdn))
            ->destroyObjects($this->makeImport(2, null));

        self::assertSame([2], $oCdn->aDestroyed);
        self::assertSame([], $aErrors);
    }

    // --------------------------------------------------------------------------

    public function test_both_the_csv_and_the_log_are_destroyed(): void
    {
        $oCdn = new CdnSpy();

        $aErrors = (new ImportModelWithSpy(new DatabaseSpy(), $oCdn))
            ->destroyObjects($this->makeImport(2, 3));

        self::assertSame([2, 3], $oCdn->aDestroyed);
        self::assertSame([], $aErrors);
    }

    // --------------------------------------------------------------------------

    /**
     * objectDestroy() reports a missing object by returning false, not by
     * throwing, so the return value has to be checked
     */
    public function test_a_false_return_is_collected_with_its_error(): void
    {
        $oCdn             = new CdnSpy();
        $oCdn->aFailures  = [3 => 'Nothing to destroy.'];

        $aErrors = (new ImportModelWithSpy(new DatabaseSpy(), $oCdn))
            ->destroyObjects($this->makeImport(2, 3));

        self::assertSame([3 => 'Nothing to destroy.'], $aErrors);
    }

    // --------------------------------------------------------------------------

    public function test_a_throw_is_collected_with_its_message(): void
    {
        $oCdn          = new CdnSpy();
        $oCdn->aThrows = [2 => 'The driver fell over'];

        $aErrors = (new ImportModelWithSpy(new DatabaseSpy(), $oCdn))
            ->destroyObjects($this->makeImport(2, 3));

        //  The log is still attempted; one bad object must not strand the other
        self::assertSame([2, 3], $oCdn->aDestroyed);
        self::assertSame([2 => 'The driver fell over'], $aErrors);
    }
}

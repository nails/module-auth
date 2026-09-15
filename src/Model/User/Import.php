<?php

/**
 * This model contains all methods for interacting with user import jobs.
 *
 * @package    Nails
 * @subpackage module-auth
 * @category   Model
 * @author     Nails Dev Team
 */

namespace Nails\Auth\Model\User;

use Nails\Auth\Constants;
use Nails\Auth\Enum\User\Import\ItemStatus;
use Nails\Auth\Enum\User\Import\Status;
use Nails\Auth\Resource;
use Nails\Cdn;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\ModelException;
use Nails\Common\Model\Base;
use Nails\Common\Service\Database;
use Nails\Factory;
use Throwable;

/**
 * Class Import
 *
 * @package Nails\Auth\Model\User
 */
class Import extends Base
{
    /**
     * The table this model represents
     *
     * @var string
     */
    const TABLE = NAILS_DB_PREFIX . 'user_import';

    /**
     * The name of the resource to use (as passed to \Nails\Factory::resource())
     *
     * @var string
     */
    const RESOURCE_NAME = 'UserImport';

    /**
     * The provider of the resource to use (as passed to \Nails\Factory::resource())
     *
     * @var string
     */
    const RESOURCE_PROVIDER = Constants::MODULE_SLUG;

    /**
     * The default column to sort on
     *
     * @var string
     */
    const DEFAULT_SORT_COLUMN = 'id';

    /**
     * The default sort order
     *
     * @var string
     */
    const DEFAULT_SORT_ORDER = self::SORT_DESC;

    /**
     * No caching; jobs are mutated by long-running workers, so every lookup
     * must see live state.
     *
     * @var bool
     */
    protected static $CACHING_ENABLED = false;

    // --------------------------------------------------------------------------

    /**
     * @throws ModelException
     */
    public function __construct()
    {
        parent::__construct();
        $this
            ->hasOne('user', 'User', Constants::MODULE_SLUG, 'created_by')
            ->hasOne('object', 'Object', Cdn\Constants::MODULE_SLUG)
            ->hasOne('log', 'Object', Cdn\Constants::MODULE_SLUG);
    }

    // --------------------------------------------------------------------------

    /**
     * Attempts to take exclusive ownership of a job
     *
     * The update only lands if the job is unclaimed and in a status a runner is
     * permitted to pick up; the read back confirms it was this process which won.
     *
     * @param int    $iId     The job to claim
     * @param string $sToken  The token to claim it with
     *
     * @throws FactoryException
     * @throws ModelException
     */
    public function claim(int $iId, string $sToken): bool
    {
        $oDb = $this->getDb();

        $oDb
            ->set('claim_token', $sToken)
            ->set('claimed', 'NOW()', false)
            ->where('id', $iId)
            ->where('claim_token', null)
            ->where_in('status', Status::values(Status::active()))
            ->update($this->getTableName());

        return (bool) $oDb
            ->where('id', $iId)
            ->where('claim_token', $sToken)
            ->count_all_results($this->getTableName());
    }

    // --------------------------------------------------------------------------

    /**
     * Relinquishes ownership of a job
     *
     * @throws FactoryException
     * @throws ModelException
     */
    public function release(int $iId): void
    {
        $oDb = $this->getDb();

        $oDb
            ->set('claim_token', null)
            ->set('claimed', null)
            ->where('id', $iId)
            ->update($this->getTableName());
    }

    // --------------------------------------------------------------------------

    /**
     * Records how far through the validation phase the job is
     *
     * @throws FactoryException
     * @throws ModelException
     */
    public function setValidatedCount(int $iId, int $iCount): void
    {
        if (!$this->update($iId, ['validated_count' => $iCount])) {
            throw new ModelException($this->lastError());
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Recalculates the error count from the item table
     *
     * The counts are derived rather than incremented so that a job which is
     * interrupted and resumed cannot double count, or lose, a row.
     *
     * @throws FactoryException
     * @throws ModelException
     */
    public function syncValidationCounts(int $iId): void
    {
        $this->update($iId, [
            'error_count' => $this->countItems($iId, ItemStatus::ERROR),
        ]);
    }

    // --------------------------------------------------------------------------

    /**
     * Recalculates the processing cursor and counts from the item table
     *
     * @throws FactoryException
     * @throws ModelException
     */
    public function syncProcessingCounts(int $iId): void
    {
        $this->update($iId, [
            'processed_count' => $this->countItems($iId),
            'success_count'   => $this->countItems($iId, ItemStatus::SUCCESS),
            'warning_count'   => $this->countItems($iId, ItemStatus::WARNING),
            'error_count'     => $this->countItems($iId, ItemStatus::ERROR),
        ]);
    }

    // --------------------------------------------------------------------------

    /**
     * Counts the job's items, optionally of a given status
     *
     * @throws FactoryException
     * @throws ModelException
     */
    protected function countItems(int $iId, ?ItemStatus $oStatus = null): int
    {
        /** @var Import\Item $oItemModel */
        $oItemModel = Factory::model('UserImportItem', Constants::MODULE_SLUG);

        $oDb = $this->getDb();
        $oDb->where('import_id', $iId);

        if ($oStatus !== null) {
            $oDb->where('status', $oStatus->value);
        }

        return (int) $oDb->count_all_results($oItemModel->getTableName());
    }

    // --------------------------------------------------------------------------

    /**
     * Destroys the CDN objects belonging to a job
     *
     * Call this only *after* the job row has been deleted: `object_id` is
     * ON DELETE CASCADE, so destroying the CSV while the row still exists takes
     * the row with it - inside objectDestroy()'s own transaction - and the
     * delete() which follows then reports the job as missing.
     *
     * Failures are collected rather than thrown: the row is already gone, so a
     * file which outlives it is litter, not an error. Note that
     * `Nails\Auth\Housekeeping\UserImports` walks job rows, so it will never reap these.
     * objectDestroy() signals failure both by returning false - for a missing
     * object, a driver failure, or a rolled back transaction - and by throwing,
     * so both are handled.
     *
     * @return array<int, string> The reason each object could not be destroyed, keyed by object ID
     *
     * @throws FactoryException
     */
    public function destroyObjects(Resource\User\Import $oImport): array
    {
        $oCdn    = $this->getCdn();
        $aErrors = [];

        foreach (array_filter([$oImport?->object?->id ?? $oImport->object_id, $oImport->log_id]) as $iObjectId) {
            try {
                if (!$oCdn->objectDestroy($iObjectId)) {
                    $aErrors[(int) $iObjectId] = $oCdn->lastError() ?: 'Unknown error';
                }
            } catch (Throwable $e) {
                $aErrors[(int) $iObjectId] = $e->getMessage();
            }
        }

        return $aErrors;
    }

    // --------------------------------------------------------------------------

    /**
     * Deletes the job's items
     *
     * Called when a job is restarted from PENDING. The unique key on
     * (import_id, line) is what makes resuming idempotent, so leaving the
     * previous run's items in place would make every line look as though it had
     * already been handled - and the counts, which are re-derived rather than
     * incremented, would be taken from that stale run.
     *
     * @return bool Whether the items were deleted
     * @throws FactoryException
     */
    public function deleteItems(int $iId): bool
    {
        /** @var Import\Item $oItemModel */
        $oItemModel = Factory::model('UserImportItem', Constants::MODULE_SLUG);

        return (bool) $this
            ->getDb()
            ->where('import_id', $iId)
            ->delete($oItemModel->getTableName());
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the database service
     *
     * Broken out so that the claim/release contract can be exercised in
     * isolation, without standing up a database.
     *
     * @throws FactoryException
     */
    protected function getDb(): Database
    {
        /** @var Database $oDb */
        $oDb = Factory::service('Database');
        return $oDb;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the CDN service
     *
     * Broken out so that destroyObjects() can be exercised in isolation, as
     * getDb() does for the database.
     *
     * @throws FactoryException
     */
    protected function getCdn(): Cdn\Service\Cdn
    {
        /** @var Cdn\Service\Cdn $oCdn */
        $oCdn = Factory::service('Cdn', Cdn\Constants::MODULE_SLUG);
        return $oCdn;
    }
}

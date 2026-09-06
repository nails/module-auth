<?php

namespace Tests\Auth\Stub;

use Nails\Auth\Model\User\Import;
use Nails\Auth\Resource;
use Nails\Common\Service\Database;

/**
 * The import model with its writes recorded rather than performed, so that what
 * the processor decides to store can be asserted without a database.
 *
 * Only the handful of methods the processor actually calls are replaced;
 * everything else is inherited, which is what keeps the real relationships and
 * table names in play.
 */
class ImportModelRecorder extends Import
{
    /**
     * Every update, in order: ['id' => int, 'data' => array]
     *
     * @var array<int, array{id: int, data: array}>
     */
    public array $aUpdates = [];

    /**
     * What update() should report
     */
    public bool $bUpdateReturn = true;

    /**
     * The error update() should report when it returns false
     */
    public ?string $sUpdateError = null;

    /**
     * Every job ID passed to deleteItems(), in order
     *
     * @var int[]
     */
    public array $aItemsDeleted = [];

    /**
     * What getById() should hand back; null makes the processor keep the
     * resource it already had
     */
    public ?Resource\User\Import $oNext = null;

    // --------------------------------------------------------------------------

    /**
     * The counts the model derives are read through here, so a recorder can
     * answer them without a server; see Model\User\Import::countItems()
     */
    public DatabaseSpy $oDbSpy;

    // --------------------------------------------------------------------------

    public function __construct()
    {
        $this->oDbSpy = new DatabaseSpy();
        parent::__construct();
    }

    // --------------------------------------------------------------------------

    protected function getDb(): Database
    {
        return $this->oDbSpy;
    }

    // --------------------------------------------------------------------------

    public function update($iId, array $aData = []): bool
    {
        $this->aUpdates[] = [
            'id'   => (int) $iId,
            'data' => $aData,
        ];

        if (!$this->bUpdateReturn && $this->sUpdateError !== null) {
            $this->setError($this->sUpdateError);
        }

        return $this->bUpdateReturn;
    }

    // --------------------------------------------------------------------------

    public function getById(?int $iId, array $aData = [])
    {
        return $this->oNext;
    }

    // --------------------------------------------------------------------------

    public function deleteItems(int $iId): bool
    {
        $this->aItemsDeleted[] = $iId;
        return true;
    }

    // --------------------------------------------------------------------------

    /**
     * The data of the last update, or null if there has not been one
     */
    public function lastUpdate(): ?array
    {
        $aUpdate = end($this->aUpdates);
        return $aUpdate === false ? null : $aUpdate['data'];
    }
}

<?php

namespace Tests\Auth\Stub;

use Generator;
use Nails\Auth\Enum\User\Import\ItemStatus;
use Nails\Auth\Model\User as UserModel;
use Nails\Auth\Model\User\Import as ImportModel;
use Nails\Auth\Resource;
use Nails\Auth\Service\User\Import as ImportService;
use Nails\Auth\Service\User\Import\Processor;
use Nails\Cdn\Service\Cdn;
use Nails\Common\Service\Logger;
use Throwable;

/**
 * The processor with its model, CDN and logger swapped for spies, and with the
 * two methods which need a database - the item queries - answered from an
 * injected fixture.
 *
 * Everything protected which is worth asserting on its own is re-exposed here
 * rather than reached through reflection; the same bargain ImportModelWithSpy
 * makes for the model's seams.
 */
class ProcessorWithSpies extends Processor
{
    /**
     * The path getSourcePath() should report, standing in for the CDN download
     */
    public ?string $sSourcePath = null;

    /**
     * The job's failing rows: [['line' => int, 'message' => string|null], ...]
     *
     * @var array<int, array{line: int, message: string|null}>
     */
    public array $aSample = [];

    /**
     * The job's warned rows, in the same shape as $aSample
     *
     * @var array<int, array{line: int, message: string|null}>
     */
    public array $aWarningSample = [];

    /**
     * The import service the hooks are fired against; the real one unless a
     * test supplies its own
     */
    public ?ImportService $oImportService = null;

    /**
     * The user model accounts are created through; the real one unless a test
     * supplies its own
     */
    public ?UserModel $oUserModel = null;

    /**
     * The statuses each call to streamItems() asked for, in order
     *
     * @var array<int, ItemStatus[]|null>
     */
    public array $aStreamed = [];

    /**
     * Every item claimed by run(), keyed by the ID it was given:
     * ['line' => int, 'status' => ItemStatus, 'message' => ?string, 'user_id' => ?int]
     *
     * @var array<int, array{line: int, status: ItemStatus, message: string|null, user_id: int|null}>
     */
    public array $aItems = [];

    /**
     * Lines getHandledLines() should report as already dealt with
     *
     * @var int[]
     */
    public array $aHandled = [];

    // --------------------------------------------------------------------------

    public function __construct(
        public ImportModelRecorder $oModelStub,
        public CdnSpy $oCdnSpy,
        public LoggerSpy $oLoggerSpy
    ) {
    }

    // --------------------------------------------------------------------------
    //  Seams
    // --------------------------------------------------------------------------

    protected function getModel(): ImportModel
    {
        return $this->oModelStub;
    }

    protected function getCdn(): Cdn
    {
        return $this->oCdnSpy;
    }

    protected function getLogger(): Logger
    {
        return $this->oLoggerSpy;
    }

    protected function getSourcePath(Resource\User\Import $oImport): string
    {
        return $this->sSourcePath ?? parent::getSourcePath($oImport);
    }

    protected function refresh(Resource\User\Import $oImport): Resource\User\Import
    {
        return $this->oModelStub->oNext ?? $oImport;
    }

    /**
     * The real implementation needs module-cdn's MetaData interfaces, which the
     * copy this module vendors for its own tests does not carry - the same
     * staleness which makes phpstan report src/Cdn/MetaData/SystemKey/*. The
     * keys are not what these tests are about, so they are left out.
     */
    protected function getLogMetaData(Resource\User\Import $oImport): array
    {
        return [];
    }

    protected function getImportService(): ImportService
    {
        return $this->oImportService ?? parent::getImportService();
    }

    protected function getUserModel(): UserModel
    {
        return $this->oUserModel ?? parent::getUserModel();
    }

    /**
     * The item table stands in for itself: the claim-then-resolve contract is
     * what run() is built on, and asserting the outcome of a row means being
     * able to read it back without a database.
     */
    protected function recordItem(
        Resource\User\Import $oImport,
        int $iLine,
        ItemStatus $oStatus,
        ?string $sMessage = null,
        ?int $iUserId = null
    ): ?int {

        $iItemId = count($this->aItems) + 1;

        $this->aItems[$iItemId] = [
            'line'    => $iLine,
            'status'  => $oStatus,
            'message' => $sMessage,
            'user_id' => $iUserId,
        ];

        return $iItemId;
    }

    protected function resolveItem(
        int $iItemId,
        ItemStatus $oStatus,
        ?string $sMessage = null,
        ?int $iUserId = null
    ): void {

        $this->aItems[$iItemId] = array_merge(
            $this->aItems[$iItemId] ?? ['line' => 0],
            [
                'status'  => $oStatus,
                'message' => $sMessage,
                'user_id' => $iUserId,
            ]
        );
    }

    protected function getHandledLines(Resource\User\Import $oImport, array $aLines): array
    {
        return array_values(array_intersect($this->aHandled, $aLines));
    }

    protected function sampleErrors(Resource\User\Import $oImport, int $iLimit): array
    {
        return array_slice($this->aSample, 0, $iLimit);
    }

    /**
     * @param ItemStatus[]|null $aStatuses
     */
    protected function streamItems(Resource\User\Import $oImport, ?array $aStatuses = null): Generator
    {
        $this->aStreamed[] = $aStatuses;

        $aItems = array_merge(
            array_map(
                fn(array $aItem): array => $aItem + ['status' => ItemStatus::ERROR],
                $this->aSample
            ),
            array_map(
                fn(array $aItem): array => $aItem + ['status' => ItemStatus::WARNING],
                $this->aWarningSample
            )
        );

        foreach ($aItems as $aItem) {

            if (!empty($aStatuses) && !in_array($aItem['status'], $aStatuses, true)) {
                continue;
            }

            yield $aItem['line'] => [
                'id'      => null,
                'status'  => $aItem['status']->value,
                'message' => $aItem['message'],
            ];
        }
    }

    // --------------------------------------------------------------------------
    //  Exposed for assertion
    // --------------------------------------------------------------------------

    public function exposeUploadLog(Resource\User\Import $oImport, iterable $aItems): int
    {
        return $this->uploadLog($oImport, $aItems);
    }

    /**
     * @param ItemStatus[]|null $aStatuses
     */
    public function exposeAttachLog(Resource\User\Import $oImport, ?array $aStatuses = null): array
    {
        return $this->attachLog($oImport, $aStatuses);
    }

    /**
     * @param ItemStatus[]|null $aStatuses
     *
     * @return array<int, array{id: int|null, status: string, message: string|null}>
     */
    public function exposeStreamItems(Resource\User\Import $oImport, ?array $aStatuses = null): array
    {
        return iterator_to_array($this->streamItems($oImport, $aStatuses), true);
    }

    public function exposeDescribeSurvivors(Resource\User\Import $oImport): ?string
    {
        return $this->describeSurvivors($oImport);
    }

    public function exposeBegin(Resource\User\Import $oImport): Resource\User\Import
    {
        return $this->begin($oImport);
    }

    public function exposeRun(Resource\User\Import $oImport, int $iLimit): Resource\User\Import
    {
        return $this->run($oImport, $iLimit);
    }

    /**
     * The items run() recorded, in line order
     *
     * @return array<int, array{line: int, status: ItemStatus, message: string|null, user_id: int|null}>
     */
    public function itemsByLine(): array
    {
        $aItems = [];

        foreach ($this->aItems as $aItem) {
            $aItems[$aItem['line']] = $aItem;
        }

        ksort($aItems);

        return $aItems;
    }

    public function exposeComplete(Resource\User\Import $oImport): Resource\User\Import
    {
        return $this->complete($oImport);
    }

    public function exposeFail(
        Resource\User\Import $oImport,
        string $sError,
        ?Throwable $e = null
    ): Resource\User\Import {
        return $this->fail($oImport, $sError, $e);
    }

    public function exposeComposeValidationError(Resource\User\Import $oImport): string
    {
        return $this->composeValidationError($oImport);
    }

    public function exposeComposeThrowableError(Resource\User\Import $oImport, Throwable $e): string
    {
        return $this->composeThrowableError($oImport, $e);
    }

    public function exposeComposeStallError(Resource\User\Import $oImport, string $sReason): string
    {
        return $this->composeStallError($oImport, $sReason);
    }

    public function exposeErrorPayload(Throwable $e): array
    {
        return $this->errorPayload($e);
    }

    public function exposeTruncateError(string $sError): string
    {
        return $this->truncateError($sError);
    }

    public function exposeFirstLine(string $sError): string
    {
        return $this->firstLine($sError);
    }

    public function exposeSummariseError(?string $sError): ?string
    {
        return $this->summariseError($sError);
    }
}

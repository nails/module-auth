<?php

namespace Tests\Auth\Stub;

use Closure;
use Nails\Auth\Resource;
use Nails\Auth\Service\User\Import;

/**
 * The import service with the parts an app is allowed to amend made settable,
 * and its existence lookup answered from a fixture rather than the database.
 */
class ImportServiceStub extends Import
{
    /**
     * @param string[]|null            $aKeys       Overrides getKeys()
     * @param string[]|null            $aUniqueKeys Overrides getUniqueKeys()
     * @param array<string, string[]>  $aExisting   Values already taken, keyed by unique key
     */
    public function __construct(
        private ?array $aKeys = null,
        private ?array $aUniqueKeys = null,
        private array $aExisting = []
    ) {
    }

    // --------------------------------------------------------------------------

    /**
     * Every call to whichExist(), in order: ['key' => string, 'values' => string[]]
     *
     * @var array<int, array{key: string, values: string[]}>
     */
    public array $aLookups = [];

    /**
     * Unique keys which whichExist() should refuse, modelling an app which has
     * added a key without saying where it lives
     *
     * @var string[]
     */
    public array $aUnsupported = [];

    /**
     * What each lifecycle hook should do, keyed by hook name; null leaves the
     * inherited no-op in place. This is the shape an app subclass takes - one
     * override per hook - with the body made settable per test.
     *
     * @var array<string, Closure|null>
     */
    public array $aHooks = [];

    /**
     * Every hook call, in order: ['hook' => string, 'args' => array]
     *
     * @var array<int, array{hook: string, args: array}>
     */
    public array $aHookCalls = [];

    // --------------------------------------------------------------------------

    public function getKeys(): array
    {
        return $this->aKeys ?? parent::getKeys();
    }

    // --------------------------------------------------------------------------

    public function getUniqueKeys(): array
    {
        return $this->aUniqueKeys ?? parent::getUniqueKeys();
    }

    // --------------------------------------------------------------------------

    public function whichExist(string $sKey, array $aValues): array
    {
        $this->aLookups[] = [
            'key'    => $sKey,
            'values' => $aValues,
        ];

        if (in_array($sKey, $this->aUnsupported, true)) {
            //  Mirrors the real service's refusal to answer for a key it cannot look up
            return parent::whichExist($sKey, $aValues);
        }

        return array_values(
            array_intersect(
                array_map('strtolower', $this->aExisting[$sKey] ?? []),
                array_map('strtolower', $aValues)
            )
        );
    }

    // --------------------------------------------------------------------------
    //  Lifecycle hooks
    // --------------------------------------------------------------------------

    public function onImportStart(Resource\User\Import $oImport): void
    {
        $this->fire('onImportStart', [$oImport]);
    }

    // --------------------------------------------------------------------------

    public function prepareUserData(array $aUserData, Resource\User\Import $oImport, array $aRow): array
    {
        $mReturn = $this->fire('prepareUserData', [$aUserData, $oImport, $aRow]);

        /**
         * Falls back to the parent rather than to $aUserData: an unset hook has
         * to exercise the real no-op, which is what guards against a future edit
         * giving it an opinion.
         */
        return $mReturn ?? parent::prepareUserData($aUserData, $oImport, $aRow);
    }

    // --------------------------------------------------------------------------

    public function afterUserCreate(
        Resource\User $oUser,
        array $aUserData,
        Resource\User\Import $oImport
    ): void {
        $this->fire('afterUserCreate', [$oUser, $aUserData, $oImport]);
    }

    // --------------------------------------------------------------------------

    public function onImportComplete(Resource\User\Import $oImport): void
    {
        $this->fire('onImportComplete', [$oImport]);
    }

    // --------------------------------------------------------------------------

    public function onImportFailed(Resource\User\Import $oImport): void
    {
        $this->fire('onImportFailed', [$oImport]);
    }

    // --------------------------------------------------------------------------

    /**
     * Records the call and runs whatever the test set for the hook
     *
     * @param array<int, mixed> $aArgs
     *
     * @return mixed Whatever the hook returned, or null if none was set
     */
    private function fire(string $sHook, array $aArgs): mixed
    {
        $this->aHookCalls[] = [
            'hook' => $sHook,
            'args' => $aArgs,
        ];

        $cHook = $this->aHooks[$sHook] ?? null;

        return $cHook === null ? null : $cHook(...$aArgs);
    }

    // --------------------------------------------------------------------------

    /**
     * The hooks which have been called, in order
     *
     * @return string[]
     */
    public function calledHooks(): array
    {
        return array_column($this->aHookCalls, 'hook');
    }

    // --------------------------------------------------------------------------

    /**
     * The arguments of the first call to a hook, or null if it was not called
     *
     * @return array<int, mixed>|null
     */
    public function firstCall(string $sHook): ?array
    {
        foreach ($this->aHookCalls as $aCall) {
            if ($aCall['hook'] === $sHook) {
                return $aCall['args'];
            }
        }

        return null;
    }
}

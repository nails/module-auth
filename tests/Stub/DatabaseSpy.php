<?php

namespace Tests\Auth\Stub;

use Nails\Common\Service\Database;

/**
 * A stand-in for the database service which records the fluent calls made
 * against it, so that the shape of a query can be asserted without a server.
 *
 * The real service proxies everything to CodeIgniter via __call, so a
 * conventional mock cannot express these expectations.
 */
class DatabaseSpy extends Database
{
    /**
     * Every call made, in order: ['method' => string, 'args' => array]
     *
     * @var array<int, array{method: string, args: array}>
     */
    public array $aCalls = [];

    /**
     * What count_all_results() should report next
     */
    public int $iCountAllResults = 0;

    // --------------------------------------------------------------------------

    public function __construct()
    {
        //  Deliberately does not connect
    }

    // --------------------------------------------------------------------------

    public function __call($sMethod, $aArguments)
    {
        $this->aCalls[] = [
            'method' => $sMethod,
            'args'   => $aArguments,
        ];

        return match ($sMethod) {
            'count_all_results' => $this->iCountAllResults,
            'update'            => true,
            default             => $this,
        };
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the arguments of every call to $sMethod
     */
    public function callsTo(string $sMethod): array
    {
        return array_values(
            array_map(
                fn(array $aCall) => $aCall['args'],
                array_filter(
                    $this->aCalls,
                    fn(array $aCall) => $aCall['method'] === $sMethod
                )
            )
        );
    }

    // --------------------------------------------------------------------------

    /**
     * The methods which were called, in order
     *
     * @return string[]
     */
    public function methods(): array
    {
        return array_column($this->aCalls, 'method');
    }
}

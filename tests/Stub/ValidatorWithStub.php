<?php

namespace Tests\Auth\Stub;

use Nails\Auth\Service\User\Import as ImportService;
use Nails\Auth\Service\User\Import\Validator;

/**
 * The validator with its import service swapped, so the contracts it enforces
 * can be tested against a template an app has amended.
 */
class ValidatorWithStub extends Validator
{
    public function __construct(private ImportService $oImportService)
    {
    }

    // --------------------------------------------------------------------------

    protected function getImportService(): ImportService
    {
        return $this->oImportService;
    }
}

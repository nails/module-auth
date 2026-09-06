<?php

namespace Tests\Auth\Stub;

use Nails\Auth\Model\User\Import;
use Nails\Cdn\Service\Cdn;
use Nails\Common\Service\Database;

/**
 * The import model, with its database and CDN swapped for spies
 */
class ImportModelWithSpy extends Import
{
    public function __construct(
        private DatabaseSpy $oSpy,
        private ?CdnSpy $oCdnSpy = null
    ) {
        parent::__construct();
    }

    // --------------------------------------------------------------------------

    protected function getDb(): Database
    {
        return $this->oSpy;
    }

    // --------------------------------------------------------------------------

    protected function getCdn(): Cdn
    {
        return $this->oCdnSpy ?? new CdnSpy();
    }
}

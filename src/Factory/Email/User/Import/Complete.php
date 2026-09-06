<?php

namespace Nails\Auth\Factory\Email\User\Import;

use Nails\Email\Interfaces;
use Nails\Email\Traits;

/**
 * Class Complete
 *
 * @package Nails\Auth\Factory\Email\User\Import
 */
class Complete implements Interfaces\Email
{
    use Traits\Email;

    // --------------------------------------------------------------------------

    public function __construct()
    {
        $this->type('user_import_complete');
    }

    // --------------------------------------------------------------------------

    /**
     * Returns test data to use when sending test emails
     *
     * @return array
     */
    public function getTestData(): array
    {
        return [
            'import'  => [
                'row_count' => 100,
                'success'   => 97,
                'warnings'  => 1,
                'errors'    => 2,
                'url'       => siteUrl('admin/auth/import/preview/1'),
            ],
            /**
             * Null on the happy path; complete() only writes an error here when
             * the log itself could not be attached, or when the app's
             * onImportComplete() hook objected. One block, so no phase line -
             * see Service\User\Import\Processor::summariseError().
             */
            'error'   => null,
            'log_url' => siteUrl('admin/auth/import/log/1'),
        ];
    }
}

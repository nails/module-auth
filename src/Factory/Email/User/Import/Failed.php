<?php

namespace Nails\Auth\Factory\Email\User\Import;

use Nails\Email\Interfaces;
use Nails\Email\Traits;

/**
 * Class Failed
 *
 * @package Nails\Auth\Factory\Email\User\Import
 */
class Failed implements Interfaces\Email
{
    use Traits\Email;

    // --------------------------------------------------------------------------

    public function __construct()
    {
        $this->type('user_import_failed');
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
                'warnings' => 0,
                'errors'   => 3,
                'url'      => siteUrl('admin/auth/import/preview/1'),
            ],
            /**
             * Summary and phase, which is all summariseError() lets through -
             * the quoted rows and the cause stay in the log. Two blocks, because
             * that is the shape the template has to be previewed in.
             */
            'error'   => implode("\n\n", [
                '3 rows in the CSV could not be validated, so no user accounts were created. Correct them and upload the file again.',
                'Phase: validating the CSV (100 of 100 rows checked)',
            ]),
            'log_url' => siteUrl('admin/auth/import/log/1'),
        ];
    }
}

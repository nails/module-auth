<?php

namespace Nails\Auth\Admin\DataExport\Source\User;

use Nails\Admin\DataExport\SourceResponse;
use Nails\Admin\Interfaces\DataExport\Source;
use Nails\Auth\Admin\Permission;
use Nails\Auth\Constants;
use Nails\Config;
use Nails\Factory;

/**
 * Class Email
 *
 * @package Nails\Auth\Admin\DataExport\Source\User
 */
class Email implements Source
{
    /**
     * Returns the format's label
     *
     * @return string
     */
    public function getLabel(): string
    {
        return 'Members: Names and Email';
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the format's file name
     *
     * @return string
     */
    public function getFileName(): string
    {
        return 'members-name-email';
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the format's description
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Export a list of all the site\'s registered users and their email addresses.';
    }

    // --------------------------------------------------------------------------

    /**
     * Returns an array of additional options for the export
     *
     * @return array
     */
    public function getOptions(): array
    {
        return [];
    }

    // --------------------------------------------------------------------------

    /**
     * Provides an opportunity for the source to decide whether it is available or not to the user
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return userHasPermission(Permission\Users\Browse::class);
    }

    // --------------------------------------------------------------------------

    /**
     * Execute the data export
     *
     * @param array $aData Any data to pass to the source
     *
     * @return SourceResponse
     */
    public function execute($aData = [])
    {
        $oDb        = Factory::service('Database');
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);
        $oResponse  = Factory::factory('DataExportSourceResponse', \Nails\Admin\Constants::MODULE_SLUG);

        $oSource = $oDb
            ->select('u.id, u.first_name, u.last_name, ue.email')
            ->join(Config::get('NAILS_DB_PREFIX') . 'user_email ue', 'u.id = ue.user_id AND ue.is_primary = 1', 'LEFT')
            ->get($oUserModel->getTableName() . ' u');

        return $oResponse
            ->setLabel($this->getLabel())
            ->setFileName($this->getFileName())
            ->setFields(['id', 'first_name', 'last_name', 'email'])
            ->setSource($oSource);
    }
}

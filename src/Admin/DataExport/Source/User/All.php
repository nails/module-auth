<?php

namespace Nails\Auth\Admin\DataExport\Source\User;

use Nails\Admin\DataExport\SourceResponse;
use Nails\Admin\Interfaces\DataExport\Source;
use Nails\Auth\Admin\Permission;
use Nails\Auth\Constants;
use Nails\Auth\Model\User;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\ModelException;
use Nails\Common\Model\Base;
use Nails\Common\Service\Database;
use Nails\Config;
use Nails\Factory;

/**
 * Class All
 *
 * @package Nails\Auth\Admin\DataExport\Source\User
 */
class All implements Source
{
    /**
     * Returns the format's label
     *
     * @return string
     */
    public function getLabel(): string
    {
        return 'Members: All';
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the format's file name
     *
     * @return string
     */
    public function getFileName(): string
    {
        return 'members-all';
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the format's description
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Export a list of all the site\'s registered users and their meta data.';
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
        /** @var Database $oDb */
        $oDb = Factory::service('Database');
        /** @var User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);
        /** @var User\Group $oUserGroupModel */
        $oUserGroupModel = Factory::model('UserGroup', Constants::MODULE_SLUG);
        /** @var User\Email $oUserEmailModel */
        $oUserEmailModel = Factory::model('UserEmail', Constants::MODULE_SLUG);

        $aTables = [
            $oUserModel,
            $oUserGroupModel,
            $oUserEmailModel,
        ];

        $aResult = $oDb->query('
            SHOW TABLES
            FROM `' . Config::get('DB_DATABASE') . '`
            WHERE
                `Tables_in_' . Config::get('DB_DATABASE') . '` LIKE "' . Config::get('NAILS_DB_PREFIX') . 'user_meta_%"
                OR `Tables_in_' . Config::get('DB_DATABASE') . '` LIKE "' . Config::get('APP_DB_PREFIX') . 'user_meta_%"
        ')->result();

        foreach ($aResult as $oTable) {
            $aTables[] = $oTable->{'Tables_in_' . Config::get('DB_DATABASE')};
        }

        $aOut = [];
        /** @var string[]|Base[] $mTable */
        foreach ($aTables as $mTable) {

            $aOut[] = Factory::factory('DataExportSourceResponse', \Nails\Admin\Constants::MODULE_SLUG)
                ->setLabel($this->compileLabel($mTable))
                ->setFileName($this->compileFilename($mTable))
                ->setFields($this->compileColumns($mTable))
                ->setSource($this->compileQuery($mTable));
        }

        return $aOut;
    }

    // --------------------------------------------------------------------------

    /**
     * Compiles the label for the model/table
     *
     * @param Base|string $mTable The table or model in question
     *
     * @return string
     * @throws ModelException
     */
    protected function compileLabel($mTable): string
    {
        return sprintf(
            'Table: %s',
            $mTable instanceof Base
                ? $mTable->getTableName()
                : $mTable
        );
    }

    // --------------------------------------------------------------------------

    /**
     * Compiles the filename for the model/table
     *
     * @param Base|string $mTable The table or model in question
     *
     * @return string
     * @throws ModelException
     */
    protected function compileFilename($mTable): string
    {
        return $mTable instanceof Base
            ? $mTable->getTableName()
            : $mTable;
    }

    // --------------------------------------------------------------------------

    /**
     * Compiles the columns for the model/table
     *
     * @param Base|string $mTable The table or model in question
     *
     * @return array
     * @throws ModelException
     * @throws FactoryException
     */
    protected function compileColumns($mTable): array
    {
        /** @var Database $oDb */
        $oDb = Factory::service('Database');

        if ($mTable instanceof Base) {
            return arrayExtractProperty(
                $oDb->query('DESCRIBE ' . $mTable->getTableName())->result(),
                'Field'
            );

        } else {
            return arrayExtractProperty(
                $oDb->query('DESCRIBE ' . $mTable)->result(),
                'Field'
            );
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Compiles the query for the model/table
     *
     * @param Base|string $mTable The table or model in question
     *
     * @return \CI_DB_mysqli_result
     * @throws FactoryException
     * @throws ModelException
     */
    protected function compileQuery($mTable): \CI_DB_mysqli_result
    {
        /** @var Database $oDb */
        $oDb = Factory::service('Database');

        if ($mTable instanceof Base) {

            /** @var Database $oDb */
            $oDb        = Factory::service('Database');
            $aColumns   = arrayExtractProperty(
                $oDb->query('DESCRIBE ' . $mTable->getTableName())->result(),
                'Field'
            );
            $aSensitive = $mTable->sensitiveFields();

            $aSelect = array_map(function (string $sColumn) use ($aSensitive) {

                return in_array($sColumn, $aSensitive)
                    ? '"[REDACTED]" as ' . $sColumn
                    : '`' . $sColumn . '`';

            }, $aColumns);

            return $oDb
                ->select($aSelect)
                ->from($mTable->getTableName())
                ->get();

        } else {
            return $oDb
                ->from($mTable)
                ->get();
        }
    }
}

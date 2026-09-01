<?php

/**
 * This class provides the ability to import users
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    AdminController
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Auth\Admin\Controller;

use Nails\Admin\Controller\Base;
use Nails\Admin\Factory\Nav;
use Nails\Admin\Helper;
use Nails\Auth\Admin\Permission;
use Nails\Auth\Cdn\MetaData\SystemKey;
use Nails\Auth\Constants;
use Nails\Auth\Model\User;
use Nails\Cdn;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\ModelException;
use Nails\Common\Exception\NailsException;
use Nails\Common\Exception\ValidationException;
use Nails\Common\Service\FormValidation;
use Nails\Common\Service\Input;
use Nails\Factory;
use RuntimeException;
use Throwable;

/**
 * Class Import
 *
 * @package Nails\Auth\Admin\Controller
 */
class Import extends Base
{
    const array IMPORT_BUCKET = [
        'slug'          => 'import-user',
        'is_hidden'     => true,
        'allowed_types' => 'csv',
    ];

    // --------------------------------------------------------------------------

    /**
     * Announces this controller's navGroups
     *
     * @throws FactoryException
     */
    public static function announce(): Nav|array|null
    {
        return null;
    }

    // --------------------------------------------------------------------------

    /**
     * Import users from CSV
     *
     * @return void
     * @throws FactoryException
     */
    public function index(): void
    {
        if (!userHasPermission(Permission\Users\Create::class)) {
            unauthorised();
        }

        // --------------------------------------------------------------------------

        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        /** @var \Nails\Auth\Service\User\Import $oImportService */
        $oImportService = Factory::service('UserImport', Constants::MODULE_SLUG);

        if ($oInput->post()) {
            try {

                if ($oInput->post('action') === 'preview') {
                    $this
                        ->validateUpload()
                        ->renderPreview(
                            $this->uploadCsv()
                        );

                    return;

                } elseif ($oInput->post('action') === 'import') {
                    $this
                        ->validateObject()
                        ->processImport();

                } else {
                    throw new \Exception('Unrecognised action');
                }

            } catch (ValidationException $e) {
                $this->oUserFeedback->error(
                    sprintf(
                        '%s:<div class="alert alert-warning" style="%s">%s</div>',
                        $e->getMessage(),
                        implode(';', [
                            'max-height: 10rem',
                            'overflow: auto',
                            'margin-bottom: 0;',
                        ]),
                        implode('<br>', $e->getData() ?? [])
                    )
                );

            } catch (\Exception $e) {
                $this->oUserFeedback->error($e->getMessage());
            }
        }

        // --------------------------------------------------------------------------

        $this->data['page']->title      = 'Import Users';
        $this->data['additionalFields'] = $oImportService->getAdditionalFields();
        Helper::loadView('index');
    }

    // --------------------------------------------------------------------------

    /**
     * Generates a CSV template to upload
     *
     * @return void
     * @throws FactoryException
     */
    public function template(): void
    {
        /** @var \Nails\Auth\Service\User\Import $oImportService */
        $oImportService = Factory::service('UserImport', Constants::MODULE_SLUG);

        $aKeys    = $oImportService->getKeys();
        $aExample = array_map(
            fn($key) => rtrim(
                trim(
                    sprintf(
                        '%s: %s',
                        in_array(FormValidation::RULE_REQUIRED, $oImportService->getValidationRules($key))
                            ? 'Required'
                            : 'Optional',
                        $oImportService->getExample($key)
                    )
                ),
                ':'
            ),
            $aKeys
        );

        Helper::loadCsv(
            [
                array_combine($aKeys, $aKeys),
                array_combine($aKeys, $aExample),
            ],
            'import-users.csv'
        );
    }

    // --------------------------------------------------------------------------

    /**
     * Validates the CSV file upload
     *
     * @return $this
     * @throws FactoryException
     * @throws ModelException
     * @throws NailsException
     * @throws ValidationException
     */
    protected function validateUpload(): self
    {
        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        /** @var Cdn\Service\Cdn $oCdn */
        $oCdn = Factory::service('Cdn', Cdn\Constants::MODULE_SLUG);

        $aFile = $oInput::file('csv');
        if (empty($aFile)) {
            throw new ValidationException('No file selected for upload');
        }

        if ($aFile['error'] !== UPLOAD_ERR_OK) {
            throw new ValidationException(
                $oCdn::getUploadError($aFile['error'])
            );
        }

        $sMime = $oCdn->getMimeFromFile($aFile['tmp_name']);
        if ($aFile['type'] !== 'text/csv' && $sMime !== 'text/plain') {
            throw new ValidationException(
                'Uploaded file is not a CSV'
            );
        }

        $this->validateData(
            $this->parseCsv($aFile['tmp_name'])
        );

        return $this;
    }

    // --------------------------------------------------------------------------

    /**
     * @return $this
     * @throws FactoryException
     * @throws ModelException
     * @throws NailsException
     * @throws ValidationException
     */
    protected function validateObject(): self
    {
        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        /** @var Cdn\Service\Cdn $oCdn */
        $oCdn = Factory::service('Cdn', Cdn\Constants::MODULE_SLUG);
        /** @var Cdn\Model\CdnObject $oObjectModel */
        $oObjectModel = Factory::model('Object', Cdn\Constants::MODULE_SLUG);

        /** @var Cdn\Resource\CdnObject $oObject */
        $oObject = $oObjectModel->getById((int) $oInput->post('object_id'));
        if (empty($oObject)) {
            throw new RuntimeException(
                'CDN Object does not exist'
            );
        } elseif ($oObject->file->mime !== 'text/csv') {
            throw new RuntimeException(
                'Object is not a CSV'
            );
        }

        $sPath = $oCdn->objectLocalPath($oObject->id);
        if (empty($sPath)) {
            throw new RuntimeException(
                'Failed to get a local path for CSV file.'
            );
        }

        $this->validateData(
            $this->parseCsv($sPath)
        );

        return $this;
    }

    // --------------------------------------------------------------------------

    /**
     * Validates the CSV data
     *
     * @param array $aData The data to validate
     *
     * @return $this
     * @throws FactoryException
     * @throws ValidationException
     * @throws ModelException
     * @throws NailsException
     */
    protected function validateData(array $aData): self
    {
        /** @var \Nails\Auth\Service\User\Import $oImportService */
        $oImportService = Factory::service('UserImport', Constants::MODULE_SLUG);
        /** @var FormValidation $oFormValidationService */
        $oFormValidationService = Factory::service('FormValidation');

        $aHeader = array_splice($aData, 0, 1);
        $aHeader = reset($aHeader);

        //  Validate Header Row
        if (empty($aHeader)) {
            throw new ValidationException(
                'Missing header row'
            );
        }

        $aKeys = $oImportService->getKeys();
        $aDiff = array_diff($aHeader, $aKeys);
        if (!empty($aDiff)) {
            throw new ValidationException(sprintf(
                'Header row contains the following invalid values: %s',
                implode(', ', $aDiff)
            ));
        }

        //  Validate data
        $aErrors = [];
        $iLines  = 2;   //  Skip the header

        //  Key the rules by the header's own columns; the CSV need not use every
        //  key, nor list them in the same order as getKeys()
        $aValidationRules = array_filter(
            array_map(
                fn($sKey) => $oImportService->getValidationRules($sKey),
                array_combine($aHeader, $aHeader)
            )
        );

        foreach ($aData as $aDatum) {

            try {

                $aDatum = array_combine($aHeader, $aDatum);
                $aDatum = array_map('trim', $aDatum);

                //  Basic validation
                $oFormValidationService
                    ->buildValidator(
                        aRules: $aValidationRules,
                        aData: $aDatum
                    )
                    ->run();

            } catch (ValidationException $e) {

                foreach ($e->getData() as $key => $error) {
                    $aErrors[] = sprintf(
                        'Line %d: %s: %s',
                        $iLines,
                        $key,
                        $error
                    );
                }

            } catch (Throwable $e) {
                $aErrors[] = sprintf(
                    'Error on line %d: %s',
                    $iLines,
                    $e->getMessage()
                );
            }

            $iLines++;
        }

        //  Duplicate detection cannot live in the per-field rules; those only ever
        //  see a single value, and the validator abandons a field's remaining rules
        //  as soon as one of them fails. It's a whole-of-file concern, so handle it
        //  as one pass over the parsed data.
        $aErrors = array_merge(
            $aErrors,
            $this->detectDuplicates($aHeader, $aData)
        );

        if (!empty($aErrors)) {

            $message = count($aErrors) === 1
                ? '1 error was found in the CSV file'
                : sprintf('%d errors were found in the CSV file', count($aErrors));

            throw (new ValidationException($message))
                ->setData($aErrors);
        }

        return $this;
    }

    // --------------------------------------------------------------------------

    /**
     * Detects values which are duplicated within the CSV itself
     *
     * @param array $aHeader The CSV's header row
     * @param array $aData   The CSV's data rows, header removed
     *
     * @return string[]
     * @throws FactoryException
     */
    protected function detectDuplicates(array $aHeader, array $aData): array
    {
        /** @var \Nails\Auth\Service\User\Import $oImportService */
        $oImportService = Factory::service('UserImport', Constants::MODULE_SLUG);

        $aErrors = [];

        foreach ($oImportService->getUniqueKeys() as $sKey) {

            $iColumn = array_search($sKey, $aHeader, true);
            if ($iColumn === false) {
                continue;
            }

            $aSeen = [];

            foreach ($aData as $iIndex => $aDatum) {

                $sValue = strtolower(trim($aDatum[$iColumn] ?? ''));
                if ($sValue === '') {
                    continue;
                }

                $iLine = $iIndex + 2;   //  Lines are 1 indexed, and the header is line 1

                if (array_key_exists($sValue, $aSeen)) {
                    $aErrors[] = sprintf(
                        'Line %d: %s: "%s" must only appear once; it is also on line %d',
                        $iLine,
                        $sKey,
                        $sValue,
                        $aSeen[$sValue]
                    );
                } else {
                    $aSeen[$sValue] = $iLine;
                }
            }
        }

        return $aErrors;
    }

    // --------------------------------------------------------------------------

    /**
     * Parses the CSV file into an array
     *
     * @param string $sPath The path to the CSV
     *
     * @return array
     */
    protected function parseCsv(string $sPath): array
    {
        return array_map(
            fn($line) => str_getcsv($line, escape: ''),
            file($sPath)
        );
    }

    // --------------------------------------------------------------------------

    /**
     * @return Cdn\Resource\CdnObject
     * @throws FactoryException
     * @throws ModelException
     * @throws ValidationException
     */
    protected function uploadCsv(): Cdn\Resource\CdnObject
    {
        /** @var Cdn\Service\Cdn $oCdn */
        $oCdn = Factory::service('Cdn', Cdn\Constants::MODULE_SLUG);
        /** @var Cdn\Model\CdnObject $oObjectModel */
        $oObjectModel = Factory::model('Object', Cdn\Constants::MODULE_SLUG);

        $oObject = $oCdn->objectCreate(
            'csv',
            self::IMPORT_BUCKET,
            [
                'Content-Type' => 'text/csv',
                'metadata'     => [
                    [
                        'key'   => (new SystemKey\UserImport)->get(),
                        'value' => true,
                    ],
                ],
            ]
        );

        if (!$oObject) {
            throw new ValidationException(sprintf(
                'Failed to upload CSV; %s',
                $oCdn->lastError()
            ));
        }

        /** @var Cdn\Resource\CdnObject $oObject */
        $oObject = $oObjectModel->getById($oObject->id);
        return $oObject;
    }

    // --------------------------------------------------------------------------

    /**
     * @param Cdn\Resource\CdnObject $oObject
     *
     * @return void
     * @throws FactoryException
     * @throws NailsException
     */
    protected function renderPreview(Cdn\Resource\CdnObject $oObject): void
    {
        /** @var \Nails\Auth\Service\User\Import $oImportService */
        $oImportService = Factory::service('UserImport', Constants::MODULE_SLUG);
        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        /** @var Cdn\Service\Cdn $oCdn */
        $oCdn = Factory::service('Cdn', Cdn\Constants::MODULE_SLUG);

        $sPath = $oCdn->objectLocalPath($oObject->id);
        if (empty($sPath)) {
            throw new RuntimeException(
                'Failed to get a local path for CSV file'
            );
        }

        $aData = $this->parseCsv($sPath);

        $aKeys   = $oImportService->getKeys();
        $aHeader = array_splice($aData, 0, 1);
        $aHeader = reset($aHeader);

        foreach ($aData as &$aDatum) {
            $aDatum = array_combine($aHeader, $aDatum);
            foreach ($aKeys as $sField) {
                if ($aDatum[$sField] === '') {
                    $aDatum[$sField] = $oImportService->getDefaultValue($sField);
                }
            }
        }

        $this->data['aKeys']       = $aKeys;
        $this->data['aHeader']     = $aHeader;
        $this->data['aData']       = $aData;
        $this->data['oObject']     = $oObject;
        $this->data['oAdditional'] = (object) $oInput->post('additional');

        $this->data['page']->title = 'Import Users: Preview (' . count($aData) . ')';

        Helper::loadView('preview');
    }

    // --------------------------------------------------------------------------

    /**
     * Process the import
     *
     * @return void
     * @throws FactoryException
     * @throws NailsException
     */
    protected function processImport(): void
    {
        /** @var \Nails\Auth\Service\User\Import $oImportService */
        $oImportService = Factory::service('UserImport', Constants::MODULE_SLUG);
        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        /** @var Cdn\Service\Cdn $oCdn */
        $oCdn = Factory::service('Cdn', Cdn\Constants::MODULE_SLUG);
        /** @var User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);

        $iObjectId   = (int) $oInput->post('object_id');
        $oAdditional = json_decode($oInput->post('additional'));

        $sPath = $oCdn->objectLocalPath($iObjectId);
        if (empty($sPath)) {
            throw new RuntimeException(
                'Failed to get a local path for CSV file.'
            );
        }

        $aKeys    = $oImportService->getKeys();
        $aData    = $this->parseCsv($sPath);
        $aHeader  = array_splice($aData, 0, 1);
        $aHeader  = reset($aHeader);
        $iSuccess = 0;
        $iError   = 0;
        $aLog     = [];

        foreach ($aData as $aDatum) {

            $aDatum     = array_combine($aHeader, $aDatum);
            $bSendEmail = stringToBoolean($aDatum['send_email'] ?? false);

            $aUserData = [];
            foreach ($aKeys as $sKey) {
                $aUserData[$sKey] = $aDatum[$sKey] ?? null;
                if ($aUserData[$sKey] === '') {
                    $aUserData[$sKey] = $oImportService->getDefaultValue($sKey);
                }
            }

            //  Apply additional fields
            foreach ($oAdditional as $oProperty => $mValue) {
                $aUserData[$oProperty] = $oImportService->parseAdditionalFields($oProperty, $mValue);
            }

            try {

                $oUser = $oUserModel->create($aUserData, $bSendEmail);

                if ($oUser) {
                    $iSuccess++;
                    $aLog[] = array_merge(
                        $aDatum,
                        [
                            'id'      => $oUser->id,
                            'status'  => 'SUCCESS',
                            'message' => '',
                        ]
                    );
                } else {
                    $iError++;
                    $aLog[] = array_merge(
                        $aDatum,
                        [
                            'id'      => null,
                            'status'  => 'ERROR',
                            'message' => $oUserModel->lastError(),
                        ]
                    );
                }

            } catch (Throwable $e) {
                $iError++;
                $aLog[] = array_merge(
                    $aDatum,
                    [
                        'id'      => null,
                        'status'  => 'ERROR',
                        'message' => $e->getMessage(),
                    ]
                );
            }
        }

        array_unshift($aLog, array_merge(
            $aHeader,
            [
                'id',
                'status',
                'message',
            ]
        ));

        //  Convert array to CSV and save to cDN
        $fp = fopen('php://temp', 'r+');
        foreach ($aLog as $row) {
            fputcsv($fp, $row, escape: '');
        }
        rewind($fp);
        $csvLog = stream_get_contents($fp);
        fclose($fp);

        /** @var \DateTime $oNow */
        $oNow = Factory::factory('DateTime');
        $oLog = $oCdn->objectCreate(
            $csvLog,
            self::IMPORT_BUCKET,
            [
                'no-md5-check'     => true,
                'Content-Type'     => 'text/csv',
                'filename_display' => sprintf(
                    'user-import-log-%s.csv',
                    $oNow->format('Y-m-d_H-i-s')
                ),
                'metadata'         => [
                    [
                        'key'   => (new SystemKey\UserImport)->get(),
                        'value' => true,
                    ],
                    [
                        'key'   => (new SystemKey\ImportedFrom)->get(),
                        'value' => $iObjectId,
                    ],
                ],
            ],
            true
        );

        if (!empty($iSuccess)) {
            $this->oUserFeedback->success(sprintf(
                '%s user accounts created successfully. <a href="%s" style="text-decoration: underline">See log for details.</a>',
                $iSuccess,
                cdnServe($oLog->id, true)
            ));
        }

        if (!empty($iError)) {
            $this->oUserFeedback->error(sprintf(
                '%s user accounts encountered errors. <a href="%s" style="text-decoration: underline">See log for details.</a>',
                $iError,
                cdnServe($oLog->id, true)
            ));
        }

        redirect(self::url());
    }
}

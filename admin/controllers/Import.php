<?php

/**
 * This class provides the ability to merge users
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    AdminController
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Admin\Auth;

use Nails\Admin\Factory\Nav;
use Nails\Admin\Helper;
use Nails\Auth\Constants;
use Nails\Auth\Controller\BaseAdmin;
use Nails\Auth\Model\User;
use Nails\Cdn;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\ValidationException;
use Nails\Common\Factory\Model\Field;
use Nails\Common\Service\DateTime;
use Nails\Common\Service\FormValidation;
use Nails\Common\Service\Input;
use Nails\Common\Service\View;
use Nails\Config;
use Nails\Factory;
use stdClass;

/**
 * Class Import
 *
 * @package Nails\Admin\Auth
 */
class Import extends BaseAdmin
{
    /**
     * Merge users
     *
     * @return void
     * @throws FactoryException
     */
    public function index(): void
    {
        if (!userHasPermission('admin:auth:accounts:create')) {
            unauthorised();
        }

        // --------------------------------------------------------------------------

        /** @var Input $oInput */
        $oInput = Factory::service('Input');

        if ($oInput->post()) {
            try {

                if ($oInput->post('action') === 'preview') {
                    $this
                        ->validateUpload()
                        ->renderPreview(
                            $this->uploadCsv(),
                            (bool) $oInput::post('skip_existing')
                        );
                    return;

                } elseif ($oInput->post('action') === 'import') {
                    $this
                        ->validateObject()
                        ->processImport(
                            (bool) $oInput::post('skip_existing')
                        );

                } else {
                    throw new \Exception('Unrecognised action');
                }

            } catch (ValidationException $e) {
                $this->oUserFeedback->error($e->getMessage());
            } catch (\Exception $e) {
                $this->oUserFeedback->error($e->getMessage());
            }
        }

        // --------------------------------------------------------------------------

        $this->data['page']->title = 'Import Users';
        Helper::loadView('index');
    }

    // --------------------------------------------------------------------------

    /**
     * Generates a CSV template to upload
     *
     * @throws FactoryException
     */
    public function template()
    {
        /** @var User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);
        $aFields    = $this->getFields();

        Helper::loadCsv(
            [
                array_combine(
                    array_keys($aFields),
                    array_keys($aFields)
                ),
                array_combine(
                    array_keys($aFields),
                    [
                        sprintf(
                            '%s: user@example.com',
                            $aFields['email'] ? 'Required' : 'Optional'
                        ),
                        sprintf(
                            '%s: user_example',
                            $aFields['username'] ? 'Required' : 'Optional'
                        ),
                        'Optional: if not set, default user group is used',
                        'Optional: automatically generated if not set',
                        'Required: 1 for yes, 0 for no',
                        'Required: 1 for yes, 0 for no',
                        'Optional',
                        'Optional',
                        'Optional',
                        'Optional: Blank, or one of: ' . implode(', ', array_keys($oUserModel->getGenders())),
                        'Optional: Blank, or date in format YYYY-MM-DD',
                        'Optional: Blank, or PHP timezone (as documented https://www.php.net/manual/en/timezones.php)',
                    ]
                ),
            ],
            'import-users.csv',
            true
        );
    }

    // --------------------------------------------------------------------------

    /**
     * Returns CSV fields and whether they are required or not
     *
     * @return bool[string]
     */
    protected function getFields(): array
    {
        return [
            'email'      => in_array(Config::get('APP_NATIVE_LOGIN_USING'), ['EMAIL', 'BOTH']),
            'username'   => in_array(Config::get('APP_NATIVE_LOGIN_USING'), ['USERNAME', 'BOTH']),
            'group_id'   => false,
            'password'   => false,
            'temp_pw'    => true,
            'send_email' => true,
            'salutation' => false,
            'first_name' => false,
            'last_name'  => false,
            'gender'     => false,
            'dob'        => false,
            'timezone'   => false,
        ];
    }

    // --------------------------------------------------------------------------

    /**
     * Validates the CSV file upload
     *
     * @return $this
     * @throws FactoryException
     * @throws ValidationException
     * @throws \Nails\Common\Exception\ModelException
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
            $this->parseCsv($aFile['tmp_name']),
            (bool) $oInput::post('skip_existing')
        );

        return $this;
    }

    // --------------------------------------------------------------------------

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
            throw new ValidationException(
                'CDN Object does not exist'
            );
        } elseif ($oObject->file->mime !== 'text/csv') {
            throw new ValidationException(
                'Object is not a CSV'
            );
        }

        $sPath = $oCdn->objectLocalPath($oObject->id);
        if (empty($sPath)) {
            throw new \RuntimeException(
                'Failed to get a local path for CSV file.'
            );
        }

        $this->validateData(
            $this->parseCsv($sPath),
            (bool) $oInput::post('skip_existing')
        );

        return $this;
    }

    // --------------------------------------------------------------------------

    /**
     * Validates the CSV data
     *
     * @param array $aData         The data to validate
     * @param bool  $bskipExisting Whether to skip existing users, or to error
     *
     * @return $this
     * @throws FactoryException
     * @throws ValidationException
     * @throws \Nails\Common\Exception\ModelException
     */
    protected function validateData(array $aData, bool $bSkipExisting): self
    {
        /** @var User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);
        /** @var User\Password $oPasswordModel */
        $oPasswordModel = Factory::model('UserPassword', Constants::MODULE_SLUG);
        /** @var User\Group $oGroupModel */
        $oGroupModel = Factory::model('UserGroup', Constants::MODULE_SLUG);
        /** @var DateTime $oDateTimeService */
        $oDateTimeService = Factory::service('DateTime');

        $aGroups = $oGroupModel->getAllFlat();
        $aHeader = array_splice($aData, 0, 1);
        $aHeader = reset($aHeader);

        if (empty($aHeader)) {
            throw new ValidationException(
                'Missing header row'
            );
        }

        $aFields         = $this->getFields();
        $aRequiredFields = array_keys(array_filter($aFields));

        $aDiff = array_diff($aHeader, array_keys($aFields));
        if (!empty($aDiff)) {
            throw new ValidationException(sprintf(
                'Header row contains the following invalid values: %s',
                implode(', ', $aDiff)
            ));
        }

        $aEmails    = [];
        $aUsernames = [];

        foreach ($aData as $iIndex => $aDatum) {

            $aDatum = array_combine($aHeader, $aDatum);
            $aDatum = array_map('trim', $aDatum);

            try {

                foreach ($aRequiredFields as $sRequiredField) {
                    if (($aDatum[$sRequiredField] ?? '') === '') {
                        throw new ValidationException(
                            sprintf(
                                'Required field `%s` not set.',
                                $sRequiredField
                            )
                        );
                    }
                }

                if (!empty($aDatum['email'])) {
                    if (!filter_var($aDatum['email'], FILTER_VALIDATE_EMAIL)) {
                        throw new ValidationException(sprintf(
                            '"%s" is not a valid email address.',
                            $aDatum['email']
                        ));
                    }

                    if (array_key_exists($aDatum['email'], $aEmails)) {
                        throw new ValidationException(sprintf(
                            'Email "%s" is a duplicate item in this list, first seen on line %s.',
                            $aDatum['email'],
                            $aEmails[$aDatum['email']]
                        ));
                    } else {
                        $aEmails[$aDatum['email']] = $iIndex + 2;
                    }

                    if (!$bSkipExisting && $oUserModel->getByEmail($aDatum['email'])) {
                        throw new ValidationException(sprintf(
                            '"%s" is already a registered email.',
                            $aDatum['email']
                        ));
                    }
                }

                if (!empty($aDatum['username'])) {
                    if (!$oUserModel->isValidUsername($aDatum['username'])) {
                        throw new ValidationException(sprintf(
                            '"%s" is not a valid username; %s',
                            $aDatum['username'],
                            $oUserModel->lastError()
                        ));
                    }

                    if (array_key_exists($aDatum['username'], $aUsernames)) {
                        throw new ValidationException(sprintf(
                            'Username "%s" is a duplicate item in this list, first seen on line %s.',
                            $aDatum['username'],
                            $aUsernames[$aDatum['username']]
                        ));
                    } else {
                        $aUsernames[$aDatum['username']] = $iIndex + 2;
                    }

                    if (!$bSkipExisting && $oUserModel->getByUsername($aDatum['username'])) {
                        throw new ValidationException(sprintf(
                            '"%s" is already a registered username.',
                            $aDatum['email']
                        ));
                    }
                }

                if (!empty($aDatum['group_id'])) {
                    if (!array_key_exists($aDatum['group_id'], $aGroups)) {
                        throw new ValidationException(
                            'Invalid user group ID.',
                        );
                    }
                }

                if (!empty($aDatum['password'])) {
                    if (!$oPasswordModel->isAcceptable($aDatum['group_id'] ?: $oGroupModel->getDefaultGroupId(), $aDatum['password'])) {
                        throw new ValidationException(
                            'Password is not acceptable.',
                        );
                    }
                }

                if (!empty($aDatum['temp_pw'])) {
                    if (!in_array($aDatum['temp_pw'], ['0', '1'])) {
                        throw new ValidationException(sprintf(
                            'Invalid value "%s" for field `temp_pw`; must be 0 or 1.',
                            $aDatum['temp_pw']
                        ));
                    }
                }

                if (!empty($aDatum['send_email'])) {
                    if (!in_array($aDatum['send_email'], ['0', '1'])) {
                        throw new ValidationException(sprintf(
                            'Invalid value "%s" for field `send_temp`; must be 0 or 1.',
                            $aDatum['send_email']
                        ));
                    }
                }

                if (!empty($aDatum['gender'])) {
                    if (!in_array($aDatum['gender'], array_keys($oUserModel->getGenders()))) {
                        throw new ValidationException(sprintf(
                            'Invalid value "%s" for field `gender`; must be %s.',
                            $aDatum['gender'],
                            implode(', ', array_keys($oUserModel->getGenders()))
                        ));
                    }
                }

                if (!empty($aDatum['dob'])) {
                    try {
                        $oDate = new \DateTime($aDatum['dob']);
                        if (empty($oDate) || $oDate->format('Y-m-d') !== $aDatum['dob']) {
                            throw new \Exception();
                        }
                    } catch (\Exception $e) {
                        throw new ValidationException(sprintf(
                            'Invalid value "%s" for field `dob`; must be a date in the format YYYY-MM-DD',
                            $aDatum['dob']
                        ));
                    }
                }

                if (!empty($aDatum['timezone'])) {
                    if (!in_array($aDatum['timezone'], array_keys($oDateTimeService->getAllTimezoneFlat()))) {
                        throw new ValidationException(sprintf(
                            'Invalid value "%s" for field `timezone`; must be a valid PHP timezone.',
                            $aDatum['timezone'],
                        ));
                    }
                }

            } catch (\Exception $e) {
                throw new ValidationException(
                    sprintf(
                        'Error at line %s: %s <pre style="padding: 1rem;margin-top:0.5rem;">%s</pre>',
                        $iIndex + 2,
                        $e->getMessage(),
                        implode(', ', $aDatum)
                    )
                );
            }
        }

        return $this;
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
        return array_map('str_getcsv', file($sPath));
    }

    // --------------------------------------------------------------------------

    protected function uploadCsv(): Cdn\Resource\CdnObject
    {
        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        /** @var Cdn\Service\Cdn $oCdn */
        $oCdn = Factory::service('Cdn', Cdn\Constants::MODULE_SLUG);
        /** @var Cdn\Model\CdnObject $oObjectModel */
        $oObjectModel = Factory::model('Object', Cdn\Constants::MODULE_SLUG);

        $aFile = $oInput::file('csv');

        $oObject = $oCdn->objectCreate(
            'csv',
            [
                'slug'      => 'import-user',
                'is_hidden' => true,
            ],
            [
                'Content-Type' => 'text/csv',
            ]
        );

        if (!$oObject) {
            throw new ValidationException(sprintf(
                'Failed to upload CSV; %s',
                $oCdn->lastError()
            ));
        }

        return $oObjectModel->getById($oObject->id);
    }

    // --------------------------------------------------------------------------

    protected function renderPreview(Cdn\Resource\CdnObject $oObject, bool $bSkipExisting): void
    {
        /** @var Cdn\Service\Cdn $oCdn */
        $oCdn = Factory::service('Cdn', Cdn\Constants::MODULE_SLUG);

        $sPath = $oCdn->objectLocalPath($oObject->id);
        if (empty($sPath)) {
            throw new \RuntimeException(
                'Failed to get a local path for CSV file'
            );
        }

        $aData = $this->parseCsv($sPath);

        $aFields = $this->getFields();
        $aHeader = array_splice($aData, 0, 1);
        $aHeader = reset($aHeader);

        //  Highlight items which will be skipped
        foreach ($aData as &$aDatum) {

            $aDatum = array_combine($aHeader, $aDatum);

            if ($bSkipExisting && $this->shouldSkip($aDatum)) {

                $aIdentifiers = array_filter([
                    array_key_exists('email', $aDatum)
                        ? 'email "' . $aDatum['email'] . '"'
                        : null,
                    array_key_exists('username', $aDatum)
                        ? 'username "' . $aDatum['username'] . '"'
                        : null,
                ]);

                $aDatum = sprintf(
                    'Item with %s is already registered and will be skipped',
                    implode(' and ', $aIdentifiers)
                );
                continue;
            }
        }

        $this->data['aFields']       = array_keys($aFields);
        $this->data['aHeader']       = $aHeader;
        $this->data['aData']         = $aData;
        $this->data['oObject']       = $oObject;
        $this->data['bSkipExisting'] = $bSkipExisting;
        $this->data['page']->title   = 'Import Users: Preview (' . count($aData) . ')';

        Helper::loadView('preview');
    }

    // --------------------------------------------------------------------------

    /**
     * Process the import
     *
     * @throws FactoryException
     */
    protected function processImport(bool $bSkipExisting): void
    {
        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        /** @var Cdn\Service\Cdn $oCdn */
        $oCdn = Factory::service('Cdn', Cdn\Constants::MODULE_SLUG);
        /** @var User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);
        /** @var User\Group $oGroupModel */
        $oGroupModel = Factory::model('UserGroup', Constants::MODULE_SLUG);

        $iObjectId = (int) $oInput->post('object_id');
        $sPath     = $oCdn->objectLocalPath((int) $oInput->post('object_id'));
        if (empty($sPath)) {
            throw new \RuntimeException(
                'Failed to get a local path for CSV file.'
            );
        }

        $aData    = $this->parseCsv($sPath);
        $aHeader  = array_splice($aData, 0, 1);
        $aHeader  = reset($aHeader);
        $iSuccess = 0;
        $iSkipped = 0;
        $iError   = 0;
        $aLog     = [];

        foreach ($aData as $iIndex => $aDatum) {

            $aDatum = array_combine($aHeader, $aDatum);

            if ($bSkipExisting && $this->shouldSkip($aDatum)) {
                $iSkipped++;
                $aLog[] = array_merge(
                    $aDatum,
                    [
                        'id'      => null,
                        'status'  => 'SKIPPED',
                        'message' => 'This item is already registered and was skipped',
                    ]
                );
                continue;
            }

            $bSendEmail = (bool) $aDatum['send_email'];
            $aUserData  = array_filter([
                'group_id'   => ($aDatum['group_id'] ?? null) ?: $oGroupModel->getDefaultGroupId(),
                'email'      => trim(($aDatum['email'] ?? null)) ?: null,
                'username'   => trim(($aDatum['username'] ?? null)) ?: null,
                'temp_pw'    => (bool) ($aDatum['temp_pw'] ?? true),
                'salutation' => trim(($aDatum['salutation'] ?? null)) ?: null,
                'first_name' => trim(($aDatum['first_name'] ?? null)) ?: null,
                'last_name'  => trim(($aDatum['last_name'] ?? null)) ?: null,
                'gender'     => trim(($aDatum['gender'] ?? null)) ?: null,
                'dob'        => trim(($aDatum['dob'] ?? null)) ?: null,
                'timezone'   => trim(($aDatum['timezone'] ?? null)) ?: null,
            ]);

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

            } catch (\Exception $e) {
                $iError++;
                $aLog[] = array_merge(
                    $aDatum,
                    [
                        'id'      => null,
                        'status'  => 'ERROR',
                        'message' => $e->getMessage(),
                    ]
                );
            } catch (\Error $e) {
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

        $aLog = array_map(function ($aItem) {

            $aFields = array_map(function ($sItem) {
                return str_replace('"', '""', trim($sItem));
            }, $aItem);

            return '"' . implode('","', $aFields) . '"';

        }, $aLog);

        /** @var \DateTime $oNow */
        $oNow = Factory::factory('DateTime');
        $oLog = $oCdn->objectCreate(
            implode(PHP_EOL, $aLog),
            [
                'slug'      => 'import-user',
                'is_hidden' => true,
            ],
            [
                'no-md5-check'     => true,
                'Content-Type'     => 'text/csv',
                'filename_display' => sprintf(
                    'user-import-log-%s.csv',
                    $oNow->format('Y-m-d_H-i-s')
                ),
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

        if (!empty($iSkipped)) {
            $this->oUserFeedback->warning(sprintf(
                '%s user accounts skipped. <a href="%s" style="text-decoration: underline">See log for details.</a>',
                $iSkipped,
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

        $oCdn->objectDestroy($iObjectId);

        redirect('admin/auth/import');
    }

    // --------------------------------------------------------------------------

    /**
     * Returns whether the item should be skipped because it already exists
     *
     * @param array $aDatum The datum to check
     *
     * @return bool
     * @throws FactoryException
     * @throws \Nails\Common\Exception\ModelException
     */
    protected function shouldSkip(array $aDatum): bool
    {
        /** @var User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);

        if (array_key_exists('email', $aDatum) && $oUserModel->getByEmail($aDatum['email'])) {
            return true;
        }

        if (array_key_exists('username', $aDatum) && $oUserModel->getByEmail($aDatum['username'])) {
            return truel;
        }

        return false;
    }
}

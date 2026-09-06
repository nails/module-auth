<?php

/**
 * Validation for user import CSVs.
 *
 * This is shared by the upload - which checks the template is usable, validates
 * the header, validates every row, and hunts for in-file duplicates - and by the
 * background runner's VALIDATING phase, which repeats the row checks a chunk at a
 * time because the file may have sat in the CDN for a while.
 *
 * Row problems come in two kinds, and the callers treat them differently: a row
 * which is *wrong* (validateRows) means the CSV must be corrected, so the upload
 * is rejected outright; a row which is merely *redundant* (detectRegistered)
 * means the file is fine and the row is already accounted for, so the admin is
 * offered the chance to skip it.
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    Service
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Auth\Service\User\Import;

use Nails\Auth\Constants;
use Nails\Auth\Exception\User\Import\TemplateException;
use Nails\Auth\Service\User\Import as ImportService;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\NailsException;
use Nails\Common\Exception\ValidationException;
use Nails\Common\Service\FormValidation;
use Nails\Config;
use Nails\Factory;
use Throwable;

/**
 * Class Validator
 *
 * @package Nails\Auth\Service\User\Import
 */
class Validator
{
    /**
     * Asserts that the import template can actually produce a usable account
     *
     * The template is the app's to amend - see Service\User\Import - which means
     * an app can remove the very column an account is identified by. Model\User
     * ::create() enforces identity from APP_NATIVE_LOGIN_USING, so without this
     * the removal is not noticed until every single row fails at creation with
     * "An email address must be supplied."
     *
     * Phrased against the login mode rather than hard-coded, so that legitimate
     * customisation still works: an app logging in by email alone is perfectly
     * entitled to drop the username column.
     *
     * @throws TemplateException If the template cannot identify a user
     * @throws FactoryException
     */
    public function validateTemplate(): void
    {
        $oImportService = $this->getImportService();

        $aKeys       = $oImportService->getKeys();
        $aUniqueKeys = $oImportService->getUniqueKeys();

        foreach ($this->getIdentityKeys() as $sKey) {

            if (!in_array($sKey, $aKeys, true)) {
                throw new TemplateException(sprintf(
                    'The user import template cannot be used: accounts are identified by "%s" '
                    . '(APP_NATIVE_LOGIN_USING is "%s"), but it is not among the columns returned '
                    . 'by %s::getKeys().',
                    $sKey,
                    (string) Config::get('APP_NATIVE_LOGIN_USING'),
                    $oImportService::class
                ));
            }

            if (!in_array($sKey, $aUniqueKeys, true)) {
                throw new TemplateException(sprintf(
                    'The user import template cannot be used: accounts are identified by "%s" '
                    . '(APP_NATIVE_LOGIN_USING is "%s"), so it must be among the columns returned '
                    . 'by %s::getUniqueKeys().',
                    $sKey,
                    (string) Config::get('APP_NATIVE_LOGIN_USING'),
                    $oImportService::class
                ));
            }
        }
    }

    // --------------------------------------------------------------------------

    /**
     * The keys an account cannot be created without
     *
     * Mirrors the branches of Model\User::create(); note that BOTH means both are
     * needed, not either.
     *
     * @return string[]
     */
    protected function getIdentityKeys(): array
    {
        return match (Config::get('APP_NATIVE_LOGIN_USING')) {
            'EMAIL'    => ['email'],
            'USERNAME' => ['username'],
            default    => ['email', 'username'],
        };
    }

    // --------------------------------------------------------------------------

    /**
     * Validates the CSV's header row
     *
     * @param string[] $aHeader The header row
     *
     * @throws ValidationException
     * @throws FactoryException
     */
    public function validateHeader(array $aHeader): void
    {
        $oImportService = $this->getImportService();

        if (empty($aHeader)) {
            throw new ValidationException(
                'Missing header row'
            );
        }

        $aDiff = array_diff($aHeader, $oImportService->getKeys());
        if (!empty($aDiff)) {
            throw new ValidationException(sprintf(
                'Header row contains the following invalid values: %s',
                implode(', ', $aDiff)
            ));
        }

        /**
         * The diff above only catches columns we do not recognise; a CSV may
         * legitimately omit most of them, and the per-field rules only apply to
         * the columns actually supplied. The identity columns are the exception:
         * without them Model\User::create() cannot make an account, so a file
         * which omits one used to pass here and then fail on every single row.
         */
        $aMissing = array_diff($this->getIdentityKeys(), $aHeader);
        if (!empty($aMissing)) {
            throw new ValidationException(sprintf(
                'Header row is missing the following %s, without which an account cannot be identified: %s',
                count($aMissing) === 1 ? 'column' : 'columns',
                implode(', ', $aMissing)
            ));
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Validates a set of raw data rows against the import service's rules
     *
     * @param string[]                 $aHeader The CSV's header row
     * @param iterable<int, string[]>  $aRows   Raw rows, keyed by line number
     *
     * @return array<int, string[]> The errors, keyed by line number
     * @throws FactoryException
     * @throws NailsException
     */
    public function validateRows(array $aHeader, iterable $aRows): array
    {
        $oImportService = $this->getImportService();
        /** @var FormValidation $oFormValidationService */
        $oFormValidationService = Factory::service('FormValidation');
        /** @var Csv $oCsv */
        $oCsv = Factory::service('UserImportCsv', Constants::MODULE_SLUG);

        /**
         * Key the rules by the header's own columns; the CSV need not use every
         * key, nor list them in the same order as getKeys()
         */
        $aValidationRules = array_filter(
            array_map(
                fn($sKey) => $oImportService->getValidationRules($sKey),
                array_combine($aHeader, $aHeader)
            )
        );

        /**
         * One validator, reused per row; each run() replaces the previous
         * result so errors never leak between rows.
         */
        $oRowValidator = $oFormValidationService->buildValidator(
            aRules: $aValidationRules,
            aData: []
        );

        $aErrors = [];

        foreach ($aRows as $iLine => $aRow) {

            $aLineErrors = [];

            if (count($aRow) !== count($aHeader)) {
                $aLineErrors[] = sprintf(
                    'Row has %d columns, the header has %d',
                    count($aRow),
                    count($aHeader)
                );
            }

            try {

                $oRowValidator->run($oCsv->align($aHeader, $aRow));

            } catch (ValidationException $e) {

                foreach ($e->getData() as $sKey => $sError) {
                    $aLineErrors[] = sprintf('%s: %s', $sKey, $sError);
                }

            } catch (Throwable $e) {
                $aLineErrors[] = $e->getMessage();
            }

            if (!empty($aLineErrors)) {
                $aErrors[$iLine] = $aLineErrors;
            }
        }

        return $aErrors;
    }

    // --------------------------------------------------------------------------

    /**
     * Detects values which are duplicated within the CSV itself
     *
     * Duplicate detection cannot live in the per-field rules; those only ever
     * see a single value, and the validator abandons a field's remaining rules
     * as soon as one of them fails. It's a whole-of-file concern, so handle it
     * as one streaming pass over the file, holding only the values seen so far
     * for the unique keys.
     *
     * @param string $sPath The path to the CSV
     *
     * @return string[]
     * @throws FactoryException
     */
    public function detectDuplicates(string $sPath): array
    {
        $oImportService = $this->getImportService();
        /** @var Csv $oCsv */
        $oCsv = Factory::service('UserImportCsv', Constants::MODULE_SLUG);

        $aHeader = $oCsv->getHeader($sPath);
        $aErrors = [];
        $aSeen   = [];
        $aColumn = [];

        foreach ($oImportService->getUniqueKeys() as $sKey) {

            $iColumn = array_search($sKey, $aHeader, true);
            if ($iColumn === false) {
                continue;
            }

            $aColumn[$sKey] = $iColumn;
            $aSeen[$sKey]   = [];
        }

        if (empty($aColumn)) {
            return [];
        }

        foreach ($oCsv->readRawRows($sPath) as $iLine => $aRow) {
            foreach ($aColumn as $sKey => $iColumn) {

                $sValue = strtolower(trim((string) ($aRow[$iColumn] ?? '')));
                if ($sValue === '') {
                    continue;
                }

                if (array_key_exists($sValue, $aSeen[$sKey])) {
                    $aErrors[] = sprintf(
                        'Line %d: %s: "%s" must only appear once; it is also on line %d',
                        $iLine,
                        $sKey,
                        $sValue,
                        $aSeen[$sKey][$sValue]
                    );
                } else {
                    $aSeen[$sKey][$sValue] = $iLine;
                }
            }
        }

        return $aErrors;
    }

    // --------------------------------------------------------------------------

    /**
     * Detects values which are already registered against an existing account
     *
     * Batched, not per row: one streaming pass collects the unique keys' values,
     * then one lookup per key answers for all of them. The closures this
     * replaced ran a query - two, on a hit - for every line in the file.
     *
     * Takes rows rather than a path so that the upload can hand it the whole
     * file as a generator while the runner hands it a chunk.
     *
     * Note that this is an early warning, not the authority: Model\User::create()
     * performs its own uniqueness check, so a value registered after this ran is
     * still refused at the point of creation.
     *
     * @param string[]                $aHeader The CSV's header row
     * @param iterable<int, string[]> $aRows   Raw rows, keyed by line number
     *
     * @return array<int, string[]> The errors, keyed by line number
     * @throws FactoryException
     * @throws TemplateException
     */
    public function detectRegistered(array $aHeader, iterable $aRows): array
    {
        $oImportService = $this->getImportService();

        $aColumn = [];
        $aLines  = [];

        foreach ($oImportService->getUniqueKeys() as $sKey) {

            $iColumn = array_search($sKey, $aHeader, true);
            if ($iColumn === false) {
                continue;
            }

            $aColumn[$sKey] = $iColumn;
            $aLines[$sKey]  = [];
        }

        if (empty($aColumn)) {
            return [];
        }

        /**
         * Only the values are held, not the rows - and keyed by value, because a
         * value can legitimately recur within a chunk even though the upload's
         * duplicate check would reject it across the whole file.
         */
        foreach ($aRows as $iLine => $aRow) {
            foreach ($aColumn as $sKey => $iColumn) {

                $sValue = strtolower(trim((string) ($aRow[$iColumn] ?? '')));
                if ($sValue === '') {
                    continue;
                }

                $aLines[$sKey][$sValue][] = $iLine;
            }
        }

        $aErrors = [];

        foreach ($aLines as $sKey => $aValues) {
            foreach ($oImportService->whichExist($sKey, array_keys($aValues)) as $sValue) {
                foreach ($aValues[$sValue] ?? [] as $iLine) {
                    $aErrors[$iLine][] = sprintf('%s: "%s" is already registered', $sKey, $sValue);
                }
            }
        }

        ksort($aErrors);

        return $aErrors;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the import service
     *
     * Broken out so the template and unique-key contracts can be exercised
     * against an app which has amended them, without registering a service; the
     * same seam Model\User\Import::getDb() provides for the database.
     *
     * @throws FactoryException
     */
    protected function getImportService(): ImportService
    {
        /** @var ImportService $oImportService */
        $oImportService = Factory::service('UserImport', Constants::MODULE_SLUG);
        return $oImportService;
    }
}

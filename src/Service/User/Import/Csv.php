<?php

/**
 * A streaming reader/writer for user import CSVs.
 *
 * Nothing in here holds more than a single row in memory at a time; a 20k row
 * import must be as cheap to page through as a 20 row one.
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    Service
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Auth\Service\User\Import;

use Generator;
use Iterator;
use Nails\Auth\Constants;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\NailsException;
use Nails\Factory;
use RuntimeException;

/**
 * Class Csv
 *
 * @package Nails\Auth\Service\User\Import
 */
class Csv
{
    /**
     * Rows are counted as CSV *records*, not physical lines; the header is
     * record 1, so the first data row is line 2. This matches the numbering the
     * import has always reported.
     */
    const HEADER_LINE = 1;

    // --------------------------------------------------------------------------

    /**
     * Returns the CSV's header row
     *
     * @param string $sPath The path to the CSV
     *
     * @return string[]
     */
    public function getHeader(string $sPath): array
    {
        $rHandle = $this->open($sPath);

        try {
            $aHeader = $this->read($rHandle);
        } finally {
            fclose($rHandle);
        }

        if ($this->isBlank($aHeader)) {
            return [];
        }

        return array_map(fn($sValue) => trim((string) $sValue), $aHeader);
    }

    // --------------------------------------------------------------------------

    /**
     * Counts the number of data rows in the CSV, the header excluded
     *
     * @param string $sPath The path to the CSV
     */
    public function countRows(string $sPath): int
    {
        $rHandle = $this->open($sPath);

        try {

            //  Discard the header
            $this->read($rHandle);

            $iCount = 0;
            while (($aRow = $this->read($rHandle)) !== false) {
                if (!$this->isBlank($aRow)) {
                    $iCount++;
                }
            }

        } finally {
            fclose($rHandle);
        }

        return $iCount;
    }

    // --------------------------------------------------------------------------

    /**
     * Yields raw (i.e. unkeyed) data rows, keyed by their line number
     *
     * @param string   $sPath   The path to the CSV
     * @param int      $iOffset The number of data rows to skip
     * @param int|null $iLimit  The maximum number of rows to yield, null for all
     *
     * @return Generator<int, string[]>
     */
    public function readRawRows(string $sPath, int $iOffset = 0, ?int $iLimit = null): Generator
    {
        $rHandle = $this->open($sPath);

        try {

            //  Discard the header
            $this->read($rHandle);

            $iIndex = 0;
            $iSent  = 0;

            while (($aRow = $this->read($rHandle)) !== false) {

                if ($this->isBlank($aRow)) {
                    continue;
                }

                $iIndex++;

                if ($iIndex <= $iOffset) {
                    continue;

                } elseif ($iLimit !== null && $iSent >= $iLimit) {
                    break;
                }

                $iSent++;

                yield $iIndex + static::HEADER_LINE => $aRow;
            }

        } finally {
            fclose($rHandle);
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Yields data rows keyed by line number, each combined with the header and
     * with the import service's default values applied to blank cells
     *
     * @param string   $sPath   The path to the CSV
     * @param int      $iOffset The number of data rows to skip
     * @param int|null $iLimit  The maximum number of rows to yield, null for all
     *
     * @return Generator<int, array<string, mixed>>
     * @throws FactoryException
     * @throws NailsException
     */
    public function readRows(string $sPath, int $iOffset = 0, ?int $iLimit = null): Generator
    {
        $aHeader = $this->getHeader($sPath);

        foreach ($this->readRawRows($sPath, $iOffset, $iLimit) as $iLine => $aRow) {
            yield $iLine => $this->applyDefaults(
                $this->align($aHeader, $aRow)
            );
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Keys a raw row by the header's columns
     *
     * Rows which are short are padded, rows which are long are truncated; the
     * discrepancy is reported separately by the validator so that a malformed
     * row produces a legible error rather than an exception.
     *
     * @param string[] $aHeader The CSV's header row
     * @param string[] $aRow    The raw row
     *
     * @return array<string, string>
     */
    public function align(array $aHeader, array $aRow): array
    {
        $aOut   = [];
        $aRow   = array_values($aRow);
        $iIndex = 0;

        foreach ($aHeader as $sKey) {
            $aOut[$sKey] = trim((string) ($aRow[$iIndex] ?? ''));
            $iIndex++;
        }

        return $aOut;
    }

    // --------------------------------------------------------------------------

    /**
     * Replaces blank cells with the import service's default value for that key
     *
     * @param array<string, string> $aRow The keyed row
     *
     * @return array<string, mixed>
     * @throws FactoryException
     * @throws NailsException
     */
    public function applyDefaults(array $aRow): array
    {
        /** @var \Nails\Auth\Service\User\Import $oImportService */
        $oImportService = Factory::service('UserImport', Constants::MODULE_SLUG);

        foreach ($aRow as $sKey => $sValue) {
            if ($sValue === '') {
                $aRow[$sKey] = $oImportService->getDefaultValue($sKey);
            }
        }

        return $aRow;
    }

    // --------------------------------------------------------------------------

    /**
     * Builds the import's log CSV
     *
     * The source CSV is streamed and joined, line by line, against the supplied
     * items; this reproduces the log format the import has always emitted (the
     * original columns, plus `id`, `status` and `message`) without ever having
     * held a second copy of the file.
     *
     * @param string                                                    $sPath   The path to the source CSV
     * @param iterable<int, array{id: int|null, status: string, message: string|null}> $aItems Keyed by line
     *                                                                                        number, ascending
     *
     * @return string The path to the generated log CSV
     */
    public function writeLog(string $sPath, iterable $aItems): string
    {
        $oItems = $aItems instanceof Iterator
            ? $aItems
            : new \ArrayIterator(is_array($aItems) ? $aItems : iterator_to_array($aItems));

        $oItems->rewind();

        $aHeader = $this->getHeader($sPath);
        $sLog    = $this->getTempFile();
        $rLog    = fopen($sLog, 'w');

        if ($rLog === false) {
            throw new RuntimeException(sprintf('Failed to open "%s" for writing', $sLog));
        }

        try {

            fputcsv($rLog, array_merge($aHeader, ['id', 'status', 'message']), escape: '');

            foreach ($this->readRawRows($sPath) as $iLine => $aRow) {

                if (!$oItems->valid()) {
                    break;

                } elseif ((int) $oItems->key() !== $iLine) {
                    continue;
                }

                $aItem = (array) $oItems->current();

                fputcsv(
                    $rLog,
                    array_merge(
                        array_values($this->align($aHeader, $aRow)),
                        [
                            $aItem['id'] ?? null,
                            $aItem['status'] ?? '',
                            $aItem['message'] ?? '',
                        ]
                    ),
                    escape: ''
                );

                $oItems->next();
            }

        } finally {
            fclose($rLog);
        }

        return $sLog;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the path to a new temporary file
     */
    public function getTempFile(): string
    {
        $sPath = tempnam(sys_get_temp_dir(), 'nails-user-import-');

        if ($sPath === false) {
            throw new RuntimeException('Failed to create a temporary file');
        }

        return $sPath;
    }

    // --------------------------------------------------------------------------

    /**
     * Opens the CSV for reading
     *
     * @return resource
     */
    protected function open(string $sPath)
    {
        $rHandle = @fopen($sPath, 'r');

        if ($rHandle === false) {
            throw new RuntimeException(sprintf(
                'Failed to open "%s" for reading',
                $sPath
            ));
        }

        return $rHandle;
    }

    // --------------------------------------------------------------------------

    /**
     * Reads a single record
     *
     * The empty escape character matches the `str_getcsv($line, escape: '')`
     * semantics the import has always used; `SplFileObject` cannot be relied
     * upon to honour it, hence the plain handle.
     *
     * @param resource $rHandle
     *
     * @return string[]|false
     */
    protected function read($rHandle): array|false
    {
        return fgetcsv($rHandle, escape: '');
    }

    // --------------------------------------------------------------------------

    /**
     * Whether a record is an empty line
     *
     * @param string[]|false $aRow
     */
    protected function isBlank(array|false $aRow): bool
    {
        return $aRow === false || $aRow === [null] || $aRow === [''];
    }
}

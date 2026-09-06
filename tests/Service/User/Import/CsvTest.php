<?php

namespace Tests\Auth\Service\User\Import;

use Nails\Auth\Constants;
use Nails\Auth\Service\User\Import\Csv;
use Nails\Factory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Nails\Auth\Service\User\Import\Csv
 */
class CsvTest extends TestCase
{
    private Csv $oCsv;

    /**
     * @var string[]
     */
    private array $aPaths = [];

    // --------------------------------------------------------------------------

    protected function setUp(): void
    {
        /** @var Csv $oCsv */
        $oCsv       = Factory::service('UserImportCsv', Constants::MODULE_SLUG);
        $this->oCsv = $oCsv;
    }

    // --------------------------------------------------------------------------

    protected function tearDown(): void
    {
        foreach ($this->aPaths as $sPath) {
            @unlink($sPath);
        }
        $this->aPaths = [];
    }

    // --------------------------------------------------------------------------

    /**
     * Writes a CSV to a temporary file and returns its path
     */
    private function write(string $sContents): string
    {
        $sPath = tempnam(sys_get_temp_dir(), 'nails-user-import-test-');
        file_put_contents($sPath, $sContents);
        $this->aPaths[] = $sPath;
        return $sPath;
    }

    // --------------------------------------------------------------------------

    public function test_get_header_returns_the_first_record(): void
    {
        $sPath = $this->write("email,first_name,last_name\na@example.com,Ada,Lovelace\n");

        self::assertSame(
            ['email', 'first_name', 'last_name'],
            $this->oCsv->getHeader($sPath)
        );
    }

    // --------------------------------------------------------------------------

    public function test_get_header_trims_whitespace(): void
    {
        $sPath = $this->write("email , first_name\na@example.com,Ada\n");

        self::assertSame(
            ['email', 'first_name'],
            $this->oCsv->getHeader($sPath)
        );
    }

    // --------------------------------------------------------------------------

    public function test_get_header_of_an_empty_file_is_empty(): void
    {
        self::assertSame([], $this->oCsv->getHeader($this->write('')));
    }

    // --------------------------------------------------------------------------

    public function test_count_rows_excludes_the_header(): void
    {
        $sPath = $this->write("email\na@example.com\nb@example.com\nc@example.com\n");

        self::assertSame(3, $this->oCsv->countRows($sPath));
    }

    // --------------------------------------------------------------------------

    public function test_count_rows_ignores_blank_lines(): void
    {
        $sPath = $this->write("email\na@example.com\n\nb@example.com\n\n");

        self::assertSame(2, $this->oCsv->countRows($sPath));
    }

    // --------------------------------------------------------------------------

    public function test_read_raw_rows_keys_by_line_number(): void
    {
        $sPath = $this->write("email\na@example.com\nb@example.com\n");

        self::assertSame(
            [
                2 => ['a@example.com'],
                3 => ['b@example.com'],
            ],
            iterator_to_array($this->oCsv->readRawRows($sPath), true)
        );
    }

    // --------------------------------------------------------------------------

    public function test_read_raw_rows_honours_the_offset_and_limit(): void
    {
        $sPath = $this->write("email\na@example.com\nb@example.com\nc@example.com\nd@example.com\n");

        self::assertSame(
            [
                3 => ['b@example.com'],
                4 => ['c@example.com'],
            ],
            iterator_to_array($this->oCsv->readRawRows($sPath, 1, 2), true)
        );
    }

    // --------------------------------------------------------------------------

    public function test_read_raw_rows_past_the_end_is_empty(): void
    {
        $sPath = $this->write("email\na@example.com\n");

        self::assertSame(
            [],
            iterator_to_array($this->oCsv->readRawRows($sPath, 10, 5), true)
        );
    }

    // --------------------------------------------------------------------------

    public function test_read_raw_rows_respects_quoted_separators(): void
    {
        $sPath = $this->write("email,last_name\n\"a@example.com\",\"Lovelace, Ada\"\n");

        self::assertSame(
            [2 => ['a@example.com', 'Lovelace, Ada']],
            iterator_to_array($this->oCsv->readRawRows($sPath), true)
        );
    }

    // --------------------------------------------------------------------------

    public function test_align_keys_the_row_by_the_header(): void
    {
        self::assertSame(
            ['email' => 'a@example.com', 'first_name' => 'Ada'],
            $this->oCsv->align(['email', 'first_name'], ['a@example.com', 'Ada'])
        );
    }

    // --------------------------------------------------------------------------

    public function test_align_pads_short_rows(): void
    {
        self::assertSame(
            ['email' => 'a@example.com', 'first_name' => ''],
            $this->oCsv->align(['email', 'first_name'], ['a@example.com'])
        );
    }

    // --------------------------------------------------------------------------

    public function test_align_truncates_long_rows(): void
    {
        self::assertSame(
            ['email' => 'a@example.com'],
            $this->oCsv->align(['email'], ['a@example.com', 'Ada'])
        );
    }

    // --------------------------------------------------------------------------

    public function test_align_trims_values(): void
    {
        self::assertSame(
            ['email' => 'a@example.com'],
            $this->oCsv->align(['email'], ['  a@example.com  '])
        );
    }

    // --------------------------------------------------------------------------

    public function test_apply_defaults_replaces_blank_cells(): void
    {
        //  first_name has no default, so a blank cell becomes null rather than ''
        self::assertSame(
            ['email' => 'a@example.com', 'first_name' => null],
            $this->oCsv->applyDefaults(['email' => 'a@example.com', 'first_name' => ''])
        );
    }

    // --------------------------------------------------------------------------

    public function test_apply_defaults_leaves_populated_cells_alone(): void
    {
        self::assertSame(
            ['first_name' => 'Ada'],
            $this->oCsv->applyDefaults(['first_name' => 'Ada'])
        );
    }

    // --------------------------------------------------------------------------

    public function test_read_rows_combines_and_defaults(): void
    {
        $sPath = $this->write("email,first_name\na@example.com,Ada\nb@example.com,\n");

        self::assertSame(
            [
                2 => ['email' => 'a@example.com', 'first_name' => 'Ada'],
                3 => ['email' => 'b@example.com', 'first_name' => null],
            ],
            iterator_to_array($this->oCsv->readRows($sPath), true)
        );
    }

    // --------------------------------------------------------------------------

    public function test_write_log_appends_the_outcome_columns(): void
    {
        $sPath = $this->write("email,first_name\na@example.com,Ada\nb@example.com,Grace\n");

        $sLog = $this->oCsv->writeLog($sPath, [
            2 => ['id' => 10, 'status' => 'SUCCESS', 'message' => ''],
            3 => ['id' => null, 'status' => 'ERROR', 'message' => 'Nope'],
        ]);

        $this->aPaths[] = $sLog;

        self::assertSame(
            implode("\n", [
                'email,first_name,id,status,message',
                'a@example.com,Ada,10,SUCCESS,',
                'b@example.com,Grace,,ERROR,Nope',
                '',
            ]),
            file_get_contents($sLog)
        );
    }

    // --------------------------------------------------------------------------

    public function test_write_log_only_emits_the_supplied_lines(): void
    {
        $sPath = $this->write("email\na@example.com\nb@example.com\nc@example.com\n");

        $sLog = $this->oCsv->writeLog($sPath, [
            3 => ['id' => null, 'status' => 'ERROR', 'message' => 'Nope'],
        ]);

        $this->aPaths[] = $sLog;

        self::assertSame(
            implode("\n", [
                'email,id,status,message',
                'b@example.com,,ERROR,Nope',
                '',
            ]),
            file_get_contents($sLog)
        );
    }
}

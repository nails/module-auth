<?php

namespace Tests\Auth\FindReplace\Resolver;

use Nails\Auth\FindReplace\Resolver\User;
use Nails\FindReplace\Factory\Matched;
use PHPUnit\Framework\TestCase;

class UserTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('NAILS_DB_PREFIX')) {
            define('NAILS_DB_PREFIX', 'nails_');
        }
    }

    public function testTables(): void
    {
        self::assertSame([NAILS_DB_PREFIX . 'user'], (new User())->tables());
    }

    public function testTitlesByNameAndPrimaryEmail(): void
    {
        $table = NAILS_DB_PREFIX . 'user';
        $resolver = $this->resolver(
            [
                (object) ['id' => 1, 'first_name' => 'Jane', 'last_name' => 'Smith'],
                (object) ['id' => 2, 'first_name' => 'Sam', 'last_name' => ''],
                (object) ['id' => 3, 'first_name' => '', 'last_name' => ''],
                (object) ['id' => 4, 'first_name' => '', 'last_name' => ''],
            ],
            [
                '1' => 'jane@example.com',
                '3' => 'anon@example.com',
            ]
        );

        $hits = $resolver->resolve($table, [
            $this->match($table, '1', 'first_name'),
            $this->match($table, '1', 'last_name'),
            $this->match($table, '2', 'first_name'),
            $this->match($table, '3', 'salutation'),
            $this->match($table, '4', 'salutation'),
        ]);

        self::assertCount(4, $hits);
        self::assertSame('User', $hits['1']->getEntityLabel());
        self::assertSame('1', $hits['1']->getEntityId());
        self::assertSame('Jane Smith (jane@example.com)', $hits['1']->getTitle());
        self::assertSame('Sam', $hits['2']->getTitle());
        self::assertSame('anon@example.com', $hits['3']->getTitle());
        self::assertNull($hits['4']->getTitle());
        self::assertSame('Edit', $hits['1']->getActions()[0]->getLabel());
        self::assertSame('https://example.com/admin/auth/accounts/edit/1', $hits['1']->getActions()[0]->getUrl());
    }

    public function testNoMatchesResolvesNothing(): void
    {
        self::assertSame([], $this->resolver([], [])->resolve(NAILS_DB_PREFIX . 'user', []));
    }

    private function match(string $table, string $id, string $column): Matched
    {
        return new Matched($table, $column, 'plaintext', 'example', $id, 'example');
    }

    /**
     * @param list<\stdClass> $rows
     * @param array<string, string> $emails
     */
    private function resolver(array $rows, array $emails): User
    {
        return new class ($rows, $emails) extends User {
            /**
             * @param list<\stdClass> $stub
             * @param array<string, string> $stubEmails
             */
            public function __construct(
                private readonly array $stub,
                private readonly array $stubEmails,
            ) {
            }

            protected function rows(string $table, string $columns, array $ids): array
            {
                return $this->stub;
            }

            protected function emails(array $ids): array
            {
                return $this->stubEmails;
            }

            protected function editUrl(): string
            {
                return 'https://example.com/admin/auth/accounts/edit';
            }
        };
    }
}

<?php

namespace Tests\Auth\FindReplace\Resolver;

use Nails\Auth\FindReplace\Resolver\Group;
use Nails\FindReplace\Factory\Matched;
use PHPUnit\Framework\TestCase;

class GroupTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('NAILS_DB_PREFIX')) {
            define('NAILS_DB_PREFIX', 'nails_');
        }
    }

    public function testTables(): void
    {
        self::assertSame([NAILS_DB_PREFIX . 'user_group'], (new Group())->tables());
    }

    public function testResolvesByLabel(): void
    {
        $table = NAILS_DB_PREFIX . 'user_group';
        $resolver = $this->resolver([
            (object) ['id' => 2, 'label' => 'Administrators'],
        ]);

        $hits = $resolver->resolve($table, [
            $this->match($table, '2', 'label'),
            $this->match($table, '2', 'description'),
        ]);

        self::assertCount(1, $hits);
        self::assertSame('User Group', $hits['2']->getEntityLabel());
        self::assertSame('2', $hits['2']->getEntityId());
        self::assertSame('Administrators', $hits['2']->getTitle());
        self::assertSame('https://example.com/admin/auth/groups/edit/2', $hits['2']->getActions()[0]->getUrl());
    }

    public function testNoMatchesResolvesNothing(): void
    {
        self::assertSame([], $this->resolver([])->resolve(NAILS_DB_PREFIX . 'user_group', []));
    }

    private function match(string $table, string $id, string $column): Matched
    {
        return new Matched($table, $column, 'plaintext', 'example', $id, 'example');
    }

    /**
     * @param list<\stdClass> $rows
     */
    private function resolver(array $rows): Group
    {
        return new class ($rows) extends Group {
            /**
             * @param list<\stdClass> $stub
             */
            public function __construct(private readonly array $stub)
            {
            }

            protected function rows(string $table, string $columns, array $ids): array
            {
                return $this->stub;
            }

            protected function editUrl(): string
            {
                return 'https://example.com/admin/auth/groups/edit';
            }
        };
    }
}

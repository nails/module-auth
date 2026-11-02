<?php

namespace Nails\Auth\FindReplace\Resolver;

use Nails\Common\Service\Database;
use Nails\Factory;
use Nails\FindReplace\Factory\Action;
use Nails\FindReplace\Factory\Hit;
use Nails\FindReplace\Factory\Matched;
use Nails\FindReplace\Interfaces\Resolver;

class Group implements Resolver
{
    /**
     * The engine passes every match in a table at once; a long IN () list
     * overflows CodeIgniter's where_in parser, so IDs are queried in chunks.
     */
    const IDS_PER_QUERY = 500;

    public function tables(): array
    {
        return [
            NAILS_DB_PREFIX . 'user_group',
        ];
    }

    public function resolve(string $table, array $matches): array
    {
        $ids = array_values(array_unique(array_map(fn(Matched $match): string => $match->getId(), $matches)));

        if ($ids === []) {
            return [];
        }

        $editUrl = $this->editUrl();
        $hits = [];

        foreach ($this->rows($table, 'id, label', $ids) as $row) {
            $id = (string) $row->id;

            $hits[$id] = new Hit(
                $table,
                $id,
                entityLabel: 'User Group',
                entityId: $id,
                title: (string) $row->label ?: null,
                actions: [
                    new Action('Edit', $editUrl . '/' . $id),
                ],
            );
        }

        return $hits;
    }

    /**
     * @param list<string> $ids
     *
     * @return list<\stdClass>
     */
    protected function rows(string $table, string $columns, array $ids): array
    {
        /** @var Database $database */
        $database = Factory::service('Database');
        $rows = [];

        foreach (array_chunk($ids, static::IDS_PER_QUERY) as $chunk) {
            $database->select($columns);
            $database->where_in('id', $chunk);
            $rows = array_merge($rows, $database->get($table)->result());
        }

        return $rows;
    }

    protected function editUrl(): string
    {
        return siteUrl('admin/auth/groups/edit');
    }
}

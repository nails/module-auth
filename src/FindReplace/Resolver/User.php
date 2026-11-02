<?php

namespace Nails\Auth\FindReplace\Resolver;

use Nails\Auth\Admin\Controller\Accounts;
use Nails\Common\Service\Database;
use Nails\Factory;
use Nails\FindReplace\Factory\Action;
use Nails\FindReplace\Factory\Hit;
use Nails\FindReplace\Factory\Matched;
use Nails\FindReplace\Interfaces\Resolver;

class User implements Resolver
{
    /**
     * The engine passes every match in a table at once; a long IN () list
     * overflows CodeIgniter's where_in parser, so IDs are queried in chunks.
     */
    const IDS_PER_QUERY = 500;

    public function tables(): array
    {
        return [
            NAILS_DB_PREFIX . 'user',
        ];
    }

    public function resolve(string $table, array $matches): array
    {
        $ids = array_values(array_unique(array_map(fn(Matched $match): string => $match->getId(), $matches)));

        if ($ids === []) {
            return [];
        }

        $emails = $this->emails($ids);
        $editUrl = $this->editUrl();
        $hits = [];

        foreach ($this->rows($table, 'id, first_name, last_name', $ids) as $row) {
            $id = (string) $row->id;
            $name = trim($row->first_name . ' ' . $row->last_name);
            $email = $emails[$id] ?? '';

            $title = match (true) {
                $name !== '' && $email !== '' => $name . ' (' . $email . ')',
                $name !== '' => $name,
                $email !== '' => $email,
                default => null,
            };

            $hits[$id] = new Hit(
                $table,
                $id,
                entityLabel: 'User',
                entityId: $id,
                title: $title,
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

    /**
     * Primary email addresses, keyed by user ID.
     *
     * @param list<string> $ids
     *
     * @return array<string, string>
     */
    protected function emails(array $ids): array
    {
        /** @var Database $database */
        $database = Factory::service('Database');
        $emails = [];

        foreach (array_chunk($ids, static::IDS_PER_QUERY) as $chunk) {
            $database->select('user_id, email');
            $database->where_in('user_id', $chunk);
            $database->where('is_primary', true);

            foreach ($database->get(NAILS_DB_PREFIX . 'user_email')->result() as $row) {
                $emails[(string) $row->user_id] = (string) $row->email;
            }
        }

        return $emails;
    }

    protected function editUrl(): string
    {
        return Accounts::url('edit');
    }
}

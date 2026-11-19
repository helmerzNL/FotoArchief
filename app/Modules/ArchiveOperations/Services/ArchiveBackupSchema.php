<?php

declare(strict_types=1);

namespace App\Modules\ArchiveOperations\Services;

use Illuminate\Support\Facades\Schema;
use LogicException;

/**
 * Which tables an in-app archive backup holds, and the order a restore clears and fills them,
 * read from the live schema (columns and foreign keys) rather than written down twice.
 *
 * The sections are what people entered: the catalogue, its history, publication and moderation,
 * and work lists. Never in a backup (App-Guidance backup-restore.md, rule 1): users, roles,
 * passkeys, recovery codes, invitations, sessions, AI provider keys and OneDrive tokens. Never
 * either: work in progress and things that can be made again (queues, uploads, integrity checks,
 * AI runs and embeddings, operation runs, exports, the backup jobs themselves).
 *
 * A new table that holds archive data belongs in SECTIONS and in the round-trip test, in the
 * same change.
 */
class ArchiveBackupSchema
{
    /** @var list<string> */
    public const array SECTIONS = [
        'archive_profile',
        'rights_statements',
        'licenses',
        'sources',
        'contributors',
        'people',
        'person_aliases',
        'locations',
        'location_aliases',
        'tags',
        'tag_synonyms',
        'collections',
        'assets',
        'asset_files',
        'asset_versions',
        'asset_people',
        'asset_locations',
        'asset_sources',
        'asset_contributors',
        'asset_rights',
        'asset_tags',
        'collection_assets',
        'asset_ocr_texts',
        'asset_audit_events',
        'publications',
        'asset_suggestions',
        'saved_asset_searches',
        'worklists',
        'worklist_items',
        'worklist_events',
    ];

    /** Tables a restore must never clear, whatever refers to what. */
    private const array PROTECTED = [
        'users', 'roles', 'permissions', 'role_user', 'permission_role', 'user_passkeys',
        'user_recovery_codes', 'user_invitations', 'webauthn_challenges', 'backup_jobs',
        'migrations', 'installation_receipts', 'ai_provider_configs', 'onedrive_connections',
    ];

    /** @var array<string, list<string>> */
    private array $columns = [];

    /** @var array<string, array<string, bool>> */
    private array $nullable = [];

    /** @var array<string, list<array{column: string, table: string, nullable: bool}>> */
    private array $foreignKeys = [];

    /** @var array<string, list<string>> */
    private array $autoIncrement = [];

    /** @return list<string> */
    public function sections(): array
    {
        return array_values(array_filter(self::SECTIONS, static fn (string $table): bool => Schema::hasTable($table)));
    }

    /** @return list<string> */
    public function columns(string $table): array
    {
        $this->load($table);

        return $this->columns[$table];
    }

    public function isNullable(string $table, string $column): bool
    {
        $this->load($table);

        return $this->nullable[$table][$column] ?? false;
    }

    /** @return list<string> */
    public function autoIncrementColumns(string $table): array
    {
        $this->load($table);

        return $this->autoIncrement[$table];
    }

    /**
     * Single-column foreign keys of a table.
     *
     * @return list<array{column: string, table: string, nullable: bool}>
     */
    public function foreignKeys(string $table): array
    {
        $this->load($table);

        return $this->foreignKeys[$table];
    }

    /**
     * Foreign key columns that point into the sections and may be empty. A restore inserts them
     * empty and fills them in afterwards, so self-references (a location inside a location) and
     * optional links between sections need no particular order.
     *
     * @return list<string>
     */
    public function deferredColumns(string $table): array
    {
        $sections = $this->sections();

        return array_values(array_map(
            static fn (array $key): string => $key['column'],
            array_filter($this->foreignKeys($table), static fn (array $key): bool => $key['nullable'] && in_array($key['table'], $sections, true)),
        ));
    }

    /**
     * The sections in an order where every required link points to a table filled earlier.
     *
     * @return list<string>
     */
    public function insertOrder(): array
    {
        $sections = $this->sections();

        return $this->order($sections, fn (string $table): array => array_values(array_unique(array_map(
            static fn (array $key): string => $key['table'],
            array_filter($this->foreignKeys($table), static fn (array $key): bool => ! $key['nullable'] && $key['table'] !== $table && in_array($key['table'], $sections, true)),
        ))));
    }

    /**
     * Every table a restore clears: the sections, plus every table with a required link to them
     * (directly or through another such table). Those hold state about the data being replaced,
     * such as integrity checks, AI suggestions or queued uploads, which would point at nothing
     * afterwards. A table whose links to them are optional keeps its rows; see detachedColumns().
     *
     * @return list<string>
     */
    public function clearedTables(): array
    {
        $all = $this->allTables();
        $cleared = $this->sections();
        do {
            $added = false;
            foreach ($all as $table) {
                if (in_array($table, $cleared, true)) {
                    continue;
                }
                foreach ($this->foreignKeys($table) as $key) {
                    if (! $key['nullable'] && in_array($key['table'], $cleared, true)) {
                        if (in_array($table, self::PROTECTED, true)) {
                            throw new LogicException("Table {$table} requires archive data and would be cleared by a restore.");
                        }
                        $cleared[] = $table;
                        $added = true;
                        break;
                    }
                }
            }
        } while ($added);

        return $cleared;
    }

    /**
     * Optional links (in any table) to a table the restore clears. They are emptied before the
     * clearing, so the operations audit log, for example, keeps its rows without the link.
     *
     * @param  list<string>  $cleared
     * @return list<array{table: string, column: string}>
     */
    public function detachedColumns(array $cleared): array
    {
        $columns = [];
        foreach ($this->allTables() as $table) {
            foreach ($this->foreignKeys($table) as $key) {
                if ($key['nullable'] && in_array($key['table'], $cleared, true)) {
                    $columns[] = ['table' => $table, 'column' => $key['column']];
                }
            }
        }

        return $columns;
    }

    /**
     * The cleared tables in an order that removes every referring row before what it refers to,
     * counting only the links that cannot be emptied first.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    public function deleteOrder(array $tables): array
    {
        $insert = $this->order($tables, fn (string $table): array => array_values(array_unique(array_map(
            static fn (array $key): string => $key['table'],
            array_filter($this->foreignKeys($table), static fn (array $key): bool => ! $key['nullable'] && $key['table'] !== $table && in_array($key['table'], $tables, true)),
        ))));

        return array_reverse($insert);
    }

    /**
     * Topological order: each table after the tables it depends on.
     *
     * @param  list<string>  $tables
     * @param  callable(string): list<string>  $dependencies
     * @return list<string>
     */
    private function order(array $tables, callable $dependencies): array
    {
        $ordered = [];
        $visiting = [];
        $visit = function (string $table) use (&$visit, &$ordered, &$visiting, $dependencies): void {
            if (in_array($table, $ordered, true)) {
                return;
            }
            if (isset($visiting[$table])) {
                throw new LogicException("Required foreign keys form a cycle at {$table}.");
            }
            $visiting[$table] = true;
            foreach ($dependencies($table) as $dependency) {
                $visit($dependency);
            }
            unset($visiting[$table]);
            $ordered[] = $table;
        };
        foreach ($tables as $table) {
            $visit($table);
        }

        return $ordered;
    }

    /** @return list<string> */
    private function allTables(): array
    {
        $names = [];
        foreach (Schema::getTables() as $table) {
            $schema = $table['schema'] ?? null;
            if (is_string($schema) && in_array($schema, ['pg_catalog', 'information_schema'], true)) {
                continue;
            }
            $names[] = (string) $table['name'];
        }

        return array_values(array_unique($names));
    }

    private function load(string $table): void
    {
        if (isset($this->columns[$table])) {
            return;
        }
        $columns = [];
        $nullable = [];
        $autoIncrement = [];
        foreach (Schema::getColumns($table) as $column) {
            $name = (string) $column['name'];
            $columns[] = $name;
            $nullable[$name] = (bool) ($column['nullable'] ?? false);
            if (($column['auto_increment'] ?? false) === true) {
                $autoIncrement[] = $name;
            }
        }
        $keys = [];
        foreach (Schema::getForeignKeys($table) as $key) {
            if (count($key['columns']) !== 1) {
                continue;
            }
            $column = (string) $key['columns'][0];
            $keys[] = ['column' => $column, 'table' => (string) $key['foreign_table'], 'nullable' => $nullable[$column] ?? false];
        }
        $this->columns[$table] = $columns;
        $this->nullable[$table] = $nullable;
        $this->foreignKeys[$table] = $keys;
        $this->autoIncrement[$table] = $autoIncrement;
    }
}

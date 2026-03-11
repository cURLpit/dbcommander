<?php

declare(strict_types=1);

namespace DbCommander\Repository;

use DbCommander\Driver\DriverInterface;
use DbCommander\Exception\NotFoundException;

/**
 * Returns tables, views, procedures and triggers within a database.
 */
final class TableRepository
{
    public function __construct(private readonly DriverInterface $driver) {}

    /**
     * List all tables and views in a database with metadata from
     * information_schema. Includes approximate row count for tables.
     *
     * @return array<int, array{
     *   name: string,
     *   type: string,
     *   engine: string|null,
     *   rows: int|null,
     *   data_length: int|null,
     *   collation: string|null,
     *   comment: string,
     *   modified: string|null
     * }>
     */
    public function listTables(string $database): array
    {
        $this->assertDatabaseExists($database);

        $sql = "
            SELECT
                TABLE_NAME        AS name,
                TABLE_TYPE        AS type,
                ENGINE            AS engine,
                TABLE_ROWS        AS `rows`,
                DATA_LENGTH       AS data_length,
                TABLE_COLLATION   AS collation,
                TABLE_COMMENT     AS comment,
                UPDATE_TIME       AS modified
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = ?
            ORDER BY TABLE_TYPE, TABLE_NAME
        ";

        $rows = $this->driver->fetchAll($sql, [$database]);

        return array_map(function (array $row): array {
            return [
                'name'        => $row['name'],
                'type'        => $row['type'] === 'BASE TABLE' ? 'TABLE' : $row['type'],
                'engine'      => $row['engine'],
                'rows'        => $row['rows'] !== null ? (int)$row['rows'] : null,
                'data_length' => $row['data_length'] !== null ? (int)$row['data_length'] : null,
                'collation'   => $row['collation'],
                'comment'     => $row['comment'] ?? '',
                'modified'    => $row['modified'] ?? null,
            ];
        }, $rows);
    }

    /**
     * List stored procedures and functions.
     *
     * @return array<int, array{name: string, type: string, definer: string, modified: string}>
     */
    public function listRoutines(string $database): array
    {
        $this->assertDatabaseExists($database);

        $sql = "
            SELECT
                ROUTINE_NAME AS name,
                ROUTINE_TYPE AS type,
                DEFINER      AS definer,
                LAST_ALTERED AS modified
            FROM information_schema.ROUTINES
            WHERE ROUTINE_SCHEMA = ?
            ORDER BY ROUTINE_TYPE, ROUTINE_NAME
        ";

        return $this->driver->fetchAll($sql, [$database]);
    }

    /**
     * List triggers.
     *
     * @return array<int, array{name: string, timing: string, event: string, table: string}>
     */
    public function listTriggers(string $database): array
    {
        $this->assertDatabaseExists($database);

        $sql = "
            SELECT
                TRIGGER_NAME      AS name,
                ACTION_TIMING     AS timing,
                EVENT_MANIPULATION AS event,
                EVENT_OBJECT_TABLE AS `table`
            FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = ?
            ORDER BY TRIGGER_NAME
        ";

        return $this->driver->fetchAll($sql, [$database]);
    }

    // ── private ──────────────────────────────────────────────

    private function assertDatabaseExists(string $database): void
    {
        $row = $this->driver->fetchOne(
            "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?",
            [$database]
        );

        if ($row === null) {
            throw new NotFoundException("Database '{$database}' not found");
        }
    }
}

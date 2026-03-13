<?php

declare(strict_types=1);

namespace DbCommander\Repository;

use DbCommander\Driver\DriverInterface;
use DbCommander\Exception\NotFoundException;

/**
 * Returns column-level structure of a table or view.
 */
final class StructureRepository
{
    public function __construct(private readonly DriverInterface $driver) {}

    /**
     * Full column metadata from information_schema.
     *
     * @return array<int, array{
     *   name: string,
     *   position: int,
     *   nullable: bool,
     *   data_type: string,
     *   full_type: string,
     *   key: string,
     *   default: string|null,
     *   extra: string,
     *   comment: string
     * }>
     */
    public function getColumns(string $database, string $table): array
    {
        $this->assertTableExists($database, $table);

        $sql = "
            SELECT
                COLUMN_NAME              AS name,
                ORDINAL_POSITION         AS position,
                IS_NULLABLE              AS nullable,
                DATA_TYPE                AS data_type,
                COLUMN_TYPE              AS full_type,
                COLUMN_KEY               AS `key`,
                COLUMN_DEFAULT           AS `default`,
                EXTRA                    AS extra,
                COLUMN_COMMENT           AS comment
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = ?
              AND TABLE_NAME   = ?
            ORDER BY ORDINAL_POSITION
        ";

        $rows = $this->driver->fetchAll($sql, [$database, $table]);

        return array_map(function (array $row): array {
            return [
                'name'      => $row['name'],
                'position'  => (int)$row['position'],
                'nullable'  => $row['nullable'] === 'YES',
                'data_type' => $row['data_type'],
                'full_type' => $row['full_type'],
                'key'       => $row['key'],
                'default'   => $row['default'],
                'extra'     => $row['extra'],
                'comment'   => $row['comment'],
            ];
        }, $rows);
    }

    /**
     * Index information for a table.
     *
     * @return array<int, array{name: string, unique: bool, columns: string[]}>
     */
    public function getIndexes(string $database, string $table): array
    {
        $this->assertTableExists($database, $table);

        $sql = "
            SELECT
                INDEX_NAME   AS index_name,
                NON_UNIQUE   AS non_unique,
                COLUMN_NAME  AS column_name,
                SEQ_IN_INDEX AS seq
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = ?
              AND TABLE_NAME   = ?
            ORDER BY INDEX_NAME, SEQ_IN_INDEX
        ";

        $rows = $this->driver->fetchAll($sql, [$database, $table]);

        // Group by index name
        $indexes = [];
        foreach ($rows as $row) {
            $iname = $row['index_name'];
            if (!isset($indexes[$iname])) {
                $indexes[$iname] = [
                    'name'    => $iname,
                    'unique'  => (int)$row['non_unique'] === 0,
                    'columns' => [],
                ];
            }
            $indexes[$iname]['columns'][] = $row['column_name'];
        }

        return array_values($indexes);
    }

    /**
     * Foreign key constraints for a table.
     *
     * @return array<int, array{
     *   name: string,
     *   column: string,
     *   ref_table: string,
     *   ref_column: string,
     *   on_update: string,
     *   on_delete: string
     * }>
     */
    public function getForeignKeys(string $database, string $table): array
    {
        $this->assertTableExists($database, $table);

        $sql = "
            SELECT
                kcu.CONSTRAINT_NAME       AS name,
                kcu.COLUMN_NAME           AS column_name,
                kcu.REFERENCED_TABLE_NAME AS ref_table,
                kcu.REFERENCED_COLUMN_NAME AS ref_column,
                rc.UPDATE_RULE            AS on_update,
                rc.DELETE_RULE            AS on_delete
            FROM information_schema.KEY_COLUMN_USAGE kcu
            JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
              ON rc.CONSTRAINT_NAME   = kcu.CONSTRAINT_NAME
             AND rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
            WHERE kcu.TABLE_SCHEMA = ?
              AND kcu.TABLE_NAME   = ?
              AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY kcu.CONSTRAINT_NAME
        ";

        return $this->driver->fetchAll($sql, [$database, $table]);
    }

    /**
     * Modify an existing column definition.
     * Builds: ALTER TABLE `db`.`table` MODIFY COLUMN `col` <full_type> [NOT NULL] [DEFAULT x] [COMMENT '...']
     *
     * @param array{
     *   name: string,
     *   full_type: string,
     *   nullable: bool,
     *   default: string|null,
     *   comment: string
     * } $column
     */
    public function modifyColumn(string $database, string $table, array $column): void
    {
        $this->assertTableExists($database, $table);

        $db  = $this->driver->quoteIdentifier($database);
        $tbl = $this->driver->quoteIdentifier($table);
        $col = $this->driver->quoteIdentifier($column['name']);

        $def = $this->buildColumnDef($column);
        $sql = "ALTER TABLE {$db}.{$tbl} MODIFY COLUMN {$col} {$def}";
        $this->driver->execute($sql);
    }

    /**
     * Add a new column to a table.
     * Builds: ALTER TABLE `db`.`table` ADD COLUMN `col` <full_type> [NOT NULL] [DEFAULT x] [COMMENT '...']
     */
    public function addColumn(string $database, string $table, array $column): void
    {
        $this->assertTableExists($database, $table);

        $db  = $this->driver->quoteIdentifier($database);
        $tbl = $this->driver->quoteIdentifier($table);
        $col = $this->driver->quoteIdentifier($column['name']);

        $def = $this->buildColumnDef($column);
        $sql = "ALTER TABLE {$db}.{$tbl} ADD COLUMN {$col} {$def}";
        $this->driver->execute($sql);
    }

    /**
     * Drop a column from a table.
     * Builds: ALTER TABLE `db`.`table` DROP COLUMN `col`
     */
    public function dropColumn(string $database, string $table, string $columnName): void
    {
        $this->assertTableExists($database, $table);

        $db  = $this->driver->quoteIdentifier($database);
        $tbl = $this->driver->quoteIdentifier($table);
        $col = $this->driver->quoteIdentifier($columnName);

        $this->driver->execute("ALTER TABLE {$db}.{$tbl} DROP COLUMN {$col}");
    }

    // ── private ──────────────────────────────────────────────

    private function buildColumnDef(array $column): string
    {
        $def  = $column['full_type'];
        $def .= $column['nullable'] ? ' NULL' : ' NOT NULL';

        if (isset($column['default']) && $column['default'] !== null && $column['default'] !== '') {
            $numericTypes = ['int','bigint','tinyint','smallint','mediumint','float','double','decimal'];
            $dataType = strtolower(preg_replace('/\(.*/', '', $column['full_type']));
            $def .= in_array($dataType, $numericTypes, true)
                ? ' DEFAULT ' . $column['default']
                : " DEFAULT '" . addslashes($column['default']) . "'";
        } elseif ($column['nullable'] ?? true) {
            $def .= ' DEFAULT NULL';
        }

        if (!empty($column['comment'])) {
            $def .= " COMMENT '" . addslashes($column['comment']) . "'";
        }

        return $def;
    }

    private function assertTableExists(string $database, string $table): void
    {
        $row = $this->driver->fetchOne(
            "SELECT TABLE_NAME
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?",
            [$database, $table]
        );

        if ($row === null) {
            throw new NotFoundException("Table '{$database}.{$table}' not found");
        }
    }
}

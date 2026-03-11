<?php

declare(strict_types=1);

namespace DbCommander\Repository;

use DbCommander\Driver\DriverInterface;
use DbCommander\Exception\DbcException;
use DbCommander\Exception\NotFoundException;

/**
 * Fetches rows from a table with pagination and optional ordering.
 */
final class RowRepository
{
    private const MAX_LIMIT = 1000;

    public function __construct(private readonly DriverInterface $driver) {}

    /**
     * @param  string[] $columns  Columns to select; empty = all (*)
     * @return array{
     *   columns: string[],
     *   rows: array<int, array<string, mixed>>,
     *   total: int|null,
     *   limit: int,
     *   offset: int
     * }
     */
    public function getRows(
        string  $database,
        string  $table,
        int     $limit      = 200,
        int     $offset     = 0,
        ?string $orderBy    = null,
        string  $direction  = 'ASC',
        array   $columns    = [],
        bool    $countTotal = true,
    ): array {
        $this->assertTableExists($database, $table);

        $limit  = min(max(1, $limit), self::MAX_LIMIT);
        $offset = max(0, $offset);

        $db  = $this->driver->quoteIdentifier($database);
        $tbl = $this->driver->quoteIdentifier($table);

        // Column list
        if (!empty($columns)) {
            $colList = implode(', ', array_map(
                fn(string $c) => $this->driver->quoteIdentifier($c),
                $columns
            ));
        } else {
            $colList = '*';
        }

        // ORDER BY
        $orderClause = '';
        if ($orderBy !== null) {
            $direction   = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
            $orderClause = 'ORDER BY ' . $this->driver->quoteIdentifier($orderBy) . ' ' . $direction;
        }

        // SELECT
        $sql  = "SELECT {$colList} FROM {$db}.{$tbl} {$orderClause} LIMIT {$limit} OFFSET {$offset}";
        $rows = $this->driver->fetchAll($sql);

        // Column names from first row keys, or explicit list
        $resolvedColumns = !empty($rows)
            ? array_keys($rows[0])
            : $columns;

        // COUNT (approximate for large tables is fine; exact here)
        $total = null;
        if ($countTotal) {
            $countRow = $this->driver->fetchOne("SELECT COUNT(*) AS cnt FROM {$db}.{$tbl}");
            $total    = $countRow !== null ? (int)$countRow['cnt'] : null;
        }

        return [
            'columns' => $resolvedColumns,
            'rows'    => $rows,
            'total'   => $total,
            'limit'   => $limit,
            'offset'  => $offset,
        ];
    }

    /**
     * Update a single row identified by $where conditions.
     *
     * @param  array<string, mixed> $where  Column→value pairs for the WHERE clause (typically PK)
     * @param  array<string, mixed> $set    Column→value pairs to UPDATE
     * @return int  Affected rows
     */
    public function updateRow(string $database, string $table, array $where, array $set): int
    {
        if (empty($set))   throw new DbcException('No columns to update');
        if (empty($where)) throw new DbcException('WHERE clause is required for UPDATE');

        $db  = $this->driver->quoteIdentifier($database);
        $tbl = $this->driver->quoteIdentifier($table);

        $setParts    = [];
        $whereParts  = [];
        $params      = [];

        foreach ($set as $col => $val) {
            $setParts[] = $this->driver->quoteIdentifier($col) . ' = ?';
            $params[]   = $val;
        }
        foreach ($where as $col => $val) {
            $whereParts[] = $this->driver->quoteIdentifier($col) . ($val === null ? ' IS NULL' : ' = ?');
            if ($val !== null) $params[] = $val;
        }

        $sql = sprintf(
            'UPDATE %s.%s SET %s WHERE %s LIMIT 1',
            $db, $tbl,
            implode(', ', $setParts),
            implode(' AND ', $whereParts),
        );

        return $this->driver->execute($sql, $params);
    }

    // ── private ──────────────────────────────────────────────

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

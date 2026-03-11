<?php

declare(strict_types=1);

namespace DbCommander\Driver;

interface DriverInterface
{
    /**
     * Execute a query and return all rows as associative arrays.
     *
     * @param  array<int|string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array;

    /**
     * Execute a query and return a single row, or null if no result.
     *
     * @param  array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array;

    /**
     * Execute a non-SELECT statement and return affected row count.
     *
     * @param  array<int|string, mixed> $params
     */
    public function execute(string $sql, array $params = []): int;

    /**
     * Quote an identifier (database/table/column name) safely.
     */
    public function quoteIdentifier(string $identifier): string;

    /**
     * Return the name of the underlying driver: 'pdo' or 'mysqli'.
     */
    public function driverName(): string;
}

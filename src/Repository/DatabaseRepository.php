<?php

declare(strict_types=1);

namespace DbCommander\Repository;

use DbCommander\Driver\DriverInterface;

/**
 * Returns the list of databases visible to the current user.
 */
final class DatabaseRepository
{
    public function __construct(private readonly DriverInterface $driver) {}

    /**
     * @return array<int, array{name: string}>
     */
    public function listDatabases(): array
    {
        $rows = $this->driver->fetchAll('SHOW DATABASES');

        // SHOW DATABASES returns a single column named 'Database'
        return array_map(
            fn(array $row) => ['name' => array_values($row)[0]],
            $rows
        );
    }
}

<?php

declare(strict_types=1);

namespace DbCommander\Driver;

use DbCommander\Config\ConnectionConfig;
use DbCommander\Exception\DbcException;

final class DriverFactory
{
    /**
     * Create a driver for the named connection.
     * Falls back to the default connection if $name is null.
     */
    public static function create(ConnectionConfig $config, ?string $name = null): DriverInterface
    {
        $connConfig = $name !== null
            ? $config->get($name)
            : $config->getDefault();

        return match ($connConfig['driver']) {
            'pdo'    => new PdoDriver($connConfig),
            'mysqli' => new MysqliDriver($connConfig),
            default  => throw new DbcException(
                "Unsupported driver: '{$connConfig['driver']}'"
            ),
        };
    }
}

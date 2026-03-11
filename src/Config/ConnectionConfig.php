<?php

declare(strict_types=1);

namespace DbCommander\Config;

use DbCommander\Exception\DbcException;

final class ConnectionConfig
{
    /** @var array<string, array<string, mixed>> */
    private array $connections;
    private string $default;

    private function __construct(array $connections, string $default)
    {
        $this->connections = $connections;
        $this->default     = $default;
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new DbcException("Config file not found or not readable: {$path}");
        }

        $json = file_get_contents($path);
        if ($json === false) {
            throw new DbcException("Failed to read config file: {$path}");
        }

        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (!isset($data['connections']) || !is_array($data['connections'])) {
            throw new DbcException("Config must have a 'connections' object");
        }

        $default = $data['default'] ?? array_key_first($data['connections']);

        if (!isset($data['connections'][$default])) {
            throw new DbcException("Default connection '{$default}' not defined in config");
        }

        foreach ($data['connections'] as $name => $conn) {
            self::validateConnection($name, $conn);
        }

        return new self($data['connections'], $default);
    }

    public static function fromArray(array $data): self
    {
        if (!isset($data['connections']) || !is_array($data['connections'])) {
            throw new DbcException("Config must have a 'connections' array");
        }
        $default = $data['default'] ?? array_key_first($data['connections']);
        foreach ($data['connections'] as $name => $conn) {
            self::validateConnection($name, $conn);
        }
        return new self($data['connections'], $default);
    }

    public function get(string $name): array
    {
        if (!isset($this->connections[$name])) {
            throw new DbcException("Connection '{$name}' not found in config");
        }
        return $this->connections[$name];
    }

    public function getDefault(): array
    {
        return $this->connections[$this->default];
    }

    public function getDefaultName(): string
    {
        return $this->default;
    }

    /** @return string[] */
    public function getNames(): array
    {
        return array_keys($this->connections);
    }

    public function has(string $name): bool
    {
        return isset($this->connections[$name]);
    }

    // ── private ──────────────────────────────────────────────

    private static function validateConnection(string $name, mixed $conn): void
    {
        if (!is_array($conn)) {
            throw new DbcException("Connection '{$name}' must be an object");
        }

        $required = ['driver', 'host', 'user', 'password'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $conn)) {
                throw new DbcException("Connection '{$name}' missing required field: '{$field}'");
            }
        }

        $allowed = ['pdo', 'mysqli'];
        if (!in_array($conn['driver'], $allowed, true)) {
            throw new DbcException(
                "Connection '{$name}': driver must be one of: " . implode(', ', $allowed)
            );
        }
    }
}

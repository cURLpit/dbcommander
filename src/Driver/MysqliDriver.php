<?php

declare(strict_types=1);

namespace DbCommander\Driver;

use DbCommander\Exception\DriverException;
use mysqli;
use mysqli_stmt;

final class MysqliDriver implements DriverInterface
{
    private mysqli $mysqli;

    public function __construct(array $config)
    {
        $host    = $config['host']     ?? '127.0.0.1';
        $port    = (int)($config['port']     ?? 3306);
        $charset = $config['charset']  ?? 'utf8mb4';
        $user    = $config['user']     ?? '';
        $pass    = $config['password'] ?? '';

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        try {
            $this->mysqli = new mysqli($host, $user, $pass, '', $port);
            $this->mysqli->set_charset($charset);
        } catch (\mysqli_sql_exception $e) {
            throw new DriverException('mysqli connection failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        try {
            if (empty($params)) {
                $result = $this->mysqli->query($sql);
                if ($result === false) {
                    throw new DriverException('Query failed: ' . $this->mysqli->error);
                }
                return $result->fetch_all(MYSQLI_ASSOC) ?? [];
            }

            $stmt = $this->prepareAndBind($sql, $params);
            $stmt->execute();
            $result = $stmt->get_result();
            return $result->fetch_all(MYSQLI_ASSOC) ?? [];
        } catch (\mysqli_sql_exception $e) {
            throw new DriverException('fetchAll failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $rows = $this->fetchAll($sql, $params);
        return $rows[0] ?? null;
    }

    public function execute(string $sql, array $params = []): int
    {
        try {
            if (empty($params)) {
                $this->mysqli->query($sql);
                return $this->mysqli->affected_rows;
            }

            $stmt = $this->prepareAndBind($sql, $params);
            $stmt->execute();
            return $stmt->affected_rows;
        } catch (\mysqli_sql_exception $e) {
            throw new DriverException('execute failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    public function driverName(): string
    {
        return 'mysqli';
    }

    // ── private ──────────────────────────────────────────────

    private function prepareAndBind(string $sql, array $params): mysqli_stmt
    {
        $stmt = $this->mysqli->prepare($sql);
        if ($stmt === false) {
            throw new DriverException('Failed to prepare statement: ' . $this->mysqli->error);
        }

        if (!empty($params)) {
            $types  = '';
            $values = [];
            foreach ($params as $value) {
                if (is_int($value))    { $types .= 'i'; }
                elseif (is_float($value)) { $types .= 'd'; }
                else                   { $types .= 's'; }
                $values[] = $value;
            }
            $stmt->bind_param($types, ...$values);
        }

        return $stmt;
    }
}

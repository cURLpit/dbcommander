<?php

declare(strict_types=1);

namespace DbCommander\Driver;

use DbCommander\Exception\DriverException;
use PDO;
use PDOException;
use PDOStatement;

final class PdoDriver implements DriverInterface
{
    private PDO $pdo;

    public function __construct(array $config)
    {
        $host    = $config['host']     ?? '127.0.0.1';
        $port    = $config['port']     ?? 3306;
        $charset = $config['charset']  ?? 'utf8mb4';
        $user    = $config['user']     ?? '';
        $pass    = $config['password'] ?? '';

        $dsn = "mysql:host={$host};port={$port};charset={$charset}";

        try {
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            throw new DriverException('PDO connection failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        try {
            $stmt = $this->prepare($sql, $params);
            $result = $stmt->fetchAll();
            return $result !== false ? $result : [];
        } catch (PDOException $e) {
            throw new DriverException('fetchAll failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        try {
            $stmt = $this->prepare($sql, $params);
            $row  = $stmt->fetch();
            return $row !== false ? $row : null;
        } catch (PDOException $e) {
            throw new DriverException('fetchOne failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function execute(string $sql, array $params = []): int
    {
        try {
            $stmt = $this->prepare($sql, $params);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            throw new DriverException('execute failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function quoteIdentifier(string $identifier): string
    {
        // Backtick-escape: replace backtick with double-backtick
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    public function driverName(): string
    {
        return 'pdo';
    }

    // ── private ──────────────────────────────────────────────

    private function prepare(string $sql, array $params): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        if ($stmt === false) {
            throw new DriverException('Failed to prepare statement');
        }
        $stmt->execute($params);
        return $stmt;
    }
}

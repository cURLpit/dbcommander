<?php

declare(strict_types=1);

namespace DbCommander\Http\Handler;

use DbCommander\Driver\DriverInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/sql
 *
 * Body: { "db": "mydb", "sql": "SELECT ..." }
 *
 * Returns:
 *   SELECT  → { columns: [...], rows: [...], total: int, time: float }
 *   Other   → { affected: int, time: float }
 *   Error   → 400 { error: "..." }
 */
final class SqlHandler implements RequestHandlerInterface
{
    use JsonResponseTrait;

    public function __construct(
        private readonly DriverInterface          $driver,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface   $streamFactory,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true);

        $sql = trim((string) ($body['sql'] ?? ''));
        $db  = trim((string) ($body['db']  ?? ''));

        if ($sql === '') {
            return $this->json(['error' => 'No SQL provided'], 400);
        }

        // Optionally switch database context
        if ($db !== '') {
            try {
                $this->driver->execute('USE ' . $this->quoteIdentifier($db));
            } catch (\Throwable $e) {
                return $this->json(['error' => 'Cannot select database: ' . $e->getMessage()], 400);
            }
        }

        $start = microtime(true);

        try {
            $upperSql = strtoupper(ltrim($sql));

            if (str_starts_with($upperSql, 'SELECT') || str_starts_with($upperSql, 'SHOW') || str_starts_with($upperSql, 'DESCRIBE') || str_starts_with($upperSql, 'EXPLAIN')) {
                $rows = $this->driver->fetchAll($sql);
                $time = round((microtime(true) - $start) * 1000, 2);

                $columns = $rows ? array_keys($rows[0]) : [];

                return $this->json([
                    'columns' => $columns,
                    'rows'    => $rows,
                    'total'   => count($rows),
                    'time'    => $time,
                ]);
            } else {
                $affected = $this->driver->execute($sql);
                $time     = round((microtime(true) - $start) * 1000, 2);

                return $this->json([
                    'affected' => $affected,
                    'time'     => $time,
                ]);
            }
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        }
    }

    private function quoteIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    protected function responseFactory(): ResponseFactoryInterface { return $this->responseFactory; }
    protected function streamFactory(): StreamFactoryInterface     { return $this->streamFactory; }
}

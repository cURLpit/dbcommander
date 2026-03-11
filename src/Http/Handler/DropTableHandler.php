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
 * DELETE /api/tables/{db}/{table}
 *
 * Query param: ?type=TABLE|VIEW  (default: TABLE)
 *
 * Returns: { "ok": true }
 */
final class DropTableHandler implements RequestHandlerInterface
{
    use JsonResponseTrait;

    public function __construct(
        private readonly DriverInterface          $driver,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface   $streamFactory,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $db    = $request->getAttribute('db',    '');
        $table = $request->getAttribute('table', '');
        $type  = strtoupper($request->getQueryParams()['type'] ?? 'TABLE');

        if (!in_array($type, ['TABLE', 'VIEW'], true)) {
            return $this->json(['error' => 'Invalid type'], 400);
        }

        try {
            $db_q  = $this->quoteIdentifier($db);
            $tbl_q = $this->quoteIdentifier($table);
            $this->driver->execute("DROP {$type} IF EXISTS {$db_q}.{$tbl_q}");
            return $this->json(['ok' => true]);
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

<?php

declare(strict_types=1);

namespace DbCommander\Http\Handler;

use DbCommander\Repository\RowRepository;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PUT /api/tables/{db}/{table}/rows
 *
 * Body: {
 *   "where": { "id": 42 },
 *   "set":   { "name": "Alice", "email": "alice@example.com" }
 * }
 *
 * Returns: { "affected": 1 }
 */
final class UpdateRowHandler implements RequestHandlerInterface
{
    use JsonResponseTrait;

    public function __construct(
        private readonly RowRepository            $repository,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface   $streamFactory,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $db    = $request->getAttribute('db',    '');
        $table = $request->getAttribute('table', '');

        $body  = json_decode((string) $request->getBody(), true);
        $where = $body['where'] ?? [];
        $set   = $body['set']   ?? [];

        if (!is_array($where) || !is_array($set)) {
            return $this->json(['error' => 'Invalid request body'], 400);
        }

        try {
            $affected = $this->repository->updateRow($db, $table, $where, $set);
            return $this->json(['affected' => $affected]);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        }
    }

    protected function responseFactory(): ResponseFactoryInterface { return $this->responseFactory; }
    protected function streamFactory(): StreamFactoryInterface     { return $this->streamFactory; }
}

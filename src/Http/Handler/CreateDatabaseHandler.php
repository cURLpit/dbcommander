<?php

declare(strict_types=1);

namespace DbCommander\Http\Handler;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/databases
 *
 * Body: { "name": "my_db", "charset": "utf8mb4", "collation": "utf8mb4_unicode_ci" }
 */
final class CreateDatabaseHandler implements RequestHandlerInterface
{
    use JsonResponseTrait;

    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface   $streamFactory,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $driver = $request->getAttribute('__driver');
        $body   = json_decode((string) $request->getBody(), true) ?? [];
        $name   = trim($body['name'] ?? '');

        if ($name === '') {
            return $this->json(['error' => 'Database name is required'], 400);
        }

        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $name)) {
            return $this->json(['error' => 'Invalid database name'], 400);
        }

        $charset   = $body['charset']   ?? 'utf8mb4';
        $collation = $body['collation'] ?? 'utf8mb4_unicode_ci';

        $nameQ      = '`' . str_replace('`', '``', $name)      . '`';
        $charsetQ   = preg_replace('/[^a-z0-9_]/', '', $charset);
        $collationQ = preg_replace('/[^a-z0-9_]/', '', $collation);

        try {
            $driver->execute(
                "CREATE DATABASE IF NOT EXISTS {$nameQ} CHARACTER SET {$charsetQ} COLLATE {$collationQ}"
            );
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }

        return $this->json(['ok' => true, 'name' => $name]);
    }

    protected function responseFactory(): ResponseFactoryInterface { return $this->responseFactory; }
    protected function streamFactory(): StreamFactoryInterface     { return $this->streamFactory; }
}

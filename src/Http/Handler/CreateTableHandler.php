<?php

declare(strict_types=1);

namespace DbCommander\Http\Handler;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/databases/{db}/tables
 *
 * Body: { "name": "my_table" }
 * Creates a minimal table with a single auto-increment primary key column.
 */
final class CreateTableHandler implements RequestHandlerInterface
{
    use JsonResponseTrait;

    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface   $streamFactory,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $driver = $request->getAttribute('__driver');
        $db     = $request->getAttribute('__route_params')['db'] ?? '';
        $body   = json_decode((string) $request->getBody(), true) ?? [];
        $name   = trim($body['name'] ?? '');

        if ($name === '') {
            return $this->json(['error' => 'Table name is required'], 400);
        }

        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $name)) {
            return $this->json(['error' => 'Invalid table name'], 400);
        }

        $dbQ    = '`' . str_replace('`', '``', $db)   . '`';
        $tblQ   = '`' . str_replace('`', '``', $name) . '`';

        try {
            $driver->execute(
                "CREATE TABLE {$dbQ}.{$tblQ} (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }

        return $this->json(['ok' => true, 'db' => $db, 'name' => $name]);
    }

    protected function responseFactory(): ResponseFactoryInterface { return $this->responseFactory; }
    protected function streamFactory(): StreamFactoryInterface     { return $this->streamFactory; }
}

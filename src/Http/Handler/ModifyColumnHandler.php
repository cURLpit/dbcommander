<?php

declare(strict_types=1);

namespace DbCommander\Http\Handler;

use DbCommander\Repository\StructureRepository;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PUT /api/tables/{db}/{table}/structure
 *
 * Body: {
 *   "column": {
 *     "name":      "email",
 *     "full_type": "varchar(255)",
 *     "nullable":  false,
 *     "default":   null,
 *     "comment":   ""
 *   }
 * }
 *
 * Returns: { "ok": true }
 */
final class ModifyColumnHandler implements RequestHandlerInterface
{
    use JsonResponseTrait;

    public function __construct(
        private readonly StructureRepository      $repository,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface   $streamFactory,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $db     = $request->getAttribute('db',    '');
        $table  = $request->getAttribute('table', '');
        $body   = json_decode((string) $request->getBody(), true);
        $column = $body['column'] ?? null;

        if (!is_array($column) || empty($column['name']) || empty($column['full_type'])) {
            return $this->json(['error' => 'Invalid request body'], 400);
        }

        try {
            $this->repository->modifyColumn($db, $table, $column);
            return $this->json(['ok' => true]);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        }
    }

    protected function responseFactory(): ResponseFactoryInterface { return $this->responseFactory; }
    protected function streamFactory(): StreamFactoryInterface     { return $this->streamFactory; }
}

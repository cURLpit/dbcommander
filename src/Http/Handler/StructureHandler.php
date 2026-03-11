<?php

declare(strict_types=1);

namespace DbCommander\Http\Handler;

use DbCommander\Repository\StructureRepository;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class StructureHandler implements RequestHandlerInterface
{
    use JsonResponseTrait;

    public function __construct(
        private readonly StructureRepository      $repository,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface   $streamFactory,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        [$db, $table] = $this->resolveParams($request);

        return $this->json([
            'database'     => $db,
            'table'        => $table,
            'columns'      => $this->repository->getColumns($db, $table),
            'indexes'      => $this->repository->getIndexes($db, $table),
            'foreign_keys' => $this->repository->getForeignKeys($db, $table),
        ]);
    }

    protected function responseFactory(): ResponseFactoryInterface { return $this->responseFactory; }
    protected function streamFactory(): StreamFactoryInterface     { return $this->streamFactory; }

    private function resolveParams(ServerRequestInterface $request): array
    {
        $db    = $request->getAttribute('db')    ?? '';
        $table = $request->getAttribute('table') ?? '';

        if (empty($db) || empty($table)) {
            $parts = array_values(array_filter(explode('/', $request->getUri()->getPath())));
            $db    = $db    ?: ($parts[1] ?? '');
            $table = $table ?: ($parts[2] ?? '');
        }

        if (empty($db) || empty($table)) {
            throw new \InvalidArgumentException('Missing route parameters: {db} and/or {table}');
        }

        return [$db, $table];
    }
}

<?php

declare(strict_types=1);

namespace DbCommander\Http\Handler;

use DbCommander\Repository\RowRepository;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RowListHandler implements RequestHandlerInterface
{
    use JsonResponseTrait;

    public function __construct(
        private readonly RowRepository            $repository,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface   $streamFactory,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        [$db, $table] = $this->resolveParams($request);
        $query        = $request->getQueryParams();

        $limit     = isset($query['limit'])   ? (int)$query['limit']  : 200;
        $offset    = isset($query['offset'])  ? (int)$query['offset'] : 0;
        $orderBy   = $query['order_by']       ?? null;
        $direction = $query['direction']      ?? 'ASC';
        $count     = ($query['count'] ?? '1') !== '0';

        $result = $this->repository->getRows(
            database:   $db,
            table:      $table,
            limit:      $limit,
            offset:     $offset,
            orderBy:    $orderBy,
            direction:  $direction,
            countTotal: $count,
        );

        return $this->json([
            'database' => $db,
            'table'    => $table,
            ...$result,
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

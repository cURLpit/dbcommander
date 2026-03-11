<?php

declare(strict_types=1);

namespace DbCommander\Http\Handler;

use DbCommander\Repository\TableRepository;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class TableListHandler implements RequestHandlerInterface
{
    use JsonResponseTrait;

    public function __construct(
        private readonly TableRepository          $repository,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface   $streamFactory,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $db = $request->getAttribute('db')
            ?? $this->extractSegment($request->getUri()->getPath(), 2);

        if (empty($db)) {
            throw new \InvalidArgumentException('Missing route parameter: {db}');
        }

        return $this->json([
            'database' => $db,
            'tables'   => $this->repository->listTables($db),
            'routines' => $this->repository->listRoutines($db),
            'triggers' => $this->repository->listTriggers($db),
        ]);
    }

    protected function responseFactory(): ResponseFactoryInterface { return $this->responseFactory; }
    protected function streamFactory(): StreamFactoryInterface     { return $this->streamFactory; }

    private function extractSegment(string $path, int $index): string
    {
        $parts = array_values(array_filter(explode('/', $path)));
        return $parts[$index] ?? '';
    }
}

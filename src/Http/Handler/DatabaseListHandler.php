<?php

declare(strict_types=1);

namespace DbCommander\Http\Handler;

use DbCommander\Repository\DatabaseRepository;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class DatabaseListHandler implements RequestHandlerInterface
{
    use JsonResponseTrait;

    public function __construct(
        private readonly DatabaseRepository       $repository,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface   $streamFactory,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $databases = $this->repository->listDatabases();
        return $this->json(['databases' => $databases]);
    }

    protected function responseFactory(): ResponseFactoryInterface { return $this->responseFactory; }
    protected function streamFactory(): StreamFactoryInterface     { return $this->streamFactory; }
}

<?php

declare(strict_types=1);

namespace DbCommander\Http\Handler;

use DbCommander\Config\ConnectionConfig;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/connections
 *
 * Returns connection list without sensitive fields (no password).
 */
final class ConnectionListHandler implements RequestHandlerInterface
{
    use JsonResponseTrait;

    public function __construct(
        private readonly ConnectionConfig         $config,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface   $streamFactory,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $names = $this->config->getNames();
        $default = $this->config->getDefaultName();

        $connections = array_map(function (string $name) use ($default): array {
            $conn = $this->config->get($name);
            return [
                'name'    => $name,
                'host'    => $conn['host'],
                'port'    => $conn['port']   ?? 3306,
                'user'    => $conn['user'],
                'driver'  => $conn['driver'],
                'default' => $name === $default,
            ];
        }, $names);

        return $this->json([
            'connections' => $connections,
            'default'     => $default,
        ]);
    }

    protected function responseFactory(): ResponseFactoryInterface { return $this->responseFactory; }
    protected function streamFactory(): StreamFactoryInterface     { return $this->streamFactory; }
}

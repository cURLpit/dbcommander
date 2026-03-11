<?php

declare(strict_types=1);

namespace DbCommander\Http\Middleware;

use DbCommander\Config\ConnectionConfig;
use DbCommander\Driver\DriverFactory;
use DbCommander\Driver\DriverInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Resolves the active connection from the request and stores it
 * as a DriverInterface instance in the __driver request attribute.
 *
 * Connection priority:
 *   1. X-Connection header  (useful for panel-specific requests)
 *   2. ?connection= query param
 *   3. Config default
 *
 * If the requested connection name is unknown, sets __connection_error
 * so the JumpMiddleware downstream can route to an error response.
 */
final class ConnectionMiddleware implements MiddlewareInterface
{
    /** @var array<string, DriverInterface> connection pool */
    private array $pool = [];

    public function __construct(
        private readonly ConnectionConfig         $config,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface   $streamFactory,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $name = $request->getHeaderLine('X-Connection')
            ?: ($request->getQueryParams()['connection'] ?? null)
            ?: $this->config->getDefaultName();

        if (!$this->config->has($name)) {
            return $handler->handle(
                $request->withAttribute('__connection_error', "Unknown connection: '{$name}'")
            );
        }

        $request = $request
            ->withAttribute('__driver',     $this->getDriver($name))
            ->withAttribute('__connection', $name);

        // Optional second connection for cross-connection operations (e.g. Copy)
        $targetName = $request->getHeaderLine('X-Connection-Target');
        if ($targetName !== '' && $targetName !== $name) {
            if (!$this->config->has($targetName)) {
                return $handler->handle(
                    $request->withAttribute('__connection_error', "Unknown target connection: '{$targetName}'")
                );
            }
            $request = $request->withAttribute('__driver_target', $this->getDriver($targetName));
        }

        return $handler->handle($request);
    }

    private function getDriver(string $name): DriverInterface
    {
        if (!isset($this->pool[$name])) {
            $this->pool[$name] = DriverFactory::create($this->config, $name);
        }
        return $this->pool[$name];
    }
}

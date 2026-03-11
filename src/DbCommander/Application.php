<?php

declare(strict_types=1);

namespace DbCommander;

use Curlpit\App\Application as BaseApplication;
use Curlpit\Core\ConfigLoader;
use Curlpit\Core\Middleware\DispatchMiddleware;
use Curlpit\Core\Middleware\JumpMiddleware;
use Curlpit\Core\Middleware\RoutingMiddleware;
use Curlpit\Core\Middleware\SetVariableMiddleware;
use DbCommander\Config\ConnectionConfig;
use DbCommander\Http\Handler\ConnectionListHandler;
use DbCommander\Http\Handler\CopyTableHandler;
use DbCommander\Http\Handler\DatabaseListHandler;
use DbCommander\Http\Handler\FrontendHandler;
use DbCommander\Http\Handler\DropTableHandler;
use DbCommander\Http\Handler\ModifyColumnHandler;
use DbCommander\Http\Handler\RowListHandler;
use DbCommander\Http\Handler\SqlHandler;
use DbCommander\Http\Handler\StructureHandler;
use DbCommander\Http\Handler\TableListHandler;
use DbCommander\Http\Handler\UpdateRowHandler;
use DbCommander\Http\Middleware\ConnectionMiddleware;
use DbCommander\Repository\DatabaseRepository;
use DbCommander\Repository\RowRepository;
use DbCommander\Repository\StructureRepository;
use DbCommander\Repository\TableRepository;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class Application extends BaseApplication
{
    public function __construct(
        private readonly ConnectionConfig $config,
        ResponseFactoryInterface          $responseFactory,
        StreamFactoryInterface            $streamFactory,
    ) {
        parent::__construct($responseFactory, $streamFactory);
    }

    protected function defaultConfigLoader(): ConfigLoader
    {
        return ConfigLoader::fromFile(dirname(__DIR__, 2) . '/src/App/Config/middleware.json');
    }

    // ── Middleware factory ────────────────────────────────────

    protected function instantiate(string $class, array $options): MiddlewareInterface
    {
        $rf = $this->responseFactory;
        $sf = $this->streamFactory;

        return match ($class) {
            \Curlpit\Core\Middleware\ErrorHandlerMiddleware::class =>
                new \Curlpit\Core\Middleware\ErrorHandlerMiddleware(
                    $rf, $sf,
                    debug: (bool) ($options['debug'] ?? false),
                ),

            \DbCommander\Http\Middleware\CorsMiddleware::class =>
                new \DbCommander\Http\Middleware\CorsMiddleware(
                    $rf,
                    allowedOrigins: $options['allowed_origins'] ?? ['*'],
                ),

            \DbCommander\Http\Middleware\JsonResponseMiddleware::class =>
                new \DbCommander\Http\Middleware\JsonResponseMiddleware(
                    $rf, $sf,
                    debug: (bool) ($options['debug'] ?? false),
                ),

            \DbCommander\Http\Middleware\ConnectionMiddleware::class =>
                new ConnectionMiddleware($this->config, $rf, $sf),

            \Curlpit\Core\Middleware\RoutingMiddleware::class =>
                new RoutingMiddleware($options['routes'] ?? []),

            \Curlpit\Core\Middleware\DispatchMiddleware::class =>
                new DispatchMiddleware(
                    fn(string $name) => $this->resolve($name),
                    $rf, $sf,
                ),

            default => parent::instantiate($class, $options),
        };
    }

    // ── Handler resolver ─────────────────────────────────────
    // A driver a request attribútumból jön – minden request
    // saját connection-öt hordoz, nincs shared state.

    private function resolve(string $shortName): RequestHandlerInterface
    {
        $rf = $this->responseFactory;
        $sf = $this->streamFactory;

        // Handlerek amik nem igényelnek drivert
        if ($shortName === 'ConnectionListHandler') {
            return new ConnectionListHandler($this->config, $rf, $sf);
        }
        if ($shortName === 'FrontendHandler') {
            return new FrontendHandler($rf, $sf, dirname(__DIR__, 2) . '/resources/app.html');
        }
        if ($shortName === 'CopyTableHandler') {
            return new CopyTableHandler($rf, $sf);
        }

        // Minden más handler requestből olvassa a drivert
        return new class($shortName, $rf, $sf) implements RequestHandlerInterface {
            public function __construct(
                private readonly string                  $name,
                private readonly ResponseFactoryInterface $rf,
                private readonly StreamFactoryInterface   $sf,
            ) {}

            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $driver = $request->getAttribute('__driver');

                if ($driver === null) {
                    $error = $request->getAttribute('__connection_error', 'No database connection');
                    $body  = $this->sf->createStream(json_encode(['error' => $error]));
                    return $this->rf->createResponse(400)
                        ->withHeader('Content-Type', 'application/json')
                        ->withBody($body);
                }

                $dbRepo    = fn() => new DatabaseRepository($driver);
                $tableRepo = fn() => new TableRepository($driver);
                $rowRepo   = fn() => new RowRepository($driver);
                $strRepo   = fn() => new StructureRepository($driver);

                $handler = match ($this->name) {
                    'DatabaseListHandler' => new DatabaseListHandler($dbRepo(),   $this->rf, $this->sf),
                    'TableListHandler'    => new TableListHandler($tableRepo(),    $this->rf, $this->sf),
                    'RowListHandler'      => new RowListHandler($rowRepo(),        $this->rf, $this->sf),
                    'StructureHandler'    => new StructureHandler($strRepo(),      $this->rf, $this->sf),
                    'SqlHandler'          => new SqlHandler($driver,               $this->rf, $this->sf),
                    'UpdateRowHandler'    => new UpdateRowHandler($rowRepo(),      $this->rf, $this->sf),
                    'ModifyColumnHandler' => new ModifyColumnHandler($strRepo(),   $this->rf, $this->sf),
                    'DropTableHandler'    => new DropTableHandler($driver,         $this->rf, $this->sf),
                    default               => throw new \RuntimeException("Unknown handler: {$this->name}"),
                };

                return $handler->handle($request);
            }
        };
    }
}

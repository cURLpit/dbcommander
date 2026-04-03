<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Curlpit\Core\Emitter;
use DbCommander\Application;
use DbCommander\Config\ConnectionConfig;

// ── Environment ───────────────────────────────────────────────────────────────
$envFile = __DIR__ . '/../.env';
$env     = is_file($envFile) ? (parse_ini_file($envFile) ?: []) : [];
define('APP_ENV', $env['APP_ENV'] ?? 'prod');

// ── PSR-17 factory auto-detect ────────────────────────────────────────────────
if (class_exists(\Nyholm\Psr7\Factory\Psr17Factory::class)) {
    $factory         = new \Nyholm\Psr7\Factory\Psr17Factory();
    $responseFactory = $factory;
    $streamFactory   = $factory;
    $request         = (new \Nyholm\Psr7Server\ServerRequestCreator(
        $factory, $factory, $factory, $factory
    ))->fromGlobals();

} elseif (class_exists(\GuzzleHttp\Psr7\HttpFactory::class)) {
    $factory         = new \GuzzleHttp\Psr7\HttpFactory();
    $responseFactory = $factory;
    $streamFactory   = $factory;
    $request         = \GuzzleHttp\Psr7\ServerRequest::fromGlobals();

} else {
    http_response_code(500);
    echo json_encode(['error' => 'No PSR-7 implementation found. Run: composer require nyholm/psr7 nyholm/psr7-server']);
    exit(1);
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────
$config     = ConnectionConfig::fromFile(__DIR__ . '/../config/connections.json');
$connName   = $request->getQueryParams()['connection'] ?? null;

$app      = new Application($config, $responseFactory, $streamFactory, $connName);
$response = $app->handle($request);

// ── Emit ─────────────────────────────────────────────────────────────────────
(new Emitter())->emit($response);

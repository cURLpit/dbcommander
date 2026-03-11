<?php

declare(strict_types=1);

namespace DbCommander\Http\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Adds CORS headers so the frontend (served from a different origin
 * in development) can call the API.
 *
 * In production, restrict $allowedOrigins to your actual domain(s).
 */
final class CorsMiddleware implements MiddlewareInterface
{
    /** @param string[] $allowedOrigins  Use ['*'] to allow all (dev only). */
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly array $allowedOrigins = ['*'],
    ) {}

    public function process(
        ServerRequestInterface  $request,
        RequestHandlerInterface $next,
    ): ResponseInterface {
        // Handle pre-flight
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            return $this->addCorsHeaders(
                $request,
                $this->responseFactory->createResponse(204)
            );
        }

        return $this->addCorsHeaders($request, $next->handle($request));
    }

    // ── private ──────────────────────────────────────────────

    private function addCorsHeaders(
        ServerRequestInterface $request,
        ResponseInterface      $response,
    ): ResponseInterface {
        $origin = $request->getHeaderLine('Origin');
        $allow  = in_array('*', $this->allowedOrigins, true)
            ? '*'
            : (in_array($origin, $this->allowedOrigins, true) ? $origin : '');

        if ($allow === '') {
            return $response;
        }

        return $response
            ->withHeader('Access-Control-Allow-Origin',  $allow)
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Accept');
    }
}

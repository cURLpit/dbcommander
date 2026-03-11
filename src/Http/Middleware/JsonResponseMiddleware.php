<?php

declare(strict_types=1);

namespace DbCommander\Http\Middleware;

use DbCommander\Exception\NotFoundException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * PSR-15 middleware that:
 *  - catches all exceptions and converts them to JSON error responses
 *  - sets Content-Type: application/json on every response
 */
final class JsonResponseMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface   $streamFactory,
        private readonly bool                     $debug = false,
    ) {}

    public function process(
        ServerRequestInterface  $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        try {
            $response = $handler->handle($request);
        } catch (NotFoundException $e) {
            return $this->jsonError(404, $e->getMessage(), $e);
        } catch (\InvalidArgumentException $e) {
            return $this->jsonError(400, $e->getMessage(), $e);
        } catch (Throwable $e) {
            return $this->jsonError(500, 'Internal server error', $e);
        }

        // Only set Content-Type: application/json if not already set by the handler
        if (!$response->hasHeader('Content-Type')) {
            return $response->withHeader('Content-Type', 'application/json');
        }

        return $response;
    }

    // ── private ──────────────────────────────────────────────

    private function jsonError(int $status, string $message, Throwable $e): ResponseInterface
    {
        $body = ['error' => $message];

        if ($this->debug) {
            $body['debug'] = [
                'class'   => get_class($e),
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => explode("\n", $e->getTraceAsString()),
            ];
        }

        $json   = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stream = $this->streamFactory->createStream($json);

        return $this->responseFactory
            ->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($stream);
    }
}

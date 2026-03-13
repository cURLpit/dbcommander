<?php

declare(strict_types=1);

namespace DbCommander\Http\Middleware;

use Curlpit\Core\LoopContext;
use DbCommander\Http\Handler\JsonResponseTrait;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Returns a JSON response after the copy loop completes.
 *
 * Reads from request attributes:
 *   __copy_source  – ['db' => ..., 'table' => ...]
 *   __copy_target  – ['db' => ..., 'table' => ...]
 *   __loop_context – LoopContext with inserted count
 */
final class CopyResponseMiddleware implements MiddlewareInterface
{
    use JsonResponseTrait;

    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface   $streamFactory,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var LoopContext $ctx */
        $ctx    = $request->getAttribute('__loop_context');
        $source = $request->getAttribute('__copy_source');
        $target = $request->getAttribute('__copy_target');
        $tgtDriver = $request->getAttribute('__driver_target')
                     ?? $request->getAttribute('__driver');

        // Refresh table statistics so the panel shows the correct row count
        $db_q  = '`' . str_replace('`', '``', $target['db'])    . '`';
        $tbl_q = '`' . str_replace('`', '``', $target['table']) . '`';
        try {
            $tgtDriver->execute("ANALYZE TABLE {$db_q}.{$tbl_q}");
        } catch (\Throwable) {
            // Non-critical – ignore if ANALYZE is not supported
        }

        return $this->json([
            'ok'       => true,
            'inserted' => $ctx->get('inserted', 0),
            'source'   => "{$source['db']}.{$source['table']}",
            'target'   => "{$target['db']}.{$target['table']}",
        ]);
    }

    protected function responseFactory(): ResponseFactoryInterface { return $this->responseFactory; }
    protected function streamFactory(): StreamFactoryInterface     { return $this->streamFactory; }
}

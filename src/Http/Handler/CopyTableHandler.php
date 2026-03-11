<?php

declare(strict_types=1);

namespace DbCommander\Http\Handler;

use Curlpit\Core\LoopContext;
use Curlpit\Core\LoopMiddleware;
use Curlpit\Core\RequestHandler;
use Curlpit\Core\Middleware\LoopMiddleware as LoopMW;
use DbCommander\Driver\DriverInterface;
use DbCommander\Http\Middleware\TableCopySourceMiddleware;
use DbCommander\Http\Middleware\TableCopyTargetMiddleware;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/copy
 *
 * Body: {
 *   "source": { "db": "shop", "table": "users" },
 *   "target": { "db": "shop_backup", "table": "users" },
 *   "mode":   "append" | "replace"   (default: append)
 * }
 *
 * Uses LoopMiddleware internally to page through source rows
 * and insert them into the target in batches of 1000.
 *
 * The source driver comes from __driver (active connection).
 * The target driver comes from __driver_target request attribute,
 * set by the frontend via X-Connection-Target header.
 */
final class CopyTableHandler implements RequestHandlerInterface
{
    use JsonResponseTrait;

    private const PAGE_SIZE = 1000;

    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface   $streamFactory,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body   = json_decode((string) $request->getBody(), true) ?? [];
        $source = $body['source'] ?? null;
        $target = $body['target'] ?? null;
        $mode   = $body['mode']   ?? 'append';

        if (!$source || !$target || empty($source['db']) || empty($source['table'])
            || empty($target['db']) || empty($target['table'])) {
            return $this->json(['error' => 'source and target (db + table) are required'], 400);
        }

        $srcDriver = $request->getAttribute('__driver');
        $tgtDriver = $request->getAttribute('__driver_target') ?? $srcDriver;

        if (!$srcDriver instanceof DriverInterface) {
            return $this->json(['error' => 'No source connection available'], 400);
        }

        try {
            // Optional: truncate target first
            if ($mode === 'replace') {
                $db_q  = '`' . str_replace('`', '``', $target['db'])    . '`';
                $tbl_q = '`' . str_replace('`', '``', $target['table']) . '`';
                $tgtDriver->execute("TRUNCATE TABLE {$db_q}.{$tbl_q}");
            }

            $rf = $this->responseFactory;
            $sf = $this->streamFactory;

            $srcMiddleware = new TableCopySourceMiddleware(
                $srcDriver,
                $source['db'],
                $source['table'],
                self::PAGE_SIZE,
            );

            $tgtMiddleware = new TableCopyTargetMiddleware(
                $tgtDriver,
                $target['db'],
                $target['table'],
            );

            // Build the loop
            $loop = new LoopMW(
                // Condition: continue while has_more is true in the context
                fn(LoopContext $ctx) => $ctx->get('has_more', true),
                // Body factory: source read → target write
                fn() => (function () use ($srcMiddleware, $tgtMiddleware, $rf): RequestHandler {
                    $inner = new RequestHandler($rf);
                    $inner->add($srcMiddleware);
                    $inner->add($tgtMiddleware);
                    return $inner;
                })(),
                $rf,
                $sf,
                maxIterations: 10000,
            );

            // Run the loop – we need a terminal handler to cap the chain
            $outerHandler = new RequestHandler($rf);
            $outerHandler->add($loop);

            $ctx     = new LoopContext(['has_more' => true, 'offset' => 0]);
            $request = $request->withAttribute('__loop_context', $ctx)
                                ->withAttribute('__pc', 0);

            $outerHandler->handle($request);

            return $this->json([
                'ok'       => true,
                'inserted' => $ctx->get('inserted', 0),
                'source'   => "{$source['db']}.{$source['table']}",
                'target'   => "{$target['db']}.{$target['table']}",
            ]);

        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    protected function responseFactory(): ResponseFactoryInterface { return $this->responseFactory; }
    protected function streamFactory(): StreamFactoryInterface     { return $this->streamFactory; }
}

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
 * Returns a JSON response after the database copy loop completes.
 *
 * Reads from LoopContext:
 *   src_db   – source database name
 *   tgt_db   – target database name
 *   inserted – total rows inserted across all tables
 *   details  – map of { tableName => rowsInserted }
 *
 * Runs ANALYZE TABLE on every copied table to refresh row-count statistics
 * so the target panel shows accurate numbers immediately after the copy.
 * (Non-critical – silently ignored if unsupported.)
 */
final class DbCopyResponseMiddleware implements MiddlewareInterface
{
    use JsonResponseTrait;

    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface   $streamFactory,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var LoopContext $ctx */
        $ctx       = $request->getAttribute('__loop_context');
        $tgtDriver = $request->getAttribute('__driver_target')
                     ?? $request->getAttribute('__driver');

        $srcDb   = $ctx->get('src_db');
        $tgtDb   = $ctx->get('tgt_db');
        $details = $ctx->get('details', []);  // { tableName => rowsInserted }
        $tgtDbQ  = '`' . str_replace('`', '``', $tgtDb) . '`';

        // Refresh table statistics on target (mirrors CopyResponseMiddleware)
        foreach (array_keys($details) as $table) {
            try {
                $tblQ = '`' . str_replace('`', '``', $table) . '`';
                $tgtDriver->execute("ANALYZE TABLE {$tgtDbQ}.{$tblQ}");
            } catch (\Throwable) {
                // Non-critical – ignore if ANALYZE is not supported
            }
        }

        // Convert details map to ordered list for JSON response
        $detailsList = array_map(
            static fn(string $table, int $rows): array => [
                'table'         => $table,
                'rows_inserted' => $rows,
            ],
            array_keys($details),
            array_values($details),
        );

        return $this->json([
            'ok'            => true,
            'source'        => $srcDb,
            'target'        => $tgtDb,
            'tables_copied' => count($details),
            'rows_inserted' => $ctx->get('inserted', 0),
            'details'       => $detailsList,
        ]);
    }

    protected function responseFactory(): ResponseFactoryInterface { return $this->responseFactory; }
    protected function streamFactory(): StreamFactoryInterface     { return $this->streamFactory; }
}

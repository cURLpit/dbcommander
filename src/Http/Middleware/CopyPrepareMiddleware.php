<?php

declare(strict_types=1);

namespace DbCommander\Http\Middleware;

use Curlpit\Core\LoopContext;
use DbCommander\Driver\DriverInterface;
use DbCommander\Http\Handler\JsonResponseTrait;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Prepares a table copy operation.
 *
 * Reads POST body: { source: {db, table}, target: {db, table}, mode: "append"|"replace" }
 * Validates drivers and parameters.
 * Creates the target table if it does not exist (cloned from source schema).
 * Optionally truncates the target table (mode=replace).
 * Initialises the LoopContext and sets request attributes for downstream middleware.
 *
 * Sets on request:
 *   __copy_source   – ['db' => ..., 'table' => ...]
 *   __copy_target   – ['db' => ..., 'table' => ...]
 *   __driver_target – target DriverInterface
 *   __loop_context  – LoopContext with has_more=true, offset=0, inserted=0
 */
final class CopyPrepareMiddleware implements MiddlewareInterface
{
    use JsonResponseTrait;

    private const PAGE_SIZE = 1000;

    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface   $streamFactory,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $body   = json_decode((string) $request->getBody(), true) ?? [];
        $source = $body['source'] ?? null;
        $target = $body['target'] ?? null;
        $mode   = $body['mode']   ?? 'append';

        if (!$source || !$target
            || empty($source['db']) || empty($source['table'])
            || empty($target['db']) || empty($target['table'])
        ) {
            return $this->json(['error' => 'source and target (db + table) are required'], 400);
        }

        $srcDriver = $request->getAttribute('__driver');
        $tgtDriver = $request->getAttribute('__driver_target') ?? $srcDriver;

        if (!$srcDriver instanceof DriverInterface) {
            return $this->json(['error' => 'No source connection available'], 400);
        }

        // Create target table if it does not exist, cloned from source schema
        try {
            $this->ensureTargetTable($srcDriver, $tgtDriver, $source, $target);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Could not create target table: ' . $e->getMessage()], 500);
        }

        if ($mode === 'replace') {
            $db_q  = '`' . str_replace('`', '``', $target['db'])    . '`';
            $tbl_q = '`' . str_replace('`', '``', $target['table']) . '`';
            $tgtDriver->execute("TRUNCATE TABLE {$db_q}.{$tbl_q}");
        }

        $ctx = new LoopContext([
            'has_more' => true,
            'offset'   => 0,
            'inserted' => 0,
            'limit'    => self::PAGE_SIZE,
        ]);

        $request = $request
            ->withAttribute('__copy_source',   $source)
            ->withAttribute('__copy_target',   $target)
            ->withAttribute('__driver_target', $tgtDriver)
            ->withAttribute('__loop_context',  $ctx);

        return $handler->handle($request);
    }

    // ── private ──────────────────────────────────────────────

    private function ensureTargetTable(
        DriverInterface $srcDriver,
        DriverInterface $tgtDriver,
        array           $source,
        array           $target,
    ): void {
        $src_db_q  = '`' . str_replace('`', '``', $source['db'])    . '`';
        $src_tbl_q = '`' . str_replace('`', '``', $source['table']) . '`';
        $tgt_db_q  = '`' . str_replace('`', '``', $target['db'])    . '`';
        $tgt_tbl_q = '`' . str_replace('`', '``', $target['table']) . '`';

        // Fetch the CREATE TABLE statement from source
        $row = $srcDriver->fetchOne(
            "SHOW CREATE TABLE {$src_db_q}.{$src_tbl_q}"
        );

        // The second column is 'Create Table' or 'Create View'
        $createSql = $row['Create Table'] ?? $row['Create View'] ?? null;

        if (!$createSql) {
            throw new \RuntimeException("Could not retrieve CREATE TABLE for {$source['db']}.{$source['table']}");
        }

        // Replace source table name with target table name
        $createSql = preg_replace(
            '/^CREATE TABLE `[^`]+`/i',
            "CREATE TABLE IF NOT EXISTS {$tgt_tbl_q}",
            $createSql
        );

        // Remove reserved tablespace clauses (e.g. TABLESPACE `mysql`)
        $createSql = preg_replace('/\s+TABLESPACE\s+`[^`]+`/i', '', $createSql);

        $tgtDriver->execute("USE {$tgt_db_q}");
        $tgtDriver->execute($createSql);
    }

    protected function responseFactory(): ResponseFactoryInterface { return $this->responseFactory; }
    protected function streamFactory(): StreamFactoryInterface     { return $this->streamFactory; }
}

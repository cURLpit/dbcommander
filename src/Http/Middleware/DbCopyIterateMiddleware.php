<?php

declare(strict_types=1);

namespace DbCommander\Http\Middleware;

use Curlpit\Core\LoopContext;
use DbCommander\Driver\DriverInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Per-iteration orchestrator for the database copy loop.
 *
 * Sits first inside the LoopMiddleware body, wrapping the reused
 * TableCopySourceMiddleware + TableCopyTargetMiddleware pair.
 *
 * PRE-HANDLER (before delegating to the next middleware in the loop body):
 *   – Determines the current table from LoopContext[tables][table_index].
 *   – On the first page of a table (offset == 0):
 *       · Clones the source table schema into the target DB (IF NOT EXISTS).
 *       · Truncates the target table when mode = "replace".
 *   – Injects __copy_source and __copy_target onto the request so that the
 *     downstream TableCopySourceMiddleware / TableCopyTargetMiddleware operate
 *     on the correct table without any modification.
 *
 * POST-HANDLER (after TableCopySource + TableCopyTarget have run):
 *   – Reads the page row count from LoopContext[rows] to accumulate statistics.
 *   – If the page was a full page (has_more == true): the offset was already
 *     advanced by TableCopySourceMiddleware; nothing extra to do.
 *   – If the page was the last page of the current table (has_more == false):
 *       · Advances table_index by one and resets offset to 0.
 *       · Sets has_more = (table_index < count(tables)), so the outer
 *         LoopMiddleware continues only if more tables remain.
 *
 * LoopContext keys consumed:
 *   tables, table_index, offset, limit, mode, src_db, tgt_db
 *
 * LoopContext keys written:
 *   has_more, table_index, offset, inserted, details
 */
final class DbCopyIterateMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var LoopContext $ctx */
        $ctx       = $request->getAttribute('__loop_context');
        $srcDriver = $request->getAttribute('__driver');
        $tgtDriver = $request->getAttribute('__driver_target') ?? $srcDriver;

        $tables      = $ctx->get('tables',      []);
        $tableIndex  = $ctx->get('table_index', 0);
        $offset      = $ctx->get('offset',      0);
        $srcDb       = $ctx->get('src_db');
        $tgtDb       = $ctx->get('tgt_db');
        $mode        = $ctx->get('mode', 'append');
        $table       = $tables[$tableIndex];

        // ── First page of this table: clone schema ───────────────────────────
        if ($offset === 0) {
            $this->ensureTargetTable($srcDriver, $tgtDriver, $srcDb, $tgtDb, $table);

            if ($mode === 'replace') {
                $tgtDriver->execute(
                    'TRUNCATE TABLE ' . $this->quoteId($tgtDb) . '.' . $this->quoteId($table)
                );
            }
        }

        // ── Set copy source / target on the request ──────────────────────────
        // TableCopySourceMiddleware and TableCopyTargetMiddleware read these
        // attributes unchanged – no modification to those classes required.
        $request = $request
            ->withAttribute('__copy_source',   ['db' => $srcDb, 'table' => $table])
            ->withAttribute('__copy_target',   ['db' => $tgtDb, 'table' => $table])
            ->withAttribute('__driver_target', $tgtDriver);

        // ── Delegate to TableCopySourceMiddleware → TableCopyTargetMiddleware ─
        $response = $handler->handle($request);

        // ── Post-page: accumulate stats and advance state ────────────────────
        $pageRows     = count($ctx->get('rows', []));
        $rowPageHasMore = $ctx->get('has_more', false);

        // Accumulate per-table and total inserted counts
        $details = $ctx->get('details', []);
        $details[$table] = ($details[$table] ?? 0) + $pageRows;
        $ctx->set('details',  $details);
        $ctx->set('inserted', $ctx->get('inserted', 0) + $pageRows);

        if (!$rowPageHasMore) {
            // Current table exhausted → move to next table
            $nextIndex = $tableIndex + 1;
            $ctx->set('table_index', $nextIndex);
            $ctx->set('offset',      0);
            // Outer loop continues only if there are more tables
            $ctx->set('has_more', $nextIndex < count($tables));
        }
        // else: has_more = true and offset are already set by TableCopySourceMiddleware
        // (offset was advanced by PAGE_SIZE, has_more = true)

        return $response;
    }

    // ── private helpers ──────────────────────────────────────────────────────

    /**
     * Clones the source table schema into the target database.
     * Mirrors CopyPrepareMiddleware::ensureTargetTable() exactly.
     */
    private function ensureTargetTable(
        DriverInterface $srcDriver,
        DriverInterface $tgtDriver,
        string          $srcDb,
        string          $tgtDb,
        string          $table,
    ): void {
        $srcDbQ = $this->quoteId($srcDb);
        $tblQ   = $this->quoteId($table);
        $tgtDbQ = $this->quoteId($tgtDb);

        $row = $srcDriver->fetchOne("SHOW CREATE TABLE {$srcDbQ}.{$tblQ}");

        $createSql = $row['Create Table'] ?? $row['Create View'] ?? null;
        if (!$createSql) {
            throw new \RuntimeException(
                "Could not retrieve CREATE TABLE for {$srcDb}.{$table}"
            );
        }

        // Replace source table name with target table name
        $createSql = preg_replace(
            '/^CREATE TABLE `[^`]+`/i',
            "CREATE TABLE IF NOT EXISTS {$tblQ}",
            $createSql
        );

        // Strip reserved tablespace clauses (e.g. TABLESPACE `mysql`)
        $createSql = preg_replace('/\s+TABLESPACE\s+`[^`]+`/i', '', $createSql);

        $tgtDriver->execute("USE {$tgtDbQ}");
        $tgtDriver->execute($createSql);
    }

    private function quoteId(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}

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
 * Prepares a database copy operation.
 *
 * Reads POST body: { source: {db}, target: {db}, mode: "append"|"replace" }
 * Validates drivers and parameters.
 * Checks the source database exists.
 * Creates the target database if it does not exist (cloned charset/collation).
 * Lists all BASE TABLEs in the source database.
 * Initialises the LoopContext and sets request attributes for downstream middleware.
 *
 * Sets on request:
 *   __driver_target  – target DriverInterface
 *   __loop_context   – LoopContext with:
 *       has_more    – true if there are tables to copy, false otherwise
 *       tables      – ordered list of table names to copy
 *       table_index – index into tables[] of the current table (starts at 0)
 *       offset      – row offset within the current table (starts at 0)
 *       limit       – page size (PAGE_SIZE)
 *       mode        – "append"|"replace"
 *       src_db      – source database name
 *       tgt_db      – target database name
 *       inserted    – running total rows inserted (across all tables)
 *       details     – map of { tableName => rowsInserted } (accumulated per iteration)
 */
final class DbCopyPrepareMiddleware implements MiddlewareInterface
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

        if (!$source || !$target || empty($source['db']) || empty($target['db'])) {
            return $this->json(['error' => 'source.db and target.db are required'], 400);
        }

        $srcDb = $source['db'];
        $tgtDb = $target['db'];

        $srcDriver = $request->getAttribute('__driver');
        $tgtDriver = $request->getAttribute('__driver_target') ?? $srcDriver;

        if (!$srcDriver instanceof DriverInterface) {
            return $this->json(['error' => 'No source connection available'], 400);
        }

        // ── Verify source DB exists ──────────────────────────────────────────
        $srcSchema = $srcDriver->fetchOne(
            "SELECT DEFAULT_CHARACTER_SET_NAME AS charset,
                    DEFAULT_COLLATION_NAME      AS collation
             FROM information_schema.SCHEMATA
             WHERE SCHEMA_NAME = ?",
            [$srcDb]
        );

        if (!$srcSchema) {
            return $this->json(['error' => "Source database '{$srcDb}' not found"], 404);
        }

        // ── Create target DB if it does not exist ────────────────────────────
        try {
            $charset   = preg_replace('/[^a-z0-9_]/', '', $srcSchema['charset']   ?? 'utf8mb4');
            $collation = preg_replace('/[^a-z0-9_]/', '', $srcSchema['collation'] ?? 'utf8mb4_unicode_ci');
            $tgtDbQ    = '`' . str_replace('`', '``', $tgtDb) . '`';
            $tgtDriver->execute(
                "CREATE DATABASE IF NOT EXISTS {$tgtDbQ} CHARACTER SET {$charset} COLLATE {$collation}"
            );
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Could not create target database: ' . $e->getMessage()], 500);
        }

        // ── List BASE TABLEs in source (views excluded) ──────────────────────
        $rows   = $srcDriver->fetchAll(
            "SELECT TABLE_NAME
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'
             ORDER BY TABLE_NAME",
            [$srcDb]
        );
        $tables = array_column($rows, 'TABLE_NAME');

        // ── Initialise LoopContext ───────────────────────────────────────────
        $ctx = new LoopContext([
            'has_more'    => !empty($tables),
            'tables'      => $tables,
            'table_index' => 0,
            'offset'      => 0,
            'limit'       => self::PAGE_SIZE,
            'mode'        => $mode,
            'src_db'      => $srcDb,
            'tgt_db'      => $tgtDb,
            'inserted'    => 0,
            'details'     => [],           // tableName => rowsInserted
        ]);

        $request = $request
            ->withAttribute('__driver_target', $tgtDriver)
            ->withAttribute('__loop_context',  $ctx);

        return $handler->handle($request);
    }

    protected function responseFactory(): ResponseFactoryInterface { return $this->responseFactory; }
    protected function streamFactory(): StreamFactoryInterface     { return $this->streamFactory; }
}

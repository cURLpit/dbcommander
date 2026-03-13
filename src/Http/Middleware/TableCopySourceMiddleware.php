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
 * Reads one page of rows from the source table.
 *
 * Reads from request attributes:
 *   __driver       – source DriverInterface
 *   __copy_source  – ['db' => ..., 'table' => ...]
 *
 * Reads from __loop_context:
 *   offset  – current offset (default 0)
 *   limit   – page size (default 1000)
 *
 * Writes to __loop_context:
 *   rows     – fetched rows array
 *   columns  – column names
 *   has_more – bool: whether to continue looping
 *   offset   – advanced by limit
 */
final class TableCopySourceMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var LoopContext $ctx */
        $ctx    = $request->getAttribute('__loop_context');
        $driver = $request->getAttribute('__driver');
        $source = $request->getAttribute('__copy_source');

        $offset = $ctx->get('offset', 0);
        $limit  = $ctx->get('limit',  1000);

        $db_q  = $this->quoteId($source['db']);
        $tbl_q = $this->quoteId($source['table']);

        $rows = $driver->fetchAll(
            "SELECT * FROM {$db_q}.{$tbl_q} LIMIT ? OFFSET ?",
            [$limit, $offset]
        );

        error_log("CopySource: {$source['db']}.{$source['table']} offset={$offset} limit={$limit} rows=" . count($rows));

        $ctx->set('rows',     $rows);
        $ctx->set('columns',  $rows ? array_keys($rows[0]) : []);
        $ctx->set('has_more', count($rows) === $limit);
        $ctx->set('offset',   $offset + $limit);

        return $handler->handle($request);
    }

    private function quoteId(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}

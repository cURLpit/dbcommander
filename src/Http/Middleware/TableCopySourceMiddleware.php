<?php

declare(strict_types=1);

namespace DbCommander\Http\Middleware;

use Curlpit\Core\LoopContext;
use DbCommander\Driver\DriverInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Reads one page of rows from the source table.
 *
 * Reads from __loop_context:
 *   offset   – current offset (default 0)
 *   limit    – page size (default 1000)
 *
 * Writes to __loop_context:
 *   rows      – fetched rows array
 *   columns   – column names
 *   has_more  – bool: whether to continue looping
 *   offset    – advanced by limit
 */
final class TableCopySourceMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly DriverInterface          $driver,
        private readonly string                   $db,
        private readonly string                   $table,
        private readonly int                      $pageSize = 1000,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var LoopContext $ctx */
        $ctx    = $request->getAttribute('__loop_context');
        $offset = $ctx->get('offset', 0);
        $limit  = $ctx->get('limit',  $this->pageSize);

        $db_q  = $this->quoteId($this->db);
        $tbl_q = $this->quoteId($this->table);

        $rows = $this->driver->fetchAll(
            "SELECT * FROM {$db_q}.{$tbl_q} LIMIT ? OFFSET ?",
            [$limit, $offset]
        );

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

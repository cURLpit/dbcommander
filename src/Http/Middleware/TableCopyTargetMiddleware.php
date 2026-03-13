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
 * Writes one page of rows into the target table.
 *
 * Reads from request attributes:
 *   __driver_target – target DriverInterface (falls back to __driver)
 *   __copy_target   – ['db' => ..., 'table' => ...]
 *
 * Reads from __loop_context:
 *   rows     – rows to insert
 *   columns  – column names
 *   inserted – running total (accumulates)
 *
 * Writes to __loop_context:
 *   inserted – updated total
 */
final class TableCopyTargetMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var LoopContext $ctx */
        $ctx    = $request->getAttribute('__loop_context');
        $driver = $request->getAttribute('__driver_target')
                  ?? $request->getAttribute('__driver');
        $target = $request->getAttribute('__copy_target');

        $rows    = $ctx->get('rows',    []);
        $columns = $ctx->get('columns', []);

        if ($rows && $columns) {
            $db_q    = $this->quoteId($target['db']);
            $tbl_q   = $this->quoteId($target['table']);
            $colList = implode(', ', array_map([$this, 'quoteId'], $columns));

            foreach ($rows as $row) {
                $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                $driver->execute(
                    "INSERT INTO {$db_q}.{$tbl_q} ({$colList}) VALUES ({$placeholders})",
                    array_values($row)
                );
            }
        }

        $ctx->set('inserted', $ctx->get('inserted', 0) + count($rows));

        return $handler->handle($request);
    }

    private function quoteId(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}

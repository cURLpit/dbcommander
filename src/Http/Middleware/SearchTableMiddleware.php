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
 * Searches one table for the given term, then advances to the next target.
 *
 * One iteration = one table (contrast with copy, where one iteration = one page).
 * Because SearchPrepareMiddleware already resolved the searchable columns per
 * table from INFORMATION_SCHEMA, this middleware simply builds a single
 * "WHERE col1 LIKE ? OR col2 LIKE ?" query – no per-column round trips.
 *
 * Reads from LoopContext:
 *   targets         – { db, table, columns[] }[]
 *   table_index     – current position in targets
 *   term            – LIKE pattern (%term%)
 *   where_clause    – optional raw SQL appended as AND (...); empty string = no extra filter
 *   results         – accumulated result rows (appended to)
 *   results_limit   – global cap; stops loop early when reached
 *   per_table_limit – per-table row cap
 *   tables_searched – running counter
 *
 * Writes to LoopContext:
 *   results         – appended with { db, table, column, value, row }
 *   tables_searched – incremented
 *   table_index     – advanced by 1
 *   has_more        – false when last table processed OR results_limit reached
 *
 * Result row shape:
 *   { db, table, column, value, row: { col => val, … } }
 *
 *   "column" is the first matching column name in the row.
 *   "value"  is its value (cast to string for JSON).
 *   "row"    is the full row so the UI can show context.
 */
final class SearchTableMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var LoopContext $ctx */
        $ctx    = $request->getAttribute('__loop_context');
        $driver = $request->getAttribute('__driver');

        $targets      = $ctx->get('targets',         []);
        $idx          = $ctx->get('table_index',      0);
        $term         = $ctx->get('term');                 // null = no text search, where-only mode
        $whereClause  = trim((string) $ctx->get('where_clause', ''));
        $results      = $ctx->get('results',          []);
        $resultsLimit = $ctx->get('results_limit',    500);
        $perTable     = $ctx->get('per_table_limit',  50);
        $searched     = $ctx->get('tables_searched',  0);

        $target  = $targets[$idx];
        $db      = $target['db'];
        $table   = $target['table'];
        $columns = $target['columns'];

        // ── Build query ───────────────────────────────────────────────────────
        $dbQ  = $this->q($db);
        $tblQ = $this->q($table);

        if ($term !== null) {
            // Text search: WHERE (col1 LIKE ? OR col2 LIKE ?) [AND (user_clause)]
            $whereParts = array_map(
                fn(string $col): string => $this->q($col) . ' LIKE ?',
                $columns,
            );
            $where  = '(' . implode(' OR ', $whereParts) . ')';
            if ($whereClause !== '') {
                $where .= ' AND (' . $whereClause . ')';
            }
            $params = array_fill(0, count($columns), $term);
        } else {
            // WHERE-only mode: no LIKE columns needed, just the user clause
            $where  = '(' . $whereClause . ')';
            $params = [];
        }
        $params[] = $perTable;          // for LIMIT ?

        try {
            $rows = $driver->fetchAll(
                "SELECT * FROM {$dbQ}.{$tblQ} WHERE {$where} LIMIT ?",
                $params
            );
        } catch (\Throwable) {
            // Table may have been dropped mid-search or access denied – skip it
            $rows = [];
        }

        // ── Annotate each row with which column first matched ─────────────────
        $termRaw = $term !== null ? trim($term, '%') : null;
        foreach ($rows as $row) {
            $matchCol   = null;
            $matchValue = null;
            if ($termRaw !== null) {
                foreach ($columns as $col) {
                    $cell = (string) ($row[$col] ?? '');
                    if (mb_stripos($cell, $termRaw) !== false) {
                        $matchCol   = $col;
                        $matchValue = $cell;
                        break;
                    }
                }
            }
            $results[] = [
                'db'     => $db,
                'table'  => $table,
                'column' => $matchCol,
                'value'  => $matchValue,
                'row'    => $row,
            ];
        }

        $nextIdx    = $idx + 1;
        $hitLimit   = count($results) >= $resultsLimit;
        $noMoreTbls = $nextIdx >= count($targets);

        $ctx->set('results',         $results);
        $ctx->set('tables_searched', $searched + 1);
        $ctx->set('table_index',     $nextIdx);
        $ctx->set('has_more',        !$hitLimit && !$noMoreTbls);

        return $handler->handle($request);
    }

    private function q(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}

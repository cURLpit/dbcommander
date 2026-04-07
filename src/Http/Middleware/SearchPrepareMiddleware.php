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
 * Prepares a global search operation.
 *
 * Reads POST body:
 *   {
 *     "term":  "search string",
 *     "scope": { "db": "mydb" }   // optional – omit for all databases
 *   }
 *
 * The key native MySQL trick used here: a single INFORMATION_SCHEMA.COLUMNS
 * query retrieves every searchable (text-like) column for every table in scope,
 * grouped by table. This means the per-iteration SearchTableMiddleware can build
 * an optimal "WHERE col1 LIKE ? OR col2 LIKE ?" query in one shot, without
 * any per-column round trips.
 *
 * Text-like column types searched:
 *   char, varchar, tinytext, text, mediumtext, longtext, enum, set
 *
 * Sets on LoopContext:
 *   term          – the search term (wrapped in % for LIKE)
 *   term_raw      – original term as entered
 *   targets       – ordered array of { db, table, columns[] }
 *   table_index   – 0
 *   has_more      – true if targets is non-empty
 *   results       – []
 *   results_limit – MAX_RESULTS (stops loop early when reached)
 *   tables_searched – 0
 */
final class SearchPrepareMiddleware implements MiddlewareInterface
{
    use JsonResponseTrait;

    /** Stop after this many result rows across all tables. */
    private const MAX_RESULTS = 500;

    /** Per-table result cap – avoids one huge table drowning everything. */
    private const MAX_PER_TABLE = 50;

    /** MySQL column DATA_TYPEs considered searchable. */
    private const TEXT_TYPES = [
        'char', 'varchar',
        'tinytext', 'text', 'mediumtext', 'longtext',
        'enum', 'set',
    ];

    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface   $streamFactory,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $body  = json_decode((string) $request->getBody(), true) ?? [];
        $term  = trim($body['term']  ?? '');
        $where = trim($body['where'] ?? '');
        $scope = $body['scope'] ?? [];

        if ($term === '' && $where === '') {
            return $this->json(['error' => 'term or where is required'], 400);
        }

        /** @var DriverInterface $driver */
        $driver = $request->getAttribute('__driver');
        if (!$driver instanceof DriverInterface) {
            return $this->json(['error' => 'No connection available'], 400);
        }

        // ── Build INFORMATION_SCHEMA query ───────────────────────────────────
        // In text-search mode: only tables that have at least one searchable text column.
        // In where-only mode ($term === ''): all BASE TABLEs in scope, since the user
        //   clause can reference any column type.

        $targets = [];

        if ($term !== '') {
            // Text search: resolve (db, table, searchable-columns[]) from INFORMATION_SCHEMA
            $typeList = implode(',', array_map(
                static fn(string $t): string => "'" . $t . "'",
                self::TEXT_TYPES,
            ));

            if (!empty($scope['db'])) {
                $sql    = "
                    SELECT c.TABLE_SCHEMA  AS db,
                           c.TABLE_NAME    AS tbl,
                           c.COLUMN_NAME   AS col
                    FROM   information_schema.COLUMNS c
                    JOIN   information_schema.TABLES  t
                           ON  t.TABLE_SCHEMA = c.TABLE_SCHEMA
                           AND t.TABLE_NAME   = c.TABLE_NAME
                    WHERE  c.TABLE_SCHEMA = ?
                      AND  t.TABLE_TYPE   = 'BASE TABLE'
                      AND  c.DATA_TYPE   IN ({$typeList})
                    ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION
                ";
                $params = [$scope['db']];
            } else {
                $sql    = "
                    SELECT c.TABLE_SCHEMA  AS db,
                           c.TABLE_NAME    AS tbl,
                           c.COLUMN_NAME   AS col
                    FROM   information_schema.COLUMNS c
                    JOIN   information_schema.TABLES  t
                           ON  t.TABLE_SCHEMA = c.TABLE_SCHEMA
                           AND t.TABLE_NAME   = c.TABLE_NAME
                    WHERE  c.TABLE_SCHEMA NOT IN
                               ('information_schema','performance_schema','mysql','sys')
                      AND  t.TABLE_TYPE   = 'BASE TABLE'
                      AND  c.DATA_TYPE   IN ({$typeList})
                    ORDER BY c.TABLE_SCHEMA, c.TABLE_NAME, c.ORDINAL_POSITION
                ";
                $params = [];
            }

            $rows = $driver->fetchAll($sql, $params);

            foreach ($rows as $row) {
                $key = $row['db'] . "\x00" . $row['tbl'];
                if (!isset($targets[$key])) {
                    $targets[$key] = ['db' => $row['db'], 'table' => $row['tbl'], 'columns' => []];
                }
                $targets[$key]['columns'][] = $row['col'];
            }
            $targets = array_values($targets);

        } else {
            // WHERE-only mode: include every BASE TABLE in scope (columns[] left empty)
            if (!empty($scope['db'])) {
                $sql    = "
                    SELECT TABLE_SCHEMA AS db, TABLE_NAME AS tbl
                    FROM   information_schema.TABLES
                    WHERE  TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'
                    ORDER BY TABLE_NAME
                ";
                $params = [$scope['db']];
            } else {
                $sql    = "
                    SELECT TABLE_SCHEMA AS db, TABLE_NAME AS tbl
                    FROM   information_schema.TABLES
                    WHERE  TABLE_SCHEMA NOT IN
                               ('information_schema','performance_schema','mysql','sys')
                      AND  TABLE_TYPE = 'BASE TABLE'
                    ORDER BY TABLE_SCHEMA, TABLE_NAME
                ";
                $params = [];
            }

            $rows = $driver->fetchAll($sql, $params);
            foreach ($rows as $row) {
                $targets[] = ['db' => $row['db'], 'table' => $row['tbl'], 'columns' => []];
            }
        }

        // ── Initialise LoopContext ────────────────────────────────────────────
        $ctx = new LoopContext([
            'term'            => $term !== '' ? '%' . $term . '%' : null,
            'term_raw'        => $term,
            'where_clause'    => $where,
            'targets'         => $targets,
            'table_index'     => 0,
            'has_more'        => !empty($targets),
            'results'         => [],
            'results_limit'   => self::MAX_RESULTS,
            'per_table_limit' => self::MAX_PER_TABLE,
            'tables_searched' => 0,
        ]);

        $request = $request->withAttribute('__loop_context', $ctx);

        return $handler->handle($request);
    }

    protected function responseFactory(): ResponseFactoryInterface { return $this->responseFactory; }
    protected function streamFactory(): StreamFactoryInterface     { return $this->streamFactory; }
}

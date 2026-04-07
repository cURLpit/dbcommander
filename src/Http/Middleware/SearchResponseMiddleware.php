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
 * Returns the search results JSON after the loop completes.
 *
 * Reads from LoopContext:
 *   term_raw        – original search term
 *   results         – accumulated result rows
 *   results_limit   – cap used (to determine truncation)
 *   tables_searched – total tables actually searched
 *   targets         – full target list (to report total table count)
 *
 * Response shape:
 *   {
 *     "ok":              true,
 *     "term":            "foo",
 *     "results":         [ { db, table, column, value, row } ],
 *     "result_count":    42,
 *     "tables_searched": 17,
 *     "tables_total":    20,
 *     "truncated":       false
 *   }
 */
final class SearchResponseMiddleware implements MiddlewareInterface
{
    use JsonResponseTrait;

    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface   $streamFactory,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var LoopContext $ctx */
        $ctx = $request->getAttribute('__loop_context');

        $results      = $ctx->get('results',          []);
        $resultsLimit = $ctx->get('results_limit',    500);
        $searched     = $ctx->get('tables_searched',  0);
        $targets      = $ctx->get('targets',          []);

        return $this->json([
            'ok'              => true,
            'term'            => $ctx->get('term_raw', ''),
            'results'         => $results,
            'result_count'    => count($results),
            'tables_searched' => $searched,
            'tables_total'    => count($targets),
            'truncated'       => count($results) >= $resultsLimit,
        ]);
    }

    protected function responseFactory(): ResponseFactoryInterface { return $this->responseFactory; }
    protected function streamFactory(): StreamFactoryInterface     { return $this->streamFactory; }
}

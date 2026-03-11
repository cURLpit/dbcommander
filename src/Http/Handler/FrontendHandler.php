<?php

declare(strict_types=1);

namespace DbCommander\Http\Handler;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves the single-page frontend application.
 *
 * The HTML template lives in resources/app.html, outside the public
 * webroot, so it cannot be accessed directly – only through this handler.
 */
final class FrontendHandler implements RequestHandlerInterface
{
    private string $templatePath;

    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface   $streamFactory,
        string $templatePath,
    ) {
        $this->templatePath = $templatePath;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!is_file($this->templatePath) || !is_readable($this->templatePath)) {
            throw new \RuntimeException("Frontend template not found: {$this->templatePath}");
        }

        $html   = file_get_contents($this->templatePath);
        $stream = $this->streamFactory->createStream($html);

        return $this->responseFactory
            ->createResponse(200)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($stream);
    }
}

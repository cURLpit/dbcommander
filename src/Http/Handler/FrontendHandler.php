<?php

declare(strict_types=1);

namespace DbCommander\Http\Handler;

use DbCommander\Asset\AssetBuilder;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves the single-page frontend application.
 *
 * Triggers the AssetBuilder to generate versioned JS/CSS assets,
 * then injects the asset URLs and dynamic data into the HTML template.
 */
final class FrontendHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface   $streamFactory,
        private readonly string                   $templatePath,
        private readonly AssetBuilder             $assetBuilder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!is_file($this->templatePath) || !is_readable($this->templatePath)) {
            throw new \RuntimeException("Frontend template not found: {$this->templatePath}");
        }

        $assets = $this->assetBuilder->build();

        $html = file_get_contents($this->templatePath);
        $ip   = $request->getAttribute('client-ip', '');

        $html = str_replace(
            ['{css_url}', '{js_url}', '{client_ip}'],
            [$assets['css'], $assets['js'], json_encode((string) $ip)],
            $html
        );

        $stream = $this->streamFactory->createStream($html);

        return $this->responseFactory
            ->createResponse(200)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($stream);
    }
}

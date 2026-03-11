<?php

declare(strict_types=1);

namespace DbCommander\Http\Handler;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

trait JsonResponseTrait
{
    abstract protected function responseFactory(): ResponseFactoryInterface;
    abstract protected function streamFactory(): StreamFactoryInterface;

    protected function json(mixed $data, int $status = 200): ResponseInterface
    {
        $json   = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $stream = $this->streamFactory()->createStream($json);

        return $this->responseFactory()
            ->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($stream);
    }
}

<?php

declare(strict_types=1);

namespace Orion\Psr15;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * An RFC 9457 problem: `application/problem+json` with a type under {@see TYPE_PREFIX}, a title, the status and a
 * detail that says what to change, never what the server is made of.
 */
final readonly class Problem
{
    public const string TYPE_PREFIX = 'https://orion-search.dev/problems/';
    public const string CONTENT_TYPE = 'application/problem+json';

    public function __construct(private ResponseFactoryInterface $responses, private StreamFactoryInterface $streams)
    {
    }

    /**
     * Every answer is the caller's: the same URL answers each tenant differently, so no shared cache may keep it.
     */
    public static function uncached(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('Cache-Control', 'private, no-store')->withHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * @param array<string, mixed> $extensions
     */
    public function respond(int $status, string $type, string $title, string $detail, array $extensions = []): ResponseInterface
    {
        $body = ['type' => self::TYPE_PREFIX . $type, 'title' => $title, 'status' => $status, 'detail' => $detail, ...$extensions];

        return self::uncached($this->responses->createResponse($status))
            ->withHeader('Content-Type', self::CONTENT_TYPE)
            ->withBody($this->streams->createStream(json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
    }
}

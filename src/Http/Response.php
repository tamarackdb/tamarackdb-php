<?php

declare(strict_types=1);

namespace TamarackDB\Http;

final readonly class Response
{
    /**
     * @param array<string, string> $headers keyed by lowercase header name
     */
    public function __construct(
        public int $statusCode,
        public array $headers = [],
        public string $body = '',
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}

<?php

declare(strict_types=1);

namespace TamarackDB\Http;

/**
 * A 200 response whose body is read line by line as it arrives.
 */
final readonly class StreamedResponse
{
    /**
     * @param array<string, string> $headers keyed by lowercase header name
     * @param \Generator<int, string> $lines the body lines, without their
     *                                       line endings; empty lines are
     *                                       skipped
     */
    public function __construct(
        public array $headers,
        public \Generator $lines,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}

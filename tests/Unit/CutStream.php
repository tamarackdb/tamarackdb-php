<?php

declare(strict_types=1);

namespace TamarackDB\Tests\Unit;

/**
 * A streamed response that yields some lines, then loses its connection.
 */
final readonly class CutStream
{
    /**
     * @param list<string> $lines
     * @param array<string, string> $headers keyed by lowercase header name
     */
    public function __construct(
        public array $lines,
        public array $headers = ['x-tamarackdb-store' => Responses::STORE],
    ) {}
}

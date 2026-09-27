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
     */
    public function __construct(public array $lines) {}
}

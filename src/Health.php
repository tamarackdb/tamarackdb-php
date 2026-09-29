<?php

declare(strict_types=1);

namespace TamarackDB;

/**
 * The answer of GET /health.
 */
final readonly class Health
{
    public function __construct(
        public string $status,
        public string $version,
    ) {}
}

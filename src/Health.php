<?php

declare(strict_types=1);

namespace TamarackDB;

/**
 * The answer of GET /health. $paused is true once a pause is in place.
 */
final readonly class Health
{
    public function __construct(
        public string $status,
        public bool $paused,
        public string $version,
    ) {}
}

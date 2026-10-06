<?php

declare(strict_types=1);

namespace TamarackDB;

/**
 * Where the log stands once a pause is in place: the last Sequence
 * Position, and the store ID it comes from.
 */
final readonly class PausePoint
{
    public function __construct(
        public int $lastSequence,
        public string $storeId,
    ) {}
}

<?php

declare(strict_types=1);

namespace TamarackDB;

/**
 * Where the log stands once a pause is in place: the last Sequence
 * Position.
 */
final readonly class PausePoint
{
    public function __construct(
        public int $lastSequence,
    ) {}
}

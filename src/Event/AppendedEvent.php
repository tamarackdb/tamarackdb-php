<?php

declare(strict_types=1);

namespace TamarackDB\Event;

/**
 * The Sequence Position and time TamarackDB gave an event at commit. The
 * store ID is the same for every event of a commit: it's on the
 * CommitResult.
 */
final readonly class AppendedEvent
{
    public function __construct(
        public int $sequence,
        public \DateTimeImmutable $time,
    ) {}
}

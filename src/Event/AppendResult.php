<?php

declare(strict_types=1);

namespace TamarackDB\Event;

/**
 * What the server returns for a write of events in a transaction.
 *
 * $time is the time every event of the write carries, in UTC, and keeps
 * once committed. Give it to each event before the code that reacts to it
 * runs.
 */
final readonly class AppendResult
{
    public function __construct(
        public \DateTimeImmutable $time,
    ) {}
}

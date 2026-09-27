<?php

declare(strict_types=1);

namespace TamarackDB\Event;

/**
 * The Sequence Position and time TamarackDB gave an appended event. Both
 * are final as soon as the append returns: the transaction commits them
 * as they are, or rolls them back entirely.
 */
final readonly class AppendedEvent
{
    public function __construct(
        public int $sequence,
        public \DateTimeImmutable $time,
    ) {}
}

<?php

declare(strict_types=1);

namespace TamarackDB;

use TamarackDB\Event\AppendedEvent;

/**
 * What commit() returns: the Sequence Position and time of each event the
 * transaction wrote, in the order they were appended.
 */
final readonly class CommitResult
{
    /**
     * @param list<AppendedEvent> $events
     */
    public function __construct(
        public array $events,
    ) {}

    /**
     * Returns the Sequence Position of the last event written, or null when
     * the transaction wrote no event.
     */
    public function lastSequence(): ?int
    {
        return $this->events === [] ? null : $this->events[\count($this->events) - 1]->sequence;
    }
}

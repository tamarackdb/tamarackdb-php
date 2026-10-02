<?php

declare(strict_types=1);

namespace TamarackDB;

use TamarackDB\Event\AppendedEvent;
use TamarackDB\Exception\InvalidArgumentException;

/**
 * What commit() returns: the store ID the transaction was written to, and
 * the Sequence Position and time of each event it wrote, in the order they
 * were appended.
 *
 * An empty transaction doesn't contact the server: it has no store ID and
 * no events. A transaction that only writes projections has a store ID,
 * but no events.
 */
final readonly class CommitResult
{
    /**
     * @param list<AppendedEvent> $events
     */
    public function __construct(
        public ?string $store,
        public array $events,
    ) {
        if ($store === null && $events !== []) {
            throw new InvalidArgumentException('written events need a store ID');
        }
    }

    /**
     * Returns the position after the last event written, or null when the
     * transaction wrote no event.
     */
    public function position(): ?StorePosition
    {
        if ($this->store === null || $this->events === []) {
            return null;
        }

        return new StorePosition($this->store, $this->events[\count($this->events) - 1]->sequence);
    }
}

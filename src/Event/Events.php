<?php

declare(strict_types=1);

namespace TamarackDB\Event;

use TamarackDB\Exception\StoreChangedException;

/**
 * The events of one read, oldest first. They can be iterated only once.
 *
 * Outside a transaction, every event is an Event, and pages are fetched as
 * the events are iterated. Inside one, the committed events (Event) come
 * first, then the events written earlier in the transaction (PendingEvent).
 *
 * @implements \IteratorAggregate<int, Event|PendingEvent>
 */
final class Events implements \IteratorAggregate
{
    /** @var \Generator<int, Event|PendingEvent> */
    private readonly \Generator $events;

    /**
     * @internal
     *
     * @param string|null $storeId the store ID every page must come from, or
     *                             null to take the one of the first page
     * @param \Closure(self): \Generator<int, Event|PendingEvent> $read reads
     *                                                                  the events, and passes the store ID of each page to receiveStoreId()
     */
    public function __construct(
        private ?string $storeId,
        \Closure $read,
    ) {
        $this->events = $read($this);
    }

    /**
     * @return \Generator<int, Event|PendingEvent>
     */
    public function getIterator(): \Generator
    {
        return $this->events;
    }

    /**
     * The store ID the events come from, or null before the first page
     * arrived. Keep it with the last Sequence Position you read, and pass
     * both to the next read. Always null inside a transaction: the server
     * keeps the store ID with the transaction.
     */
    public function storeId(): ?string
    {
        return $this->storeId;
    }

    /**
     * @internal
     *
     * @throws StoreChangedException when $storeId isn't the one of the read
     */
    public function receiveStoreId(string $storeId): void
    {
        if ($this->storeId !== null && $storeId !== $this->storeId) {
            throw new StoreChangedException(\sprintf('the store ID is %s, not %s: the store was reset', $storeId, $this->storeId));
        }
        $this->storeId = $storeId;
    }
}

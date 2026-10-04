<?php

declare(strict_types=1);

namespace TamarackDB\Event;

use TamarackDB\Exception\StoreChangedException;

/**
 * The committed events of one read, oldest first. Pages are fetched as the
 * events are iterated, and they can be iterated only once.
 *
 * @implements \IteratorAggregate<int, Event>
 */
final class Events implements \IteratorAggregate
{
    /** @var \Generator<int, Event> */
    private readonly \Generator $events;

    /**
     * @internal
     *
     * @param string|null $storeId the store ID every page must come from, or
     *                             null to take the one of the first page
     * @param \Closure(self): \Generator<int, Event> $read reads the pages,
     *                                                     and passes the store ID of each to receiveStoreId()
     */
    public function __construct(
        private ?string $storeId,
        \Closure $read,
    ) {
        $this->events = $read($this);
    }

    /**
     * @return \Generator<int, Event>
     */
    public function getIterator(): \Generator
    {
        return $this->events;
    }

    /**
     * The store ID the events come from, or null before the first page
     * arrived. Keep it with the last Sequence Position you read, and pass
     * both to the next read.
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

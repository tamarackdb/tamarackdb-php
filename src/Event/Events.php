<?php

declare(strict_types=1);

namespace TamarackDB\Event;

/**
 * The committed events of one read, oldest first. Pages are fetched as the
 * events are iterated, and they can be iterated only once.
 *
 * @implements \IteratorAggregate<int, Event>
 */
final class Events implements \IteratorAggregate
{
    /**
     * @internal
     *
     * @param \Generator<int, Event> $events reads the pages as it's iterated
     */
    public function __construct(
        private readonly \Generator $events,
    ) {}

    /**
     * @return \Generator<int, Event>
     */
    public function getIterator(): \Generator
    {
        return $this->events;
    }
}

<?php

declare(strict_types=1);

namespace TamarackDB\Query;

/**
 * Which events to read, or to check in an Append Condition. The filters
 * given together form one item and must all match; or() adds another
 * item, and an event matching any item matches the query.
 *
 *     new Query(
 *         EventType::in('user-created', 'user-updated'),
 *         Identifier::is('userId', '123'),
 *     )->or(
 *         EventType::in('some-other-event'),
 *     )
 *
 * To match every event, read with AllEvents instead. To match none, read
 * with NoEvents.
 */
final readonly class Query
{
    /** @var non-empty-list<QueryItem> */
    public array $items;

    public function __construct(EventType|Identifier|Metadata $filter, EventType|Identifier|Metadata ...$filters)
    {
        $this->items = [new QueryItem($filter, ...$filters)];
    }

    /**
     * Returns a copy of this query with one more item.
     */
    public function or(EventType|Identifier|Metadata $filter, EventType|Identifier|Metadata ...$filters): self
    {
        return clone($this, ['items' => [...$this->items, new QueryItem($filter, ...$filters)]]);
    }

    /**
     * Returns a copy of this query with $map applied to each item, for
     * example to add a filter to every item:
     *
     *     $query->map(fn (QueryItem $item) => $item->with(Metadata::is('tenantId', 'acme')))
     *
     * @param callable(QueryItem): QueryItem $map
     */
    public function map(callable $map): self
    {
        return clone($this, ['items' => array_map($map, $this->items)]);
    }

    /**
     * @return non-empty-list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(static fn(QueryItem $item): array => $item->toArray(), $this->items);
    }
}

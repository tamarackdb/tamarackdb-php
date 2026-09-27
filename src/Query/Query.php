<?php

declare(strict_types=1);

namespace TamarackDB\Query;

use TamarackDB\Exception\InvalidArgumentException;

/**
 * Which events to read, or to check in an Append Condition: every event,
 * or the events matching any of its items.
 *
 *     Query::all()
 *     Query::of(
 *         new QueryItem(types: ['user-created', 'user-updated'], identifiers: ['userId' => '123']),
 *         new QueryItem(types: ['some-other-event']),
 *     )
 */
final readonly class Query
{
    /**
     * @param list<QueryItem>|null $items null matches every event
     */
    private function __construct(public ?array $items) {}

    public static function all(): self
    {
        return new self(null);
    }

    public static function of(QueryItem ...$items): self
    {
        if ($items === []) {
            throw new InvalidArgumentException('a query needs at least one item; use Query::all() to match every event');
        }

        return new self(array_values($items));
    }

    public function isAll(): bool
    {
        return $this->items === null;
    }

    /**
     * The query's JSON value: "*", or the list of its items.
     *
     * @return string|list<array<string, mixed>>
     */
    public function toJsonValue(): string|array
    {
        if ($this->items === null) {
            return '*';
        }

        return array_map(static fn(QueryItem $item): array => $item->toArray(), $this->items);
    }
}

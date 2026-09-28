<?php

declare(strict_types=1);

namespace TamarackDB\Query;

use TamarackDB\Internal\Tags;

/**
 * One item of a query: the filters given to one Query constructor or
 * or() call. An event matches it when its type is any of $types (every
 * type when empty), and it carries every one of $identifiers and
 * $metadata.
 *
 * Identifiers and metadata are in compact form, as on Event: a name with
 * one value maps to a string, a name with several values to a list, and
 * the event must carry each value.
 */
final readonly class QueryItem
{
    /** @var list<string> */
    public array $types;

    /** @var array<string, string|list<string>> */
    public array $identifiers;

    /** @var array<string, string|list<string>> */
    public array $metadata;

    public function __construct(EventType|Identifier|Metadata $filter, EventType|Identifier|Metadata ...$filters)
    {
        [$this->types, $this->identifiers, $this->metadata] = self::merge([], [], [], [$filter, ...$filters]);
    }

    /**
     * Returns a copy of this item with more filters.
     */
    public function with(EventType|Identifier|Metadata $filter, EventType|Identifier|Metadata ...$filters): self
    {
        [$types, $identifiers, $metadata] = self::merge($this->types, $this->identifiers, $this->metadata, [$filter, ...$filters]);

        return clone($this, ['types' => $types, 'identifiers' => $identifiers, 'metadata' => $metadata]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $item = [];
        if ($this->types !== []) {
            $item['types'] = $this->types;
        }
        if ($this->identifiers !== []) {
            $item['identifiers'] = Tags::toPairs($this->identifiers);
        }
        if ($this->metadata !== []) {
            $item['metadata'] = Tags::toPairs($this->metadata);
        }

        return $item;
    }

    /**
     * @param list<string> $types
     * @param array<string, string|list<string>> $identifiers
     * @param array<string, string|list<string>> $metadata
     * @param array<EventType|Identifier|Metadata> $filters
     *
     * @return array{list<string>, array<string, string|list<string>>, array<string, string|list<string>>}
     */
    private static function merge(array $types, array $identifiers, array $metadata, array $filters): array
    {
        foreach ($filters as $filter) {
            match (true) {
                $filter instanceof EventType => $types = array_values(array_unique([...$types, ...$filter->types])),
                $filter instanceof Identifier => $identifiers = Tags::add($identifiers, $filter->name, $filter->value),
                $filter instanceof Metadata => $metadata = Tags::add($metadata, $filter->name, $filter->value),
            };
        }

        return [$types, $identifiers, $metadata];
    }
}

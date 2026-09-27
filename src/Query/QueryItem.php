<?php

declare(strict_types=1);

namespace TamarackDB\Query;

use TamarackDB\Exception\InvalidArgumentException;
use TamarackDB\Internal\Tags;

/**
 * One item of a query. An event matches it when its type is any of
 * $types (every type when empty), and it carries every one of
 * $identifiers and $metadata.
 *
 * Identifiers and metadata are given as name => value, or
 * name => list of values: the event must carry each value.
 *
 *     new QueryItem(types: ['user-created'], identifiers: ['userId' => '123'])
 */
final readonly class QueryItem
{
    /** @var list<string> */
    public array $types;

    /** @var array<string, list<string>> */
    public array $identifiers;

    /** @var array<string, list<string>> */
    public array $metadata;

    /**
     * @param list<string> $types
     * @param array<string, string|list<string>> $identifiers
     * @param array<string, string|list<string>> $metadata
     */
    public function __construct(array $types = [], array $identifiers = [], array $metadata = [])
    {
        foreach ($types as $type) {
            if (!\is_string($type) || $type === '') {
                throw new InvalidArgumentException('a query item type must be a non-empty string');
            }
        }
        $this->types = array_values($types);
        $this->identifiers = Tags::normalize($identifiers, 'identifier');
        $this->metadata = Tags::normalize($metadata, 'metadata');

        if ($this->types === [] && $this->identifiers === [] && $this->metadata === []) {
            throw new InvalidArgumentException('a query item needs at least one type, identifier, or metadata value; use Query::all() to match every event');
        }
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
}

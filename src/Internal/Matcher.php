<?php

declare(strict_types=1);

namespace TamarackDB\Internal;

use TamarackDB\Query\Query;
use TamarackDB\Query\QueryItem;

/**
 * Tells whether an event matches a query, with the same rules as the
 * server's SQL. A null query matches every event. Otherwise the event must
 * match one item: its type is in the item's types (any type when empty),
 * and it carries every identifier and metadata value the item asks for.
 * Identifiers and metadata are separate: one never satisfies the other.
 * Strings are compared exactly, as SQLite compares them in BINARY.
 *
 * @internal
 */
final class Matcher
{
    /**
     * @param array<string, string|list<string>> $identifiers
     * @param array<string, string|list<string>> $metadata
     */
    public static function matches(?Query $query, string $type, array $identifiers, array $metadata): bool
    {
        if ($query === null) {
            return true;
        }
        foreach ($query->items as $item) {
            if (self::matchesItem($item, $type, $identifiers, $metadata)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string|list<string>> $identifiers
     * @param array<string, string|list<string>> $metadata
     */
    private static function matchesItem(QueryItem $item, string $type, array $identifiers, array $metadata): bool
    {
        if ($item->types !== [] && !\in_array($type, $item->types, true)) {
            return false;
        }

        return self::carries($identifiers, $item->identifiers) && self::carries($metadata, $item->metadata);
    }

    /**
     * @param array<string, string|list<string>> $tags
     * @param array<string, string|list<string>> $wanted
     */
    private static function carries(array $tags, array $wanted): bool
    {
        foreach ($wanted as $name => $values) {
            if (!isset($tags[$name])) {
                return false;
            }
            $carried = \is_string($tags[$name]) ? [$tags[$name]] : $tags[$name];
            foreach (\is_string($values) ? [$values] : $values as $value) {
                if (!\in_array($value, $carried, true)) {
                    return false;
                }
            }
        }

        return true;
    }
}

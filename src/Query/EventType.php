<?php

declare(strict_types=1);

namespace TamarackDB\Query;

use TamarackDB\Exception\InvalidArgumentException;

/**
 * A query filter: the event's type is any of $types.
 *
 *     EventType::in('user-created', 'user-updated')
 */
final readonly class EventType
{
    /**
     * @param list<string> $types
     */
    private function __construct(public array $types) {}

    public static function in(string ...$types): self
    {
        if ($types === []) {
            throw new InvalidArgumentException('EventType::in() needs at least one type');
        }
        foreach ($types as $type) {
            if ($type === '') {
                throw new InvalidArgumentException('an event type must not be empty');
            }
        }

        return new self(array_values($types));
    }
}

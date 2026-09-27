<?php

declare(strict_types=1);

namespace TamarackDB\Event;

use TamarackDB\Exception\InvalidArgumentException;
use TamarackDB\Query\Query;

/**
 * Makes an append fail with a ConcurrencyException when an event matching
 * $failIfEventsMatch exists after $afterSequence. Both are optional.
 */
final readonly class AppendCondition
{
    public function __construct(
        public ?Query $failIfEventsMatch = null,
        public ?int $afterSequence = null,
    ) {
        if ($afterSequence !== null && $afterSequence < 0) {
            throw new InvalidArgumentException('afterSequence must not be negative');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $condition = [];
        if ($this->failIfEventsMatch !== null) {
            $condition['failIfEventsMatch'] = $this->failIfEventsMatch->toJsonValue();
        }
        if ($this->afterSequence !== null) {
            $condition['afterSequence'] = $this->afterSequence;
        }

        return $condition;
    }
}

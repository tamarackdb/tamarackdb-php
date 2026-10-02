<?php

declare(strict_types=1);

namespace TamarackDB\Event;

use TamarackDB\Internal\Matcher;
use TamarackDB\Internal\Tags;
use TamarackDB\Query\Query;

/**
 * An event appended in the current transaction and not committed yet.
 * A read in the transaction returns the pending events that match its
 * query after the events from the server.
 *
 * It has no Sequence Position and no time: the server sets both at commit.
 *
 * A name with one identifier or metadata value maps to a string, a name
 * with several values to a list.
 */
final readonly class PendingEvent
{
    /** @var array<string, string|list<string>> */
    public array $identifiers;

    /** @var array<string, string|list<string>> */
    public array $metadata;

    /**
     * @param array<string, string|list<string>> $identifiers
     * @param array<string, string|list<string>> $metadata
     */
    public function __construct(
        public string $type,
        array $identifiers,
        array $metadata,
        public string $payload,
    ) {
        $this->identifiers = Tags::normalize($identifiers, 'identifier');
        $this->metadata = Tags::normalize($metadata, 'metadata');
    }

    /**
     * Tells whether this event matches $query. A null query matches every
     * event.
     */
    public function matchesQuery(?Query $query): bool
    {
        return Matcher::matches($query, $this->type, $this->identifiers, $this->metadata);
    }
}

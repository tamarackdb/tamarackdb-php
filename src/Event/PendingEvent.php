<?php

declare(strict_types=1);

namespace TamarackDB\Event;

use TamarackDB\Internal\EventLine;
use TamarackDB\Internal\Tags;

/**
 * An event written earlier in the current transaction, as a read in that
 * transaction returns it. It gets its Sequence Position at commit.
 *
 * $time is the time of its write, in UTC: the time it will carry once
 * committed.
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
        public \DateTimeImmutable $time,
        public string $type,
        array $identifiers,
        array $metadata,
        public string $payload,
    ) {
        $this->identifiers = Tags::normalize($identifiers, 'identifier');
        $this->metadata = Tags::normalize($metadata, 'metadata');
    }

    /**
     * @param array<string, mixed> $data one pending event line of a QUERY /tx/{txId}/events response
     */
    public static function fromArray(array $data): self
    {
        $line = EventLine::parse($data);

        return new self($line->time, $line->type, $line->identifiers, $line->metadata, $line->payload);
    }
}

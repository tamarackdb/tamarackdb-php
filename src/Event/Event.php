<?php

declare(strict_types=1);

namespace TamarackDB\Event;

use TamarackDB\Exception\InvalidArgumentException;
use TamarackDB\Exception\ProtocolException;
use TamarackDB\Internal\Tags;
use TamarackDB\Internal\Time;

/**
 * A stored event, as read back from TamarackDB.
 *
 * $time is when TamarackDB appended the event, in UTC. Only $sequence
 * defines the order of events.
 *
 * A name with one identifier or metadata value maps to a string, a name
 * with several values to a list.
 */
final readonly class Event
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
        public int $sequence,
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
     * @param array<string, mixed> $data one event line of a QUERY /events response
     */
    public static function fromArray(array $data): self
    {
        if (!\is_int($data['sequence'] ?? null) || !\is_string($data['type'] ?? null) || !\is_string($data['payload'] ?? null)) {
            throw new ProtocolException('invalid event from the server');
        }
        $identifiers = $data['identifiers'] ?? [];
        $metadata = $data['metadata'] ?? [];
        if (!\is_array($identifiers) || !\is_array($metadata)) {
            throw new ProtocolException('invalid event from the server');
        }
        try {
            $identifiers = Tags::normalize($identifiers, 'identifier');
            $metadata = Tags::normalize($metadata, 'metadata');
        } catch (InvalidArgumentException $e) {
            throw new ProtocolException('invalid event from the server: ' . $e->getMessage(), 0, $e);
        }

        return new self($data['sequence'], Time::parse($data['time'] ?? null), $data['type'], $identifiers, $metadata, $data['payload']);
    }
}

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
 */
final readonly class Event
{
    /**
     * @param array<string, list<string>> $identifiers
     * @param array<string, list<string>> $metadata
     */
    public function __construct(
        public int $sequence,
        public \DateTimeImmutable $time,
        public string $type,
        public array $identifiers,
        public array $metadata,
        public string $payload,
    ) {}

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

    /**
     * The first value of an identifier, or null when the event carries none
     * under that name.
     */
    public function identifier(string $name): ?string
    {
        return $this->identifiers[$name][0] ?? null;
    }

    /**
     * The first value of a metadata entry, or null when the event carries
     * none under that name.
     */
    public function metadataValue(string $name): ?string
    {
        return $this->metadata[$name][0] ?? null;
    }
}

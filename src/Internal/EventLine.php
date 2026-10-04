<?php

declare(strict_types=1);

namespace TamarackDB\Internal;

use TamarackDB\Exception\InvalidArgumentException;
use TamarackDB\Exception\ProtocolException;

/**
 * The fields an event line from the server has, with or without a
 * Sequence Position.
 *
 * @internal
 */
final readonly class EventLine
{
    /**
     * @param array<string, string|list<string>> $identifiers
     * @param array<string, string|list<string>> $metadata
     */
    private function __construct(
        public \DateTimeImmutable $time,
        public string $type,
        public array $identifiers,
        public array $metadata,
        public string $payload,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function parse(array $data): self
    {
        if (!\is_string($data['type'] ?? null) || !\is_string($data['payload'] ?? null)) {
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

        return new self(Time::parse($data['time'] ?? null), $data['type'], $identifiers, $metadata, $data['payload']);
    }
}

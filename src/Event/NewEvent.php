<?php

declare(strict_types=1);

namespace TamarackDB\Event;

use TamarackDB\Exception\InvalidArgumentException;
use TamarackDB\Internal\Tags;

/**
 * An event to append.
 *
 * Identifiers and metadata are given as name => value, or
 * name => list of values for one tag per value. The payload is an opaque
 * string: TamarackDB never parses it, so its format is up to the
 * application.
 *
 *     new NewEvent('user-created', ['userId' => '123'], ['tenantId' => 'acme'], '{"name":"Ada"}')
 */
final readonly class NewEvent
{
    /** @var array<string, list<string>> */
    public array $identifiers;

    /** @var array<string, list<string>> */
    public array $metadata;

    /**
     * @param array<string, string|list<string>> $identifiers
     * @param array<string, string|list<string>> $metadata
     */
    public function __construct(
        public string $type,
        array $identifiers = [],
        array $metadata = [],
        public string $payload = '',
    ) {
        if ($type === '') {
            throw new InvalidArgumentException('an event type must not be empty');
        }
        $this->identifiers = Tags::normalize($identifiers, 'identifier');
        $this->metadata = Tags::normalize($metadata, 'metadata');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $event = ['type' => $this->type];
        if ($this->identifiers !== []) {
            $event['identifiers'] = Tags::toCompact($this->identifiers);
        }
        if ($this->metadata !== []) {
            $event['metadata'] = Tags::toCompact($this->metadata);
        }
        $event['payload'] = $this->payload;

        return $event;
    }
}

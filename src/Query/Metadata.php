<?php

declare(strict_types=1);

namespace TamarackDB\Query;

use TamarackDB\Exception\InvalidArgumentException;

/**
 * A query filter: the event carries the metadata $name with $value.
 *
 *     Metadata::is('tenantId', 'acme')
 */
final readonly class Metadata
{
    private function __construct(
        public string $name,
        public string $value,
    ) {}

    public static function is(string $name, string $value): self
    {
        if ($name === '') {
            throw new InvalidArgumentException('metadata name must not be empty');
        }

        return new self($name, $value);
    }
}

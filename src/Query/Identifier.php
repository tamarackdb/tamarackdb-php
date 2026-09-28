<?php

declare(strict_types=1);

namespace TamarackDB\Query;

use TamarackDB\Exception\InvalidArgumentException;

/**
 * A query filter: the event carries the identifier $name with $value.
 *
 *     Identifier::is('userId', '123')
 */
final readonly class Identifier
{
    private function __construct(
        public string $name,
        public string $value,
    ) {}

    public static function is(string $name, string $value): self
    {
        if ($name === '') {
            throw new InvalidArgumentException('identifier name must not be empty');
        }

        return new self($name, $value);
    }
}

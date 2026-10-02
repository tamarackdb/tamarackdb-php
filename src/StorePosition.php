<?php

declare(strict_types=1);

namespace TamarackDB;

use TamarackDB\Exception\InvalidArgumentException;

/**
 * A Sequence Position together with the store ID it belongs to. A Sequence
 * Position alone means nothing after a reset, which empties the store and
 * draws a new store ID.
 *
 * Treat it as an opaque value: keep it with toString(), for example in a
 * projection, and give it back with fromString().
 */
final readonly class StorePosition
{
    public function __construct(
        public string $store,
        public int $sequence,
    ) {
        if ($store === '') {
            throw new InvalidArgumentException('a store ID must not be empty');
        }
        if ($sequence < 0) {
            throw new InvalidArgumentException('a Sequence Position must not be negative');
        }
    }

    public function toString(): string
    {
        return $this->store . ':' . $this->sequence;
    }

    public static function fromString(string $value): self
    {
        $at = strrpos($value, ':');
        $sequence = $at === false ? '' : substr($value, $at + 1);
        if ($at === false || !ctype_digit($sequence) || (string) (int) $sequence !== $sequence) {
            throw new InvalidArgumentException(\sprintf('"%s" is not a store position', $value));
        }

        return new self(substr($value, 0, $at), (int) $sequence);
    }
}

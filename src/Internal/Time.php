<?php

declare(strict_types=1);

namespace TamarackDB\Internal;

use TamarackDB\Exception\ProtocolException;

/**
 * @internal
 */
final class Time
{
    public static function parse(mixed $value): \DateTimeImmutable
    {
        if (!\is_string($value)) {
            throw new ProtocolException('expected a time string from the server');
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            throw new ProtocolException(\sprintf('invalid time "%s" from the server', $value), 0, $e);
        }
    }
}

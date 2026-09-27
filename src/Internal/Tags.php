<?php

declare(strict_types=1);

namespace TamarackDB\Internal;

use TamarackDB\Exception\InvalidArgumentException;

/**
 * @internal
 */
final class Tags
{
    /**
     * Normalizes identifiers or metadata given as name => value, or
     * name => list of values, to name => list of values.
     *
     * @param array<array-key, mixed> $tags
     *
     * @return array<string, list<string>>
     */
    public static function normalize(array $tags, string $what): array
    {
        $out = [];
        foreach ($tags as $name => $values) {
            $name = (string) $name;
            if ($name === '') {
                throw new InvalidArgumentException(\sprintf('%s name must not be empty', $what));
            }
            if (\is_string($values)) {
                $values = [$values];
            }
            if (!\is_array($values) || $values === []) {
                throw new InvalidArgumentException(\sprintf('%s "%s" must be a string or a non-empty list of strings', $what, $name));
            }
            foreach ($values as $value) {
                if (!\is_string($value)) {
                    throw new InvalidArgumentException(\sprintf('%s "%s" must be a string or a non-empty list of strings', $what, $name));
                }
                $out[$name][] = $value;
            }
        }

        return $out;
    }

    /**
     * @param array<string, list<string>> $tags
     *
     * @return list<array{name: string, value: string}>
     */
    public static function toPairs(array $tags): array
    {
        $pairs = [];
        foreach ($tags as $name => $values) {
            foreach ($values as $value) {
                $pairs[] = ['name' => $name, 'value' => $value];
            }
        }

        return $pairs;
    }

    /**
     * @param array<string, list<string>> $tags
     *
     * @return array<string, string|list<string>>
     */
    public static function toCompact(array $tags): array
    {
        return array_map(static fn(array $values): string|array => \count($values) === 1 ? $values[0] : $values, $tags);
    }
}

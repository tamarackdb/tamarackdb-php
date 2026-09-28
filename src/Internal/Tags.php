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
     * Checks identifiers or metadata given as name => value, or
     * name => list of values, and returns them in compact form: a name with
     * one value maps to a string, a name with several values to a list.
     *
     * @param array<array-key, mixed> $tags
     *
     * @return array<string, string|list<string>>
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
                $out[$name] = $values;

                continue;
            }
            if (!\is_array($values) || $values === []) {
                throw new InvalidArgumentException(\sprintf('%s "%s" must be a string or a non-empty list of strings', $what, $name));
            }
            $list = [];
            foreach ($values as $value) {
                if (!\is_string($value)) {
                    throw new InvalidArgumentException(\sprintf('%s "%s" must be a string or a non-empty list of strings', $what, $name));
                }
                $list[] = $value;
            }
            $out[$name] = \count($list) === 1 ? $list[0] : $list;
        }

        return $out;
    }

    /**
     * Adds one value to tags in compact form, unless it's already there.
     *
     * @param array<string, string|list<string>> $tags
     *
     * @return array<string, string|list<string>>
     */
    public static function add(array $tags, string $name, string $value): array
    {
        $values = $tags[$name] ?? [];
        $values = \is_string($values) ? [$values] : $values;
        if (!\in_array($value, $values, true)) {
            $values[] = $value;
        }
        $tags[$name] = \count($values) === 1 ? $values[0] : $values;

        return $tags;
    }

    /**
     * @param array<string, string|list<string>> $tags
     *
     * @return list<array{name: string, value: string}>
     */
    public static function toPairs(array $tags): array
    {
        $pairs = [];
        foreach ($tags as $name => $values) {
            foreach (\is_string($values) ? [$values] : $values as $value) {
                $pairs[] = ['name' => $name, 'value' => $value];
            }
        }

        return $pairs;
    }
}

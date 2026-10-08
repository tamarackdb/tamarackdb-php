<?php

declare(strict_types=1);

namespace TamarackDB\Internal;

use TamarackDB\Exception\InvalidArgumentException;

/**
 * The type + id of each projection in one write, to reject an empty one
 * or the same one twice.
 *
 * @internal
 */
final class ProjectionKeys
{
    /** @var array<string, string> where each type + id is already written, keyed by type and id */
    private array $seen = [];

    /**
     * Claims a type + id for $op[$index], such as create[0].
     */
    public function claim(string $op, int $index, string $type, string $id): void
    {
        if ($type === '' || $id === '') {
            throw new InvalidArgumentException('a projection type and id must not be empty');
        }
        $key = $type . "\0" . $id;
        if (isset($this->seen[$key])) {
            throw new InvalidArgumentException(\sprintf('%s[%d] has the same type and id as %s', $op, $index, $this->seen[$key]));
        }
        $this->seen[$key] = \sprintf('%s[%d]', $op, $index);
    }
}

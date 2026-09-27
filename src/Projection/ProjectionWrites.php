<?php

declare(strict_types=1);

namespace TamarackDB\Projection;

use TamarackDB\Exception\InvalidArgumentException;

/**
 * Collects the projection writes of one POST /projections call.
 *
 * Collect every change your event handlers make, then send them in one
 * call right before the commit.
 *
 *     $writes = (new ProjectionWrites())
 *         ->create('user-list-entry', '789', '{"name":"Grace"}')
 *         ->replace('user-profile', '123', $profile->version, '{"name":"Ada Lovelace"}')
 *         ->delete('user-list-entry', '456', $entry->version);
 */
final class ProjectionWrites implements \Countable
{
    /** @var list<array{type: string, id: string, payload: string}> */
    private array $create = [];

    /** @var list<array{type: string, id: string, version: string, payload: string}> */
    private array $replace = [];

    /** @var list<array{type: string, id: string, version: string}> */
    private array $delete = [];

    /** @var array<string, string> where each type + id is already written, keyed by type and id */
    private array $seen = [];

    /**
     * Creates a projection that must not exist yet.
     */
    public function create(string $type, string $id, string $payload): self
    {
        $this->claim('create', \count($this->create), $type, $id);
        $this->create[] = ['type' => $type, 'id' => $id, 'payload' => $payload];

        return $this;
    }

    /**
     * Replaces the whole payload of the projection stored at $version.
     */
    public function replace(string $type, string $id, string $version, string $payload): self
    {
        $this->claim('replace', \count($this->replace), $type, $id);
        $this->replace[] = ['type' => $type, 'id' => $id, 'version' => $version, 'payload' => $payload];

        return $this;
    }

    /**
     * Deletes the projection stored at $version.
     */
    public function delete(string $type, string $id, string $version): self
    {
        $this->claim('delete', \count($this->delete), $type, $id);
        $this->delete[] = ['type' => $type, 'id' => $id, 'version' => $version];

        return $this;
    }

    public function count(): int
    {
        return \count($this->create) + \count($this->replace) + \count($this->delete);
    }

    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    /**
     * @return array{create: list<array{type: string, id: string, payload: string}>, replace: list<array{type: string, id: string, version: string, payload: string}>, delete: list<array{type: string, id: string, version: string}>}
     */
    public function toArray(): array
    {
        return ['create' => $this->create, 'replace' => $this->replace, 'delete' => $this->delete];
    }

    private function claim(string $op, int $index, string $type, string $id): void
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

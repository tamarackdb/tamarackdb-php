<?php

declare(strict_types=1);

namespace TamarackDB\Projection;

use TamarackDB\Internal\ProjectionKeys;

/**
 * Collects the projection writes of one writeProjections() call, outside a
 * transaction: for a projection rebuild, or a projector that catches up on
 * its own. Each replace and delete names the version it was read at.
 *
 *     $writes = new ProjectionWrites()
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

    private readonly ProjectionKeys $keys;

    public function __construct()
    {
        $this->keys = new ProjectionKeys();
    }

    /**
     * Creates a projection that must not exist yet.
     */
    public function create(string $type, string $id, string $payload): self
    {
        $this->keys->claim('create', \count($this->create), $type, $id);
        $this->create[] = ['type' => $type, 'id' => $id, 'payload' => $payload];

        return $this;
    }

    /**
     * Replaces the whole payload of the projection stored at $version.
     */
    public function replace(string $type, string $id, string $version, string $payload): self
    {
        $this->keys->claim('replace', \count($this->replace), $type, $id);
        $this->replace[] = ['type' => $type, 'id' => $id, 'version' => $version, 'payload' => $payload];

        return $this;
    }

    /**
     * Deletes the projection stored at $version.
     */
    public function delete(string $type, string $id, string $version): self
    {
        $this->keys->claim('delete', \count($this->delete), $type, $id);
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
}

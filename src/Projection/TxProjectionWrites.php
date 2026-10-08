<?php

declare(strict_types=1);

namespace TamarackDB\Projection;

use TamarackDB\Internal\ProjectionKeys;

/**
 * Collects the projection writes of one Transaction::writeProjections()
 * call. No write carries a version: the server keeps the version of each
 * projection the transaction read.
 *
 *     $writes = new TxProjectionWrites()
 *         ->create('user-profile', '789', '{"name":"Grace"}')
 *         ->replace('user-stats', 'all', '{"count":42}')
 *         ->delete('user-list-entry', '456');
 *
 * A create needs no read. A replace or a delete needs a read of the
 * projection in the transaction, or a create earlier in it. See
 * https://tamarackdb.github.io/docs/http-api/transactions/#writing-projections
 */
final class TxProjectionWrites implements \Countable
{
    /** @var list<array{type: string, id: string, payload: string}> */
    private array $create = [];

    /** @var list<array{type: string, id: string, payload: string}> */
    private array $replace = [];

    /** @var list<array{type: string, id: string}> */
    private array $delete = [];

    private readonly ProjectionKeys $keys;

    public function __construct()
    {
        $this->keys = new ProjectionKeys();
    }

    /**
     * Creates a projection that must not exist yet. At commit, one that
     * exists gets a ConcurrencyException.
     */
    public function create(string $type, string $id, string $payload): self
    {
        $this->keys->claim('create', \count($this->create), $type, $id);
        $this->create[] = ['type' => $type, 'id' => $id, 'payload' => $payload];

        return $this;
    }

    /**
     * Replaces the whole payload of a projection that exists in the
     * transaction.
     */
    public function replace(string $type, string $id, string $payload): self
    {
        $this->keys->claim('replace', \count($this->replace), $type, $id);
        $this->replace[] = ['type' => $type, 'id' => $id, 'payload' => $payload];

        return $this;
    }

    /**
     * Deletes a projection. Deleting one that doesn't exist does nothing.
     */
    public function delete(string $type, string $id): self
    {
        $this->keys->claim('delete', \count($this->delete), $type, $id);
        $this->delete[] = ['type' => $type, 'id' => $id];

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
     * @return array{create: list<array{type: string, id: string, payload: string}>, replace: list<array{type: string, id: string, payload: string}>, delete: list<array{type: string, id: string}>}
     */
    public function toArray(): array
    {
        return ['create' => $this->create, 'replace' => $this->replace, 'delete' => $this->delete];
    }
}

<?php

declare(strict_types=1);

namespace TamarackDB;

use TamarackDB\Event\Events;
use TamarackDB\Exception\ConcurrencyException;
use TamarackDB\Exception\NoActiveTransactionException;
use TamarackDB\Exception\PausedException;
use TamarackDB\Exception\ProtocolException;
use TamarackDB\Exception\StoreChangedException;
use TamarackDB\Exception\TimeoutException;
use TamarackDB\Exception\TransactionAlreadyActiveException;
use TamarackDB\Exception\WriteQueueFullException;
use TamarackDB\Http\CurlTransport;
use TamarackDB\Http\Transport;
use TamarackDB\Internal\Api;
use TamarackDB\Internal\Json;
use TamarackDB\Projection\Projection;
use TamarackDB\Projection\ProjectionWriteResult;
use TamarackDB\Projection\ProjectionWrites;
use TamarackDB\Query\AllEvents;
use TamarackDB\Query\NoEvents;
use TamarackDB\Query\Query;

/**
 * Client for one TamarackDB server.
 *
 * A command runs in a transaction, from beginTransaction() to commit() or
 * rollback() on the Transaction. The client holds at most one active
 * transaction at a time, and getTransaction() returns it.
 *
 * The other methods run outside any transaction: reading committed
 * events, for a projector that catches up on its own; writing projections
 * with their versions; and rebuilding projections.
 *
 *     $client = Client::http('http://127.0.0.1:8085');
 *     $client = Client::unixSocket('/run/tamarackdb/tamarackdb.sock', token: 'secret');
 */
final class Client
{
    private readonly Api $api;

    private ?Transaction $transaction = null;

    public function __construct(Transport $transport)
    {
        $this->api = new Api($transport);
    }

    /**
     * Connects over TCP.
     *
     * @param string|null $token Bearer token, when the server has enableAuth on
     * @param float $timeout seconds to wait for a response, including the
     *                       wait for a turn in the server's queue
     */
    public static function http(
        string $baseUrl = 'http://127.0.0.1:8085',
        ?string $token = null,
        float $timeout = 60.0,
    ): self {
        return new self(new CurlTransport($baseUrl, token: $token, timeout: $timeout));
    }

    /**
     * Connects over the server's unix socket.
     *
     * @param string|null $token Bearer token, when the server has enableAuth on
     * @param float $timeout seconds to wait for a response, including the
     *                       wait for a turn in the server's queue
     */
    public static function unixSocket(
        string $socketPath = '/run/tamarackdb/tamarackdb.sock',
        ?string $token = null,
        float $timeout = 60.0,
    ): self {
        return new self(new CurlTransport(unixSocket: $socketPath, token: $token, timeout: $timeout));
    }

    /**
     * Begins a transaction, and returns it. getTransaction() returns it too,
     * until it's over.
     *
     * @throws TransactionAlreadyActiveException when this client already has an active transaction
     * @throws PausedException when a pause is requested or in place
     */
    public function beginTransaction(): Transaction
    {
        if ($this->transaction !== null && $this->transaction->isActive()) {
            throw new TransactionAlreadyActiveException(\sprintf('transaction %s is already active', $this->transaction->id));
        }

        return $this->transaction = new Transaction($this->api, $this->api->begin());
    }

    /**
     * Whether this client has an active transaction, as far as it knows.
     * The server can still end it on its own, for example once it expires.
     */
    public function inTransaction(): bool
    {
        return $this->transaction !== null && $this->transaction->isActive();
    }

    /**
     * The active transaction.
     *
     * @throws NoActiveTransactionException when there is none
     */
    public function getTransaction(): Transaction
    {
        if ($this->transaction === null || !$this->transaction->isActive()) {
            throw new NoActiveTransactionException('no active transaction');
        }

        return $this->transaction;
    }

    /**
     * Reads the committed events matching $query, oldest first. Pages are
     * fetched as the events are iterated. The read never waits for a
     * write. A page cut short is resumed after the last event received, so
     * no event is skipped or repeated.
     *
     * To follow new events, keep the last Sequence Position you got and
     * the store ID of the read (Events::storeId()). Read again later with
     * both. If the store was reset in between, the read throws a
     * StoreChangedException: start over from the beginning.
     *
     * A decision reads in its transaction instead, with
     * Transaction::readEvents().
     *
     * @param int|null $afterSequence only events after this Sequence Position
     * @param string|null $storeId the store ID $afterSequence comes from
     * @param int|null $pageSize events per request, or null for the server's default
     *
     * @throws StoreChangedException when a page comes from another store ID
     */
    public function readEvents(
        Query|AllEvents|NoEvents $query,
        ?int $afterSequence = null,
        ?string $storeId = null,
        ?int $pageSize = null,
    ): Events {
        return $this->api->readEvents($query, $afterSequence, $storeId, $pageSize);
    }

    /**
     * Reads a committed projection, with its version, or null when none
     * exists. Keep the version to replace or delete the projection with
     * writeProjections().
     *
     * @phpstan-impure
     */
    public function getProjection(string $type, string $id): ?Projection
    {
        return $this->api->getProjection(null, $type, $id);
    }

    /**
     * Creates, replaces, and deletes projections, all or nothing: for a
     * projection rebuild, or a projector that catches up on its own. The
     * call waits for its turn in the server's queue.
     *
     * @throws ConcurrencyException when a projection isn't at the version given, or a created one already exists
     * @throws TimeoutException when the turn and the write didn't end in time
     * @throws WriteQueueFullException when too many requests are already waiting
     */
    public function writeProjections(ProjectionWrites $writes): ProjectionWriteResult
    {
        return $this->api->writeProjections($writes);
    }

    /**
     * Deletes every projection of one type, for a projection rebuild. The
     * call waits for its turn in the server's queue.
     *
     * @throws TimeoutException when the turn and the delete didn't end in time
     * @throws WriteQueueFullException when too many requests are already waiting
     */
    public function deleteProjectionsByType(string $type): void
    {
        $this->api->call('DELETE', '/projections/' . rawurlencode($type));
    }

    /**
     * Deletes every projection, for a projection rebuild. The call waits
     * for its turn in the server's queue.
     *
     * @throws TimeoutException when the turn and the delete didn't end in time
     * @throws WriteQueueFullException when too many requests are already waiting
     */
    public function deleteAllProjections(): void
    {
        $this->api->call('DELETE', '/projections');
    }

    public function health(): Health
    {
        $data = Json::decodeObject($this->api->call('GET', '/health')->body);
        if (!\is_string($data['status'] ?? null) || !\is_string($data['version'] ?? null)) {
            throw new ProtocolException('invalid GET /health response');
        }

        return new Health($data['status'], $data['version']);
    }

    /**
     * Deletes every event and every projection, and rolls back the active
     * transaction. Only exists when the server has devMode on: meant for
     * test suites, never production.
     */
    public function reset(): void
    {
        $this->transaction?->rollback();
        $this->api->call('POST', '/reset');
    }
}

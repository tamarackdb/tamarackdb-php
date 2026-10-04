<?php

declare(strict_types=1);

namespace TamarackDB;

use TamarackDB\Event\Event;
use TamarackDB\Event\Events;
use TamarackDB\Event\NewEvent;
use TamarackDB\Event\PendingEvent;
use TamarackDB\Exception\ConcurrencyException;
use TamarackDB\Exception\InvalidArgumentException;
use TamarackDB\Exception\NoActiveTransactionException;
use TamarackDB\Exception\ProtocolException;
use TamarackDB\Exception\ServerException;
use TamarackDB\Exception\StoreChangedException;
use TamarackDB\Exception\TamarackDBException;
use TamarackDB\Exception\TimeoutException;
use TamarackDB\Exception\TransactionAlreadyActiveException;
use TamarackDB\Exception\TransactionNotFoundException;
use TamarackDB\Exception\TransportException;
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
 * Client for one TamarackDB server. It holds at most one transaction at a
 * time: beginTransaction() opens it, and every call until commit() or
 * rollback() runs inside it.
 *
 * Any server error on a call in the transaction ends it, except a missing
 * projection. A transport error leaves it open on this side, since the
 * call may not have reached the server: call rollback().
 *
 *     $client = Client::http('http://127.0.0.1:8085');
 *     $client = Client::unixSocket('/run/tamarackdb/tamarackdb.sock', token: 'secret');
 */
final class Client
{
    private readonly Api $api;

    private ?string $txId = null;

    /** @var array<string, true> the projections read in the transaction, keyed by type and id */
    private array $readProjections = [];

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
     * Opens a transaction. From then on, readEvents(), appendEvents(),
     * getProjection(), saveProjection(), and deleteProjection() run inside
     * it, until commit() or rollback().
     *
     * @throws TransactionAlreadyActiveException when this client already has a transaction
     */
    public function beginTransaction(): void
    {
        if ($this->txId !== null) {
            throw new TransactionAlreadyActiveException(\sprintf('transaction %s is already active', $this->txId));
        }
        $this->txId = $this->api->begin();
        $this->readProjections = [];
    }

    /**
     * Whether this client has a transaction, as far as it knows. The server
     * can still end it on its own, for example once it expires.
     */
    public function inTransaction(): bool
    {
        return $this->txId !== null;
    }

    /**
     * Commits the transaction, in its turn in the server's queue. The
     * transaction is over afterwards, whatever the outcome. If the
     * response is lost, the commit can't be sent again: read what the
     * transaction wrote to know whether it happened.
     *
     * @throws NoActiveTransactionException outside a transaction
     * @throws ConcurrencyException when what the transaction read changed since: run the whole command again
     * @throws TransactionNotFoundException when the transaction expired, or an error ended it
     * @throws TimeoutException when the turn and the commit didn't end in time
     * @throws WriteQueueFullException when too many requests are already waiting
     */
    public function commit(): void
    {
        $txId = $this->requireTransaction();
        $this->end();
        $this->api->commit($txId);
    }

    /**
     * Abandons the transaction: nothing it holds is written. Without a
     * transaction, it does nothing. It never throws, so it's safe in the
     * error handler around a command: if the server can't be reached, the
     * transaction expires there on its own.
     */
    public function rollback(): void
    {
        $txId = $this->txId;
        if ($txId === null) {
            return;
        }
        $this->end();
        try {
            $this->api->abandon($txId);
        } catch (TamarackDBException) {
            // The server ends an abandoned transaction on its own.
        }
    }

    /**
     * Reads the events matching $query, oldest first.
     *
     * Outside a transaction, the read sees committed events only (Event),
     * and never waits for a write. Pages are fetched as the events are
     * iterated. A page cut short is resumed after the last event received,
     * so no event is skipped or repeated.
     *
     * To follow new events, keep the last Sequence Position you got and
     * the store ID of the read (Events::storeId()). Read again later with
     * both. If the store was reset in between, the read throws a
     * StoreChangedException: start over from the beginning.
     *
     * Inside a transaction, the read is the one a decision rests on. It
     * returns every committed event that matches (Event), then every event
     * written earlier in the transaction that matches (PendingEvent), with
     * no pages. The whole response is read before readEvents() returns.
     * The next call must be
     * appendEvents(), with the events of the decision or an empty list. A
     * response cut short, or one this library can't read, abandons the
     * transaction and throws: run the whole command again.
     *
     * @param int|null $afterSequence only events after this Sequence Position, outside a transaction
     * @param string|null $storeId the store ID $afterSequence comes from
     * @param int|null $pageSize events per request, or null for the server's default
     *
     * @throws InvalidArgumentException inside a transaction, with $afterSequence, $storeId, or $pageSize
     * @throws StoreChangedException when a page comes from another store ID
     * @throws ServerException when the server refuses the read; inside a transaction, the transaction is over
     * @throws TransportException inside a transaction, when the response was cut short
     */
    public function readEvents(
        Query|AllEvents|NoEvents $query,
        ?int $afterSequence = null,
        ?string $storeId = null,
        ?int $pageSize = null,
    ): Events {
        $txId = $this->txId;
        if ($txId === null) {
            return $this->api->readEvents($query, $afterSequence, $storeId, $pageSize);
        }
        if ($afterSequence !== null || $storeId !== null || $pageSize !== null) {
            throw new InvalidArgumentException('a read in a transaction takes no afterSequence, storeId, or pageSize');
        }
        $events = iterator_to_array($this->readTxEvents($txId, $query), false);

        return new Events(null, static function () use ($events): \Generator {
            yield from $events;
        });
    }

    /**
     * Writes events in the transaction, and returns the time they all
     * carry: give it to each event before the code that reacts to it
     * runs. The events get their Sequence Position at commit.
     *
     * The write closes the read before it. An empty list is the decision
     * to write nothing.
     *
     * @param list<NewEvent> $events
     *
     * @throws NoActiveTransactionException outside a transaction
     * @throws ServerException when the server refuses the write: the transaction is over
     */
    public function appendEvents(array $events): \DateTimeImmutable
    {
        $txId = $this->requireTransaction();

        return $this->runInTransaction($txId, fn(): \DateTimeImmutable => $this->api->writeTxEvents($txId, array_values($events)));
    }

    /**
     * Reads a projection, or null when none exists. Inside a transaction,
     * it sees the projections written earlier in it, a missing projection
     * doesn't end the transaction, and the version is null. Outside one, it
     * sees committed projections only, with their version.
     */
    public function getProjection(string $type, string $id): ?Projection
    {
        $txId = $this->txId;
        if ($txId === null) {
            return $this->api->getProjection(null, $type, $id);
        }
        $projection = $this->runInTransaction($txId, fn(): ?Projection => $this->api->getProjection($txId, $type, $id));
        $this->readProjections[self::projectionKey($type, $id)] = true;

        return $projection;
    }

    /**
     * Creates or replaces a projection in the transaction, with its whole
     * new payload. The transaction must have read it first: if it didn't,
     * this reads it, so call it only once the events of the last read are
     * written.
     *
     * @throws NoActiveTransactionException outside a transaction
     * @throws InvalidArgumentException when $type or $id is empty
     * @throws ServerException when the server refuses the read or the write: the transaction is over
     */
    public function saveProjection(string $type, string $id, string $payload): void
    {
        $txId = $this->requireTransaction();
        $this->readBeforeWrite($txId, $type, $id);
        $this->runInTransaction($txId, fn() => $this->api->writeTxProjection($txId, $type, $id, $payload));
    }

    /**
     * Deletes a projection in the transaction. Deleting one that doesn't
     * exist does nothing. The transaction must have read it first: if it
     * didn't, this reads it, so call it only once the events of the last
     * read are written.
     *
     * @throws NoActiveTransactionException outside a transaction
     * @throws InvalidArgumentException when $type or $id is empty
     * @throws ServerException when the server refuses the read or the write: the transaction is over
     */
    public function deleteProjection(string $type, string $id): void
    {
        $txId = $this->requireTransaction();
        $this->readBeforeWrite($txId, $type, $id);
        $this->runInTransaction($txId, fn() => $this->api->writeTxProjection($txId, $type, $id, null));
    }

    /**
     * Creates, replaces, and deletes projections, all or nothing, outside a
     * transaction: for a projection rebuild, or a projector that catches up
     * on its own. The call waits for its turn in the server's queue.
     *
     * @throws TransactionAlreadyActiveException inside a transaction
     * @throws ConcurrencyException when a projection isn't at the version given, or a created one already exists
     * @throws TimeoutException when the turn and the write didn't end in time
     * @throws WriteQueueFullException when too many requests are already waiting
     */
    public function writeProjections(ProjectionWrites $writes): ProjectionWriteResult
    {
        if ($this->txId !== null) {
            throw new TransactionAlreadyActiveException('writeProjections() runs outside a transaction');
        }

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
     * Deletes every event and every projection. Only exists when the
     * server has devMode on: meant for test suites, never production.
     */
    public function reset(): void
    {
        $this->end();
        $this->api->call('POST', '/reset');
    }

    /**
     * Runs a call in transaction $txId, and forgets the transaction when
     * the server answers with an error: the server ended it.
     *
     * @template T
     *
     * @param \Closure(): T $call
     *
     * @return T
     */
    private function runInTransaction(string $txId, \Closure $call): mixed
    {
        try {
            return $call();
        } catch (ServerException $e) {
            $this->forget($txId);
            throw $e;
        }
    }

    /**
     * @return \Generator<int, Event|PendingEvent>
     */
    private function readTxEvents(string $txId, Query|AllEvents|NoEvents $query): \Generator
    {
        try {
            yield from $this->api->readTxEvents($txId, $query);
        } catch (ServerException $e) {
            $this->forget($txId);
            throw $e;
        } catch (TransportException|ProtocolException $e) {
            // The read's condition is open, and what it missed is unknown.
            if ($this->txId === $txId) {
                $this->rollback();
            }
            throw $e;
        }
    }

    private function forget(string $txId): void
    {
        if ($this->txId === $txId) {
            $this->end();
        }
    }

    private function end(): void
    {
        $this->txId = null;
        $this->readProjections = [];
    }

    /**
     * Reads the projection in transaction $txId, unless the transaction
     * already read it: the server only lets a transaction write a
     * projection it read.
     */
    private function readBeforeWrite(string $txId, string $type, string $id): void
    {
        if ($type === '' || $id === '') {
            throw new InvalidArgumentException('a projection type and id must not be empty');
        }
        if (!isset($this->readProjections[self::projectionKey($type, $id)])) {
            $this->getProjection($type, $id);
        }
    }

    private static function projectionKey(string $type, string $id): string
    {
        return $type . "\0" . $id;
    }

    private function requireTransaction(): string
    {
        return $this->txId ?? throw new NoActiveTransactionException('no active transaction');
    }
}

<?php

declare(strict_types=1);

namespace TamarackDB;

use TamarackDB\Event\Event;
use TamarackDB\Event\NewEvent;
use TamarackDB\Event\PendingEvent;
use TamarackDB\Exception\ConcurrencyException;
use TamarackDB\Exception\InvalidArgumentException;
use TamarackDB\Exception\NoActiveTransactionException;
use TamarackDB\Exception\ProtocolException;
use TamarackDB\Exception\ServerException;
use TamarackDB\Exception\TamarackDBException;
use TamarackDB\Exception\TimeoutException;
use TamarackDB\Exception\TransactionNotFoundException;
use TamarackDB\Exception\TransportException;
use TamarackDB\Exception\WriteQueueFullException;
use TamarackDB\Internal\Api;
use TamarackDB\Projection\Projection;
use TamarackDB\Query\AllEvents;
use TamarackDB\Query\NoEvents;
use TamarackDB\Query\Query;

/**
 * A transaction on the server, from Client::beginTransaction() to commit()
 * or rollback().
 *
 * Each decision reads events with readEvents(), then writes its events,
 * or none, with appendEvents(). Projections are read with getProjection()
 * and written with saveProjection() or deleteProjection(). Nothing is
 * written until commit().
 *
 * Any server error ends the transaction, except a missing projection. A
 * transport error leaves it active on this side, since the call may not
 * have reached the server: call rollback(). Once the transaction is over,
 * every call but rollback() throws a NoActiveTransactionException.
 */
final class Transaction
{
    private bool $active = true;

    /** @var array<string, true> the projections read in the transaction, keyed by type and id */
    private array $readProjections = [];

    /**
     * @internal
     *
     * @param string $id the txId the server gave the transaction
     */
    public function __construct(
        private readonly Api $api,
        public readonly string $id,
    ) {}

    /**
     * Whether the transaction is still active, as far as this side knows.
     * The server can still end it on its own, for example once it expires.
     */
    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Reads the events a decision rests on: every committed event that
     * matches (Event), then every event written earlier in the transaction
     * that matches (PendingEvent). There are no pages: the whole response
     * is read before this returns.
     *
     * The next call must be appendEvents(), with the events of the
     * decision or an empty list. A response cut short, or one this library
     * can't read, abandons the transaction and throws: run the whole
     * command again.
     *
     * @return list<Event|PendingEvent>
     *
     * @throws NoActiveTransactionException when the transaction is over
     * @throws ServerException when the server refuses the read: the transaction is over
     * @throws TransportException when the response was cut short: the transaction is over
     */
    public function readEvents(Query|AllEvents|NoEvents $query): array
    {
        $this->requireActive();
        try {
            return iterator_to_array($this->api->readTxEvents($this->id, $query), false);
        } catch (ServerException $e) {
            $this->active = false;
            throw $e;
        } catch (TransportException|ProtocolException $e) {
            // The read's condition is open, and what it missed is unknown.
            $this->rollback();
            throw $e;
        }
    }

    /**
     * Writes events, and returns the time they all carry: give it to each
     * event before the code that reacts to it runs. The events get their
     * Sequence Position at commit.
     *
     * The write closes the read before it. An empty list is the decision
     * to write nothing.
     *
     * @param list<NewEvent> $events
     *
     * @throws NoActiveTransactionException when the transaction is over
     * @throws ServerException when the server refuses the write: the transaction is over
     */
    public function appendEvents(array $events): \DateTimeImmutable
    {
        return $this->run(fn(): \DateTimeImmutable => $this->api->writeTxEvents($this->id, array_values($events)));
    }

    /**
     * Reads a projection as the transaction sees it, with the changes it
     * made, or null when none exists. A missing projection doesn't end the
     * transaction. The version is always null: the server keeps the
     * version read.
     *
     * @phpstan-impure
     *
     * @throws NoActiveTransactionException when the transaction is over
     * @throws ServerException when the server refuses the read: the transaction is over
     */
    public function getProjection(string $type, string $id): ?Projection
    {
        $projection = $this->run(fn(): ?Projection => $this->api->getProjection($this->id, $type, $id));
        $this->readProjections[self::projectionKey($type, $id)] = true;

        return $projection;
    }

    /**
     * Creates or replaces a projection, with its whole new payload. The
     * transaction must have read it first: if it didn't, this reads it, so
     * call it only once the events of the last read are written.
     *
     * @throws NoActiveTransactionException when the transaction is over
     * @throws InvalidArgumentException when $type or $id is empty
     * @throws ServerException when the server refuses the read or the write: the transaction is over
     */
    public function saveProjection(string $type, string $id, string $payload): void
    {
        $this->readBeforeWrite($type, $id);
        $this->run(fn() => $this->api->writeTxProjection($this->id, $type, $id, $payload));
    }

    /**
     * Deletes a projection. Deleting one that doesn't exist does nothing.
     * The transaction must have read it first: if it didn't, this reads
     * it, so call it only once the events of the last read are written.
     *
     * @throws NoActiveTransactionException when the transaction is over
     * @throws InvalidArgumentException when $type or $id is empty
     * @throws ServerException when the server refuses the read or the write: the transaction is over
     */
    public function deleteProjection(string $type, string $id): void
    {
        $this->readBeforeWrite($type, $id);
        $this->run(fn() => $this->api->writeTxProjection($this->id, $type, $id, null));
    }

    /**
     * Commits the transaction, in its turn in the server's queue. The
     * transaction is over afterwards, whatever the outcome. If the
     * response is lost, the commit can't be sent again: read what the
     * transaction wrote to know whether it happened.
     *
     * @throws NoActiveTransactionException when the transaction is over
     * @throws ConcurrencyException when what the transaction read changed since: run the whole command again
     * @throws TransactionNotFoundException when the transaction expired, or an error ended it
     * @throws TimeoutException when the turn and the commit didn't end in time
     * @throws WriteQueueFullException when too many requests are already waiting
     */
    public function commit(): void
    {
        $this->requireActive();
        $this->active = false;
        $this->api->commit($this->id);
    }

    /**
     * Abandons the transaction: nothing it holds is written. Once the
     * transaction is over, it does nothing. It never throws, so it's safe
     * in the error handler around a command: if the server can't be
     * reached, the transaction expires there on its own.
     */
    public function rollback(): void
    {
        if (!$this->active) {
            return;
        }
        $this->active = false;
        try {
            $this->api->abandon($this->id);
        } catch (TamarackDBException) {
            // The server ends an abandoned transaction on its own.
        }
    }

    /**
     * Runs a call, and ends the transaction when the server answers with
     * an error: the server ended it.
     *
     * @template T
     *
     * @param \Closure(): T $call
     *
     * @return T
     */
    private function run(\Closure $call): mixed
    {
        $this->requireActive();
        try {
            return $call();
        } catch (ServerException $e) {
            $this->active = false;
            throw $e;
        }
    }

    /**
     * Reads the projection unless the transaction already read it: the
     * server only lets a transaction write a projection it read.
     */
    private function readBeforeWrite(string $type, string $id): void
    {
        $this->requireActive();
        if ($type === '' || $id === '') {
            throw new InvalidArgumentException('a projection type and id must not be empty');
        }
        if (!isset($this->readProjections[self::projectionKey($type, $id)])) {
            $this->getProjection($type, $id);
        }
    }

    private function requireActive(): void
    {
        if (!$this->active) {
            throw new NoActiveTransactionException(\sprintf('transaction %s is over', $this->id));
        }
    }

    private static function projectionKey(string $type, string $id): string
    {
        return $type . "\0" . $id;
    }
}

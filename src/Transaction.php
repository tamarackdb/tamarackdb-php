<?php

declare(strict_types=1);

namespace TamarackDB;

use TamarackDB\Event\AppendResult;
use TamarackDB\Event\Event;
use TamarackDB\Event\NewEvent;
use TamarackDB\Event\PendingEvent;
use TamarackDB\Exception\ConcurrencyException;
use TamarackDB\Exception\NoActiveTransactionException;
use TamarackDB\Exception\ProtocolException;
use TamarackDB\Exception\ServerException;
use TamarackDB\Exception\TamarackDBException;
use TamarackDB\Exception\TimeoutException;
use TamarackDB\Exception\TransactionBusyException;
use TamarackDB\Exception\TransactionNotFoundException;
use TamarackDB\Exception\TransportException;
use TamarackDB\Exception\WriteQueueFullException;
use TamarackDB\Internal\Api;
use TamarackDB\Projection\Projection;
use TamarackDB\Projection\TxProjectionWrites;
use TamarackDB\Query\AllEvents;
use TamarackDB\Query\NoEvents;
use TamarackDB\Query\Query;

/**
 * A transaction on the server, from Client::beginTransaction() to commit()
 * or rollback().
 *
 * Each decision reads events with readEvents(), then writes its events,
 * or none, with appendEvents(). Projections are read with getProjection()
 * and written with writeProjections(). Nothing is written until commit().
 *
 * Any server error ends the transaction, except a missing projection and
 * a TransactionBusyException. A transport error leaves it active on this side, since the call may not
 * have reached the server: call rollback(). Once the transaction is over,
 * every call but rollback() throws a NoActiveTransactionException.
 */
final class Transaction
{
    private bool $active = true;

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
            $this->endOn($e);
            throw $e;
        } catch (TransportException|ProtocolException $e) {
            // The read's condition is open, and what it missed is unknown.
            $this->rollback();
            throw $e;
        }
    }

    /**
     * Writes events. The result gives the time they all carry: give it to
     * each event before the code that reacts to it runs. The events get
     * their Sequence Position at commit.
     *
     * The write closes the read before it. An empty list is the decision
     * to write nothing.
     *
     * @param list<NewEvent> $events
     *
     * @throws NoActiveTransactionException when the transaction is over
     * @throws ServerException when the server refuses the write: the transaction is over
     */
    public function appendEvents(array $events): AppendResult
    {
        return $this->run(fn(): AppendResult => $this->api->writeTxEvents($this->id, array_values($events)));
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
        return $this->run(fn(): ?Projection => $this->api->getProjection($this->id, $type, $id));
    }

    /**
     * Creates, replaces, and deletes projections. A create needs no read.
     * A replace or a delete needs a read of the projection in the
     * transaction, or a create earlier in it: the server checks, and ends
     * the transaction otherwise. Call it only once the events of the last
     * read are written. Empty writes send nothing.
     *
     * @throws NoActiveTransactionException when the transaction is over
     * @throws ServerException when the server refuses the write: the transaction is over
     */
    public function writeProjections(TxProjectionWrites $writes): void
    {
        $this->requireActive();
        if ($writes->isEmpty()) {
            return;
        }
        $this->run(fn() => $this->api->writeTxProjections($this->id, $writes));
    }

    /**
     * Commits the transaction, in its turn in the server's queue. The
     * transaction is over afterwards, whatever the outcome, except after a
     * TransactionBusyException. If the response is lost, the commit can't
     * be sent again: see
     * https://tamarackdb.github.io/docs/development/http-api/#commit
     *
     * @throws NoActiveTransactionException when the transaction is over
     * @throws ConcurrencyException when what the transaction read changed since: run the whole command again
     * @throws TransactionNotFoundException when the transaction expired, or an error ended it
     * @throws TransactionBusyException when another call still runs on the transaction
     * @throws TimeoutException when the turn and the commit didn't end in time
     * @throws WriteQueueFullException when too many requests are already waiting
     */
    public function commit(): void
    {
        $this->requireActive();
        try {
            $this->api->commit($this->id);
        } catch (TransactionBusyException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->active = false;
            throw $e;
        }
        $this->active = false;
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
            $this->endOn($e);
            throw $e;
        }
    }

    /**
     * Ends the transaction on a server error, except TransactionBusy: the
     * server keeps the transaction then.
     */
    private function endOn(ServerException $e): void
    {
        if (!$e instanceof TransactionBusyException) {
            $this->active = false;
        }
    }

    private function requireActive(): void
    {
        if (!$this->active) {
            throw new NoActiveTransactionException(\sprintf('transaction %s is over', $this->id));
        }
    }
}

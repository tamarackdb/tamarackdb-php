<?php

declare(strict_types=1);

namespace TamarackDB;

use TamarackDB\Event\AppendCondition;
use TamarackDB\Event\AppendedEvent;
use TamarackDB\Event\Events;
use TamarackDB\Event\NewEvent;
use TamarackDB\Exception\ConcurrencyException;
use TamarackDB\Exception\NoActiveTransactionException;
use TamarackDB\Exception\ProtocolException;
use TamarackDB\Exception\ServerException;
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
 * Client for one TamarackDB server. It holds at most one transaction at a
 * time: beginTransaction() opens it, and every call until commit() or
 * rollback() runs inside it.
 *
 *     $client = Client::http('http://127.0.0.1:8085');
 *     $client = Client::unixSocket('/run/tamarackdb/tamarackdb.sock', token: 'secret');
 */
final class Client
{
    private readonly Api $api;

    private ?string $ticket = null;

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
     * Opens a transaction. Waits for its turn when another one is active.
     * From then on, readEvents(),
     * getProjection(), and writeProjections() run inside it, until
     * commit() or rollback().
     *
     * @throws TransactionAlreadyActiveException when this client already has a transaction
     * @throws TimeoutException when the turn didn't come in time
     */
    public function beginTransaction(): void
    {
        if ($this->ticket !== null) {
            throw new TransactionAlreadyActiveException(\sprintf('transaction %s is already active', $this->ticket));
        }
        $data = Json::decodeObject($this->api->call('POST', '/begin')->body);
        if (!\is_string($data['ticket'] ?? null) || $data['ticket'] === '') {
            throw new ProtocolException('invalid POST /begin response');
        }
        $this->ticket = $data['ticket'];
    }

    /**
     * Whether this client has a transaction, as far as it knows. The server
     * can still end it on its own, for example once its idle timeout is
     * reached.
     */
    public function inTransaction(): bool
    {
        return $this->ticket !== null;
    }

    public function commit(): void
    {
        $ticket = $this->requireTicket();
        $this->ticket = null;
        $this->api->call('POST', '/commit');
    }

    public function rollback(): void
    {
        $ticket = $this->requireTicket();
        $this->ticket = null;
        $this->api->call('POST', '/rollback');
    }

    /**
     * Reads the events matching $query, oldest first. Pages are fetched as
     * the events are iterated.
     *
     * The read sees committed events only, and never waits for a write. A
     * page cut short is resumed after the last event received, so no event
     * is skipped or repeated.
     *
     * To follow new events, keep the last Sequence Position you got and
     * the store ID of the read (Events::storeId()). Read again later with
     * both. If the store was reset in between, the read throws a
     * StoreChangedException: start over from the beginning.
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
     * Appends events inside the active transaction, and returns the
     * Sequence Position and time given to each, in order. With a
     * condition, the append fails with a ConcurrencyException if an event
     * matching it exists.
     *
     * @param list<NewEvent> $events at most 100
     *
     * @return list<AppendedEvent>
     *
     * @throws NoActiveTransactionException outside a transaction
     */
    public function appendEvents(array $events, ?AppendCondition $condition = null): array
    {
        $ticket = $this->requireTicket();

        return $this->run($ticket, fn(): array => $this->api->appendEvents($events, $condition));
    }

    /**
     * Reads a projection, or null when none exists. Inside a transaction,
     * it sees the projections written earlier in it, a missing projection
     * doesn't end the transaction, and the version is null. Outside one, it
     * sees committed projections only, with their version.
     */
    public function getProjection(string $type, string $id): ?Projection
    {
        $ticket = $this->ticket;
        if ($ticket === null) {
            return $this->api->getProjection($type, $id);
        }

        return $this->run($ticket, fn(): ?Projection => $this->api->getProjection($type, $id));
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
        if ($this->ticket !== null) {
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
        $this->ticket = null;
        $this->api->call('POST', '/reset');
    }

    /**
     * @template T
     *
     * @param \Closure(): T $call
     *
     * @return T
     */
    private function run(string $ticket, \Closure $call): mixed
    {
        try {
            return $call();
        } catch (ServerException $e) {
            $this->forget($ticket);
            throw $e;
        }
    }

    /**
     * Drops $ticket after a server error: the server rolled the
     * transaction back. A missing projection never gets here, and a
     * transport failure leaves the transaction unknown, so the ticket is
     * kept and a rollback can still be attempted.
     */
    private function forget(string $ticket): void
    {
        if ($this->ticket === $ticket) {
            $this->ticket = null;
        }
    }

    private function requireTicket(): string
    {
        return $this->ticket ?? throw new NoActiveTransactionException('no active transaction');
    }
}

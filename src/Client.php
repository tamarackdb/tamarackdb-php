<?php

declare(strict_types=1);

namespace TamarackDB;

use TamarackDB\Event\AppendCondition;
use TamarackDB\Event\AppendedEvent;
use TamarackDB\Event\Event;
use TamarackDB\Event\NewEvent;
use TamarackDB\Exception\NoActiveTransactionException;
use TamarackDB\Exception\ProtocolException;
use TamarackDB\Exception\ServerException;
use TamarackDB\Exception\TimeoutException;
use TamarackDB\Exception\TransactionAlreadyActiveException;
use TamarackDB\Exception\TransactionQueueFullException;
use TamarackDB\Http\CurlTransport;
use TamarackDB\Http\Transport;
use TamarackDB\Internal\Api;
use TamarackDB\Internal\AppendDelegator;
use TamarackDB\Internal\Json;
use TamarackDB\Internal\ReadDelegator;
use TamarackDB\Middleware\AppendHandler;
use TamarackDB\Middleware\AppendMiddleware;
use TamarackDB\Middleware\ReadHandler;
use TamarackDB\Middleware\ReadMiddleware;
use TamarackDB\Middleware\ReadRequest;
use TamarackDB\Projection\Projection;
use TamarackDB\Projection\ProjectionWriteResult;
use TamarackDB\Projection\ProjectionWrites;
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

    private AppendHandler $appendHandler;

    private ReadHandler $readHandler;

    /** @var list<AppendMiddleware|ReadMiddleware> innermost first */
    private array $middlewares = [];

    private ?string $ticket = null;

    public function __construct(Transport $transport)
    {
        $this->api = new Api($transport);
        $this->appendHandler = $this->api;
        $this->readHandler = $this->api;
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
     * Adds a middleware around every append, every read, or both, when it
     * implements both interfaces. The last one added is the outermost
     * layer: it runs first.
     */
    public function addMiddleware(AppendMiddleware|ReadMiddleware $middleware): void
    {
        $this->middlewares[] = $middleware;
        $this->chainMiddlewares();
    }

    /**
     * Adds a middleware as the innermost layer, closest to the server,
     * whatever was added before or is added after with addMiddleware(). It
     * sees events exactly as they are sent and received.
     */
    public function addInnerMiddleware(AppendMiddleware|ReadMiddleware $middleware): void
    {
        array_unshift($this->middlewares, $middleware);
        $this->chainMiddlewares();
    }

    /**
     * Opens a transaction. Waits for its turn when another one is active.
     * From then on, readEvents(),
     * getProjection(), and writeProjections() run inside it, until
     * commit() or rollback().
     *
     * @throws TransactionAlreadyActiveException when this client already has a transaction
     * @throws TimeoutException when the turn didn't come in time
     * @throws TransactionQueueFullException when too many requests are already waiting
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

    /**
     * The ticket of the active transaction, or null outside one. Log it
     * with the command it belongs to: when a transaction expires, the
     * server logs a warning with that ticket.
     */
    public function getTicket(): ?string
    {
        return $this->ticket;
    }

    public function commit(): void
    {
        $ticket = $this->requireTicket();
        $this->ticket = null;
        $this->api->call('POST', '/commit', $ticket);
    }

    public function rollback(): void
    {
        $ticket = $this->requireTicket();
        $this->ticket = null;
        $this->api->call('POST', '/rollback', $ticket);
    }

    /**
     * Reads the events matching $query, or every event when $query is
     * null, oldest first. Pages are fetched as the generator is consumed.
     *
     * Inside a transaction, the read sees the events appended earlier in
     * it, and stays tied to that transaction even if the generator is
     * consumed later. Stopping early is safe: the rest of the current page
     * is read and discarded, since closing the connection would roll the
     * transaction back.
     *
     * Outside a transaction, the read sees committed events only, and never
     * waits for the active transaction. A page cut short is resumed after
     * the last event received, so no event is skipped or repeated. To
     * follow new events, keep the last Sequence Position you got, and read
     * again later with it as $afterSequence.
     *
     * @param int|null $afterSequence only events after this Sequence Position
     * @param \DateTimeInterface|null $from only events appended at or after this time
     * @param \DateTimeInterface|null $before only events appended before this time
     * @param int|null $pageSize events per request, or null for the server's default
     *
     * @return \Generator<int, Event>
     */
    public function readEvents(
        ?Query $query,
        ?int $afterSequence = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $before = null,
        ?int $pageSize = null,
    ): \Generator {
        $ticket = $this->ticket;
        $events = $this->readHandler->readEvents(new ReadRequest($query, $afterSequence, $from, $before, $pageSize, $ticket));

        return $ticket === null ? $events : $this->endOnServerError($ticket, $events);
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

        return $this->run($ticket, fn(): array => $this->appendHandler->appendEvents($events, $condition, $ticket));
    }

    /**
     * Reads a projection, or null when none exists. Inside a transaction,
     * it sees the projections written earlier in it, and a missing
     * projection doesn't end the transaction. Outside one, it sees
     * committed projections only.
     */
    public function getProjection(string $type, string $id): ?Projection
    {
        $ticket = $this->ticket;
        if ($ticket === null) {
            return $this->api->getProjection(null, $type, $id);
        }

        return $this->run($ticket, fn(): ?Projection => $this->api->getProjection($ticket, $type, $id));
    }

    /**
     * Creates, replaces, and deletes projections. Inside a transaction,
     * send every change in one call, right before the commit. Outside one,
     * for a projection rebuild, the call waits for its turn in the server's
     * queue and commits on its own.
     *
     * @throws TimeoutException when the turn and the write didn't end in time
     * @throws TransactionQueueFullException outside a transaction, when too many requests are already waiting
     */
    public function writeProjections(ProjectionWrites $writes): ProjectionWriteResult
    {
        $ticket = $this->ticket;
        if ($ticket === null) {
            return $this->api->writeProjections(null, $writes);
        }

        return $this->run($ticket, fn(): ProjectionWriteResult => $this->api->writeProjections($ticket, $writes));
    }

    /**
     * Deletes every projection of one type, for a projection rebuild. The
     * call waits for its turn in the server's queue.
     *
     * @throws TimeoutException when the turn and the delete didn't end in time
     * @throws TransactionQueueFullException when too many requests are already waiting
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
     * @throws TransactionQueueFullException when too many requests are already waiting
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
     * The server's current state (active transaction, queue, connection
     * pools), as GET /debug returns it.
     *
     * @return array<string, mixed>
     */
    public function debug(): array
    {
        return Json::decodeObject($this->api->call('GET', '/debug')->body);
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
     * @param \Generator<int, Event> $events
     *
     * @return \Generator<int, Event>
     */
    private function endOnServerError(string $ticket, \Generator $events): \Generator
    {
        try {
            yield from $events;
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

    private function chainMiddlewares(): void
    {
        $this->appendHandler = $this->api;
        $this->readHandler = $this->api;
        foreach ($this->middlewares as $middleware) {
            if ($middleware instanceof AppendMiddleware) {
                $this->appendHandler = new AppendDelegator($middleware, $this->appendHandler);
            }
            if ($middleware instanceof ReadMiddleware) {
                $this->readHandler = new ReadDelegator($middleware, $this->readHandler);
            }
        }
    }

    private function requireTicket(): string
    {
        return $this->ticket ?? throw new NoActiveTransactionException('no active transaction');
    }
}

<?php

declare(strict_types=1);

namespace TamarackDB;

use TamarackDB\Event\Event;
use TamarackDB\Exception\PausedException;
use TamarackDB\Exception\ProtocolException;
use TamarackDB\Exception\TamarackDBException;
use TamarackDB\Exception\TimeoutException;
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
 * Client for one TamarackDB server.
 *
 *     $client = Client::http('http://127.0.0.1:8085');
 *     $client = Client::unixSocket('/run/tamarackdb/tamarackdb.sock', token: 'secret');
 */
final class Client
{
    private readonly Api $api;

    private AppendHandler $appendHandler;

    private ReadHandler $readHandler;

    /**
     * @param float $queueTimeout seconds POST /begin and POST /pause may
     *                            wait for their turn in the server's queue
     */
    public function __construct(
        Transport $transport,
        private readonly float $queueTimeout = 10.0,
    ) {
        $this->api = new Api($transport);
        $this->appendHandler = $this->api;
        $this->readHandler = $this->api;
    }

    /**
     * Connects over TCP.
     *
     * @param string|null $token Bearer token, when the server has enableAuth on
     * @param float $queueTimeout seconds POST /begin and POST /pause may wait for their turn
     * @param float $timeout seconds to wait for any other response
     */
    public static function http(
        string $baseUrl = 'http://127.0.0.1:8085',
        ?string $token = null,
        float $queueTimeout = 10.0,
        float $timeout = 30.0,
    ): self {
        return new self(new CurlTransport($baseUrl, token: $token, timeout: $timeout), $queueTimeout);
    }

    /**
     * Connects over the server's unix socket.
     *
     * @param string|null $token Bearer token, when the server has enableAuth on
     * @param float $queueTimeout seconds POST /begin and POST /pause may wait for their turn
     * @param float $timeout seconds to wait for any other response
     */
    public static function unixSocket(
        string $socketPath = '/run/tamarackdb/tamarackdb.sock',
        ?string $token = null,
        float $queueTimeout = 10.0,
        float $timeout = 30.0,
    ): self {
        return new self(new CurlTransport(unixSocket: $socketPath, token: $token, timeout: $timeout), $queueTimeout);
    }

    /**
     * Adds a middleware around every append, every read, or both, when it
     * implements both interfaces. The last one added is the outermost
     * layer: it runs first.
     *
     * A transaction keeps the middlewares its client had when it began.
     */
    public function addMiddleware(AppendMiddleware|ReadMiddleware $middleware): void
    {
        if ($middleware instanceof AppendMiddleware) {
            $this->appendHandler = new AppendDelegator($middleware, $this->appendHandler);
        }
        if ($middleware instanceof ReadMiddleware) {
            $this->readHandler = new ReadDelegator($middleware, $this->readHandler);
        }
    }

    /**
     * Opens a transaction. Waits for its turn when another one is active,
     * for at most the queue timeout.
     *
     * @throws TimeoutException when the turn didn't come in time
     * @throws PausedException when the server is paused for a projection rebuild
     * @throws TransactionQueueFullException when too many requests are already waiting
     */
    public function begin(): Transaction
    {
        $data = Json::decodeObject($this->api->call('POST', '/begin', timeout: $this->queueTimeout)->body);
        if (!\is_string($data['ticket'] ?? null) || $data['ticket'] === '') {
            throw new ProtocolException('invalid POST /begin response');
        }

        return new Transaction($this->api, $data['ticket'], $this->appendHandler, $this->readHandler);
    }

    /**
     * Runs $command inside a new transaction, and commits it once $command
     * returns, unless $command already ended it. When $command throws, the
     * transaction is rolled back and the exception rethrown.
     *
     * @template T
     *
     * @param callable(Transaction): T $command
     *
     * @return T
     */
    public function transactional(callable $command): mixed
    {
        $transaction = $this->begin();
        try {
            $result = $command($transaction);
        } catch (\Throwable $e) {
            if ($transaction->isActive()) {
                try {
                    $transaction->rollback();
                } catch (TamarackDBException) {
                    // The transaction is already gone, or the server can't
                    // be reached: it rolls back on its own at its idle
                    // timeout. The original failure is what matters.
                }
            }
            throw $e;
        }
        if ($transaction->isActive()) {
            $transaction->commit();
        }

        return $result;
    }

    /**
     * Reads the committed events matching $query, oldest first. It never
     * waits for the active transaction. Pages are fetched as the generator
     * is consumed; a page cut short is resumed after the last event
     * received, so no event is skipped or repeated.
     *
     * To follow new events, keep the last Sequence Position you got, and
     * read again later with it as $afterSequence.
     *
     * @param int|null $afterSequence only events after this Sequence Position
     * @param \DateTimeInterface|null $from only events appended at or after this time
     * @param \DateTimeInterface|null $before only events appended before this time
     * @param int|null $pageSize events per request, or null for the server's default
     *
     * @return \Generator<int, Event>
     */
    public function readEvents(
        Query $query,
        ?int $afterSequence = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $before = null,
        ?int $pageSize = null,
    ): \Generator {
        return $this->readHandler->readEvents(new ReadRequest($query, $afterSequence, $from, $before, $pageSize));
    }

    /**
     * Reads a committed projection, or null when none exists.
     */
    public function getProjection(string $type, string $id): ?Projection
    {
        return $this->api->getProjection(null, $type, $id);
    }

    /**
     * Writes projections outside any transaction, for a projection
     * rebuild. Only accepted while the server is paused; each call commits
     * on its own.
     */
    public function writeProjections(ProjectionWrites $writes): ProjectionWriteResult
    {
        return $this->api->writeProjections(null, $writes);
    }

    /**
     * Deletes every projection of one type. Only accepted while the server
     * is paused.
     */
    public function deleteProjectionsByType(string $type): void
    {
        $this->api->call('DELETE', '/projections/' . rawurlencode($type));
    }

    /**
     * Deletes every projection. Only accepted while the server is paused.
     */
    public function deleteAllProjections(): void
    {
        $this->api->call('DELETE', '/projections');
    }

    /**
     * Pauses the server for a projection rebuild, once every transaction
     * already queued has ended. From then on, begin() fails with a
     * PausedException until resume().
     *
     * @throws TimeoutException when the turn didn't come within the queue timeout
     * @throws TransactionQueueFullException when too many requests are already waiting
     */
    public function pause(): void
    {
        $this->api->call('POST', '/pause', timeout: $this->queueTimeout);
    }

    public function resume(): void
    {
        $this->api->call('POST', '/resume');
    }

    public function health(): Health
    {
        $data = Json::decodeObject($this->api->call('GET', '/health')->body);
        if (!\is_string($data['status'] ?? null) || !\is_string($data['version'] ?? null) || !\is_bool($data['paused'] ?? null)) {
            throw new ProtocolException('invalid GET /health response');
        }

        return new Health($data['status'], $data['version'], $data['paused']);
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
        $this->api->call('POST', '/reset');
    }
}

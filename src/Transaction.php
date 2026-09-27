<?php

declare(strict_types=1);

namespace TamarackDB;

use TamarackDB\Event\AppendCondition;
use TamarackDB\Event\AppendedEvent;
use TamarackDB\Event\Event;
use TamarackDB\Event\NewEvent;
use TamarackDB\Exception\ServerException;
use TamarackDB\Exception\TransactionEndedException;
use TamarackDB\Internal\Api;
use TamarackDB\Projection\Projection;
use TamarackDB\Projection\ProjectionWriteResult;
use TamarackDB\Projection\ProjectionWrites;
use TamarackDB\Query\Query;

/**
 * An open transaction. It holds the store's write lock until it ends:
 * keep it inside one request of your application, and end it with
 * commit() or rollback() before answering the end user.
 *
 * Any server error, except a missing projection, rolls the transaction
 * back on the server. After that, open a new transaction and run the
 * whole command again.
 */
final class Transaction
{
    private bool $active = true;

    /**
     * @internal use Client::begin() or Client::transactional()
     */
    public function __construct(
        private readonly Api $api,
        public readonly string $ticket,
    ) {}

    /**
     * Whether the transaction can still take calls, as far as this client
     * knows. The server can still end it on its own, for example once its
     * idle timeout is reached.
     */
    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Reads the events matching $query, oldest first, inside the
     * transaction: it sees the events appended earlier in it. Pages are
     * fetched as the generator is consumed.
     *
     * Stopping early is safe: the rest of the current page is read and
     * discarded, since closing the connection would roll the transaction
     * back.
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
        $this->assertActive();
        try {
            yield from $this->api->readEvents($this->ticket, $query, $afterSequence, $from, $before, $pageSize);
        } catch (ServerException $e) {
            $this->active = false;
            throw $e;
        }
    }

    /**
     * Appends events, and returns the Sequence Position and time given to
     * each, in order. With a condition, the append fails with a
     * ConcurrencyException if an event matching it exists.
     *
     * @param list<NewEvent> $events at most 100
     *
     * @return list<AppendedEvent>
     */
    public function append(array $events, ?AppendCondition $condition = null): array
    {
        return $this->run(fn(): array => $this->api->append($this->ticket, $events, $condition));
    }

    /**
     * Reads a projection inside the transaction: it sees the projections
     * written earlier in it. Returns null when none exists, which doesn't
     * end the transaction.
     */
    public function getProjection(string $type, string $id): ?Projection
    {
        return $this->run(fn(): ?Projection => $this->api->getProjection($this->ticket, $type, $id));
    }

    /**
     * Creates, replaces, and deletes projections. Send every change in one
     * call, right before the commit.
     */
    public function writeProjections(ProjectionWrites $writes): ProjectionWriteResult
    {
        return $this->run(fn(): ProjectionWriteResult => $this->api->writeProjections($this->ticket, $writes));
    }

    public function commit(): void
    {
        $this->assertActive();
        $this->active = false;
        $this->api->call('POST', '/commit', $this->ticket);
    }

    public function rollback(): void
    {
        $this->assertActive();
        $this->active = false;
        $this->api->call('POST', '/rollback', $this->ticket);
    }

    /**
     * @template T
     *
     * @param \Closure(): T $call
     *
     * @return T
     */
    private function run(\Closure $call): mixed
    {
        $this->assertActive();
        try {
            return $call();
        } catch (ServerException $e) {
            // The server rolled the transaction back. A transport failure
            // leaves it unknown: the client still counts it as active, so
            // a rollback is attempted.
            $this->active = false;
            throw $e;
        }
    }

    private function assertActive(): void
    {
        if (!$this->active) {
            throw new TransactionEndedException(\sprintf('transaction %s has already ended', $this->ticket));
        }
    }
}

<?php

declare(strict_types=1);

namespace TamarackDB\Internal;

use TamarackDB\Event\AppendResult;
use TamarackDB\Event\Event;
use TamarackDB\Event\Events;
use TamarackDB\Event\NewEvent;
use TamarackDB\Event\PendingEvent;
use TamarackDB\Exception\ProjectionNotFoundException;
use TamarackDB\Exception\ProtocolException;
use TamarackDB\Exception\ServerException;
use TamarackDB\Exception\TransportException;
use TamarackDB\Http\Response;
use TamarackDB\Http\Transport;
use TamarackDB\PausePoint;
use TamarackDB\Projection\Projection;
use TamarackDB\Projection\ProjectionWriteResult;
use TamarackDB\Projection\ProjectionWrites;
use TamarackDB\Projection\TxProjectionWrites;
use TamarackDB\Query\AllEvents;
use TamarackDB\Query\NoEvents;
use TamarackDB\Query\Query;

/**
 * The HTTP calls of Client.
 *
 * @internal
 */
final class Api
{
    public const string VERSION_HEADER = 'X-Tamarackdb-Version';

    /**
     * How many times in a row a read resumes a page that was cut short,
     * without getting any new event, before giving up.
     */
    private const int MAX_RESUME_ATTEMPTS = 3;

    public function __construct(
        private readonly Transport $transport,
    ) {}

    /**
     * Sends a request, and throws a ServerException on an error status.
     *
     * @param array<string, mixed>|null $json
     */
    public function call(string $method, string $path, ?array $json = null): Response
    {
        $body = $json === null ? null : Json::encode($json);
        $response = $this->transport->send($method, $path, $this->headers($body !== null), $body);
        if ($response->statusCode >= 400) {
            throw ServerException::fromResponse($response);
        }

        return $response;
    }

    /**
     * Reads committed events.
     */
    public function readEvents(Query|AllEvents|NoEvents $query, ?int $afterSequence, ?int $pageSize): Events
    {
        return new Events($this->pages($query, $afterSequence, $pageSize));
    }

    /**
     * @return \Generator<int, Event>
     */
    private function pages(Query|AllEvents|NoEvents $query, ?int $afterSequence, ?int $pageSize): \Generator
    {
        $body = ['query' => self::query($query)];
        if ($pageSize !== null) {
            $body['limit'] = $pageSize;
        }

        $failures = 0;
        while (true) {
            if ($afterSequence !== null) {
                $body['afterSequence'] = $afterSequence;
            }
            $hasMore = null;
            $progress = false;
            try {
                $lines = $this->transport->stream(
                    'QUERY',
                    '/events',
                    $this->headers(true),
                    Json::encode($body),
                );
                foreach ($lines as $line) {
                    $data = Json::decodeObject($line);
                    if (\array_key_exists('hasMore', $data)) {
                        $hasMore = $data['hasMore'] === true;
                        continue;
                    }
                    $event = Event::fromArray($data);
                    $afterSequence = $event->sequence;
                    $progress = true;
                    yield $event;
                }
                if ($hasMore === null) {
                    throw new TransportException('QUERY /events: the page ended without its trailer');
                }
            } catch (TransportException $e) {
                $failures = $progress ? 1 : $failures + 1;
                if ($failures >= self::MAX_RESUME_ATTEMPTS) {
                    throw $e;
                }
                usleep(100_000 * $failures);
                continue;
            }
            if (!$hasMore) {
                return;
            }
            $failures = 0;
        }
    }

    /**
     * Begins a transaction, and returns its txId.
     */
    public function begin(): string
    {
        $data = Json::decodeObject($this->call('POST', '/tx')->body);
        if (!\is_string($data['txId'] ?? null) || $data['txId'] === '') {
            throw new ProtocolException('invalid POST /tx response');
        }

        return $data['txId'];
    }

    /**
     * Reads the events of a decision in transaction $txId: the committed
     * events, then the pending ones.
     *
     * @return \Generator<int, Event|PendingEvent>
     *
     * @throws TransportException when the response ends without its trailer
     */
    public function readTxEvents(string $txId, Query|AllEvents|NoEvents $query): \Generator
    {
        $path = self::txPath($txId) . '/events';
        $lines = $this->transport->stream('QUERY', $path, $this->headers(true), Json::encode(['query' => self::query($query)]));
        foreach ($lines as $line) {
            $data = Json::decodeObject($line);
            if (\array_key_exists('end', $data)) {
                return;
            }
            yield \array_key_exists('sequence', $data) ? Event::fromArray($data) : PendingEvent::fromArray($data);
        }

        throw new TransportException(\sprintf('QUERY %s: the response ended without its trailer', $path));
    }

    /**
     * Writes events in a transaction.
     *
     * @param list<NewEvent> $events
     */
    public function writeTxEvents(string $txId, array $events): AppendResult
    {
        $request = ['events' => array_map(static fn(NewEvent $event): array => $event->toArray(), $events)];
        $data = Json::decodeObject($this->call('POST', self::txPath($txId) . '/events', $request)->body);

        return new AppendResult(Time::parse($data['time'] ?? null));
    }

    public function commit(string $txId): void
    {
        $this->call('POST', self::txPath($txId) . '/commit');
    }

    public function abandon(string $txId): void
    {
        $this->call('DELETE', self::txPath($txId));
    }

    /**
     * Reads a projection, committed outside a transaction, or as
     * transaction $txId sees it. Only a committed projection has a version.
     */
    public function getProjection(?string $txId, string $type, string $id): ?Projection
    {
        $path = '/projections/' . rawurlencode($type) . '/' . rawurlencode($id);
        try {
            $response = $this->call('GET', $txId === null ? $path : self::txPath($txId) . $path);
        } catch (ProjectionNotFoundException) {
            return null;
        }
        if ($txId !== null) {
            return new Projection($type, $id, null, $response->body);
        }
        $version = $response->header(self::VERSION_HEADER);
        if ($version === null) {
            throw new ProtocolException('GET /projections response without a version');
        }

        return new Projection($type, $id, $version, $response->body);
    }

    public function writeTxProjections(string $txId, TxProjectionWrites $writes): void
    {
        $this->call('POST', self::txPath($txId) . '/projections', $writes->toArray());
    }

    public function writeProjections(ProjectionWrites $writes): ProjectionWriteResult
    {
        $data = Json::decodeObject($this->call('POST', '/projections', $writes->toArray())->body);

        return new ProjectionWriteResult(self::versions($data, 'create'), self::versions($data, 'replace'));
    }

    /**
     * Asks for a pause. Returns where the log stands once the pause is in
     * place, or null while transactions are still open.
     */
    public function pause(): ?PausePoint
    {
        $response = $this->call('POST', '/pause');
        if ($response->statusCode === 202) {
            return null;
        }
        $data = Json::decodeObject($response->body);
        if ($response->statusCode !== 200 || !\is_int($data['lastSequence'] ?? null)) {
            throw new ProtocolException('invalid POST /pause response');
        }

        return new PausePoint($data['lastSequence']);
    }

    private static function txPath(string $txId): string
    {
        return '/tx/' . rawurlencode($txId);
    }

    /**
     * The query as the HTTP API spells it.
     *
     * @return non-empty-list<array<string, mixed>>|'all'|'none'
     */
    private static function query(Query|AllEvents|NoEvents $query): array|string
    {
        return match (true) {
            $query instanceof AllEvents => 'all',
            $query instanceof NoEvents => 'none',
            default => $query->toArray(),
        };
    }

    /**
     * @return array<string, string>
     */
    private function headers(bool $json): array
    {
        return $json ? ['Content-Type' => 'application/json'] : [];
    }

    /**
     * @param array<mixed> $projections
     *
     * @return list<string>
     */
    private static function versions(array $projections, string $key): array
    {
        $entries = $projections[$key] ?? [];
        if (!\is_array($entries)) {
            throw new ProtocolException('invalid POST /projections response');
        }
        $versions = [];
        foreach ($entries as $entry) {
            if (!\is_array($entry) || !\is_string($entry['version'] ?? null)) {
                throw new ProtocolException('invalid POST /projections response');
            }
            $versions[] = $entry['version'];
        }

        return $versions;
    }
}

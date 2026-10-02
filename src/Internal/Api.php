<?php

declare(strict_types=1);

namespace TamarackDB\Internal;

use TamarackDB\Event\AppendedEvent;
use TamarackDB\Event\Event;
use TamarackDB\Exception\ProjectionNotFoundException;
use TamarackDB\Exception\ProtocolException;
use TamarackDB\Exception\ServerException;
use TamarackDB\Exception\StoreChangedException;
use TamarackDB\Exception\TransportException;
use TamarackDB\Health;
use TamarackDB\Http\Response;
use TamarackDB\Http\Transport;
use TamarackDB\Query\Query;

/**
 * The HTTP calls of the Client. It knows the wire format, and nothing
 * about transactions.
 *
 * @internal
 */
final class Api
{
    public const string STORE_HEADER = 'X-Tamarackdb-Store';

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
        $response = $this->transport->send($method, $path, self::headers($body !== null), $body);
        if ($response->statusCode >= 400) {
            throw ServerException::fromResponse($response);
        }

        return $response;
    }

    /**
     * Reads the events matching $query after $afterSequence, page after
     * page, and resumes a page that was cut short. Returns the store ID and
     * the Sequence Position of the last event the server returned, or
     * $afterSequence when it returned none.
     *
     * With $store, every page must come from that store; without it, every
     * page must come from the store of the first one. Otherwise the read
     * stops with a StoreChangedException.
     *
     * @return \Generator<int, Event, mixed, array{string, int}>
     */
    public function readEvents(?Query $query, int $afterSequence, ?int $pageSize, ?string $store): \Generator
    {
        $body = ['query' => $query?->toArray() ?? '*'];
        if ($pageSize !== null) {
            $body['limit'] = $pageSize;
        }

        $failures = 0;
        while (true) {
            $body['afterSequence'] = $afterSequence;
            $hasMore = null;
            $progress = false;
            try {
                $response = $this->transport->stream('QUERY', '/events', self::headers(true), Json::encode($body));
                $store = self::checkStore($response->header(self::STORE_HEADER), $store);
                foreach ($response->lines as $line) {
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
                return [$store, $afterSequence];
            }
            $failures = 0;
        }
    }

    /**
     * @param array<string, mixed> $body the POST /write body
     */
    public function write(array $body): WriteResult
    {
        $response = $this->call('POST', '/write', $body);
        $store = $response->header(self::STORE_HEADER);
        if ($store === null || $store === '') {
            throw new ProtocolException('POST /write response without a store ID');
        }
        $data = Json::decodeObject($response->body);
        if (!\is_array($data['events'] ?? []) || !\is_array($data['projections'] ?? [])) {
            throw new ProtocolException('invalid POST /write response');
        }

        $events = [];
        foreach ($data['events'] ?? [] as $event) {
            if (!\is_array($event) || !\is_int($event['sequence'] ?? null)) {
                throw new ProtocolException('invalid POST /write response');
            }
            $events[] = new AppendedEvent($event['sequence'], Time::parse($event['time'] ?? null));
        }
        $projections = $data['projections'] ?? [];

        return new WriteResult($store, $events, self::versions($projections, 'create'), self::versions($projections, 'replace'));
    }

    public function getProjection(string $type, string $id): ?StoredProjection
    {
        try {
            $response = $this->call('GET', self::projectionPath($type) . '/' . rawurlencode($id));
        } catch (ProjectionNotFoundException) {
            return null;
        }
        $version = $response->header(self::VERSION_HEADER);
        if ($version === null || $version === '') {
            throw new ProtocolException('GET /projections response without a version');
        }

        return new StoredProjection($version, $response->body);
    }

    public function deleteProjectionsByType(string $type): void
    {
        $this->call('DELETE', self::projectionPath($type));
    }

    public function deleteAllProjections(): void
    {
        $this->call('DELETE', '/projections');
    }

    public function reset(): void
    {
        $this->call('POST', '/reset');
    }

    public function health(): Health
    {
        $data = Json::decodeObject($this->call('GET', '/health')->body);
        if (!\is_string($data['status'] ?? null) || !\is_string($data['version'] ?? null)) {
            throw new ProtocolException('invalid GET /health response');
        }

        return new Health($data['status'], $data['version']);
    }

    private static function checkStore(?string $actual, ?string $expected): string
    {
        if ($actual === null || $actual === '') {
            throw new ProtocolException('QUERY /events response without a store ID');
        }
        if ($expected !== null && $actual !== $expected) {
            throw new StoreChangedException('the store was reset: Sequence Positions read before mean nothing now');
        }

        return $actual;
    }

    private static function projectionPath(string $type): string
    {
        return '/projections/' . rawurlencode($type);
    }

    /**
     * @return array<string, string>
     */
    private static function headers(bool $json): array
    {
        return $json ? ['Content-Type' => 'application/json'] : [];
    }

    /**
     * @return list<string>
     */
    private static function versions(mixed $projections, string $key): array
    {
        $entries = \is_array($projections) ? $projections[$key] ?? [] : null;
        if (!\is_array($entries)) {
            throw new ProtocolException('invalid POST /write response');
        }
        $versions = [];
        foreach ($entries as $entry) {
            if (!\is_array($entry) || !\is_string($entry['version'] ?? null)) {
                throw new ProtocolException('invalid POST /write response');
            }
            $versions[] = $entry['version'];
        }

        return $versions;
    }
}

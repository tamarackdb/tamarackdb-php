<?php

declare(strict_types=1);

namespace TamarackDB\Internal;

use TamarackDB\Event\AppendCondition;
use TamarackDB\Event\AppendedEvent;
use TamarackDB\Event\Event;
use TamarackDB\Event\NewEvent;
use TamarackDB\Exception\ProjectionNotFoundException;
use TamarackDB\Exception\ProtocolException;
use TamarackDB\Exception\ServerException;
use TamarackDB\Exception\TransportException;
use TamarackDB\Http\Response;
use TamarackDB\Http\Transport;
use TamarackDB\Projection\Projection;
use TamarackDB\Projection\ProjectionWriteResult;
use TamarackDB\Projection\ProjectionWrites;
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
     * How many times in a row a read resumes a page that
     * was cut short, without getting any new event, before giving up.
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
     * @return \Generator<int, Event>
     */
    public function readEvents(?Query $query, ?int $afterSequence, ?int $pageSize): \Generator
    {
        $body = ['query' => $query?->toArray() ?? '*'];
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
                $lines = $this->transport->stream('QUERY', '/events', $this->headers(true), Json::encode($body));
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
     * @param list<NewEvent> $events
     *
     * @return list<AppendedEvent>
     */
    public function appendEvents(array $events, ?AppendCondition $condition): array
    {
        $request = ['events' => array_map(static fn(NewEvent $event): array => $event->toArray(), array_values($events))];
        if ($condition !== null && ($array = $condition->toArray()) !== []) {
            $request['condition'] = $array;
        }
        $data = Json::decodeObject($this->call('POST', '/events', $request)->body);
        if (!\is_array($data['events'] ?? null)) {
            throw new ProtocolException('invalid POST /events response');
        }

        $appended = [];
        foreach ($data['events'] as $event) {
            if (!\is_array($event) || !\is_int($event['sequence'] ?? null)) {
                throw new ProtocolException('invalid POST /events response');
            }
            $appended[] = new AppendedEvent($event['sequence'], Time::parse($event['time'] ?? null));
        }

        return $appended;
    }

    public function getProjection(string $type, string $id): ?Projection
    {
        try {
            $response = $this->call('GET', '/projections/' . rawurlencode($type) . '/' . rawurlencode($id));
        } catch (ProjectionNotFoundException) {
            return null;
        }
        $version = $response->header(self::VERSION_HEADER);
        if ($version === null) {
            throw new ProtocolException('GET /projections response without a version');
        }

        return new Projection($type, $id, $version, $response->body);
    }

    public function writeProjections(ProjectionWrites $writes): ProjectionWriteResult
    {
        $data = Json::decodeObject($this->call('POST', '/projections', $writes->toArray())->body);

        return new ProjectionWriteResult(self::versions($data, 'create'), self::versions($data, 'replace'));
    }

    /**
     * @return array<string, string>
     */
    private function headers(bool $json): array
    {
        return $json ? ['Content-Type' => 'application/json'] : [];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private static function versions(array $data, string $key): array
    {
        $entries = $data[$key] ?? [];
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

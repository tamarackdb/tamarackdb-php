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
use TamarackDB\Middleware\AppendHandler;
use TamarackDB\Middleware\ReadHandler;
use TamarackDB\Middleware\ReadRequest;
use TamarackDB\Projection\Projection;
use TamarackDB\Projection\ProjectionWriteResult;
use TamarackDB\Projection\ProjectionWrites;

/**
 * The HTTP calls shared by Client, without a ticket, and Transaction,
 * with one.
 *
 * @internal
 */
final class Api implements AppendHandler, ReadHandler
{
    public const string TICKET_HEADER = 'X-Tamarackdb-Ticket';

    public const string VERSION_HEADER = 'X-Tamarackdb-Version';

    /**
     * How many times in a row a read without a ticket resumes a page that
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
    public function call(string $method, string $path, ?string $ticket = null, ?array $json = null, ?float $timeout = null): Response
    {
        $body = $json === null ? null : Json::encode($json);
        $response = $this->transport->send($method, $path, $this->headers($ticket, $body !== null), $body, $timeout);
        if ($response->statusCode >= 400) {
            throw ServerException::fromResponse($response);
        }

        return $response;
    }

    /**
     * The innermost layer of the read chain.
     */
    public function readEvents(ReadRequest $request): \Generator
    {
        $ticket = $request->ticket;
        $afterSequence = $request->afterSequence;
        $body = ['query' => $request->query->toJsonValue()];
        if ($request->from !== null || $request->before !== null) {
            $body['time'] = array_filter(
                ['from' => $request->from === null ? null : Time::format($request->from), 'before' => $request->before === null ? null : Time::format($request->before)],
                static fn(?string $bound): bool => $bound !== null,
            );
        }
        if ($request->pageSize !== null) {
            $body['limit'] = $request->pageSize;
        }

        $failures = 0;
        while (true) {
            if ($afterSequence !== null) {
                $body['afterSequence'] = $afterSequence;
            }
            $hasMore = null;
            $progress = false;
            try {
                $lines = $this->transport->stream('QUERY', '/events', $this->headers($ticket, true), Json::encode($body), $ticket !== null);
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
                // With a ticket, a page cut short means the transaction was
                // rolled back: there's nothing to resume.
                $failures = $progress ? 1 : $failures + 1;
                if ($ticket !== null || $failures >= self::MAX_RESUME_ATTEMPTS) {
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
     * The innermost layer of the append chain.
     */
    public function append(array $events, ?AppendCondition $condition, string $ticket): array
    {
        $request = ['events' => array_map(static fn(NewEvent $event): array => $event->toArray(), array_values($events))];
        if ($condition !== null && ($array = $condition->toArray()) !== []) {
            $request['condition'] = $array;
        }
        $data = Json::decodeObject($this->call('POST', '/events', $ticket, $request)->body);
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

    public function getProjection(?string $ticket, string $type, string $id): ?Projection
    {
        try {
            $response = $this->call('GET', '/projections/' . rawurlencode($type) . '/' . rawurlencode($id), $ticket);
        } catch (ProjectionNotFoundException) {
            return null;
        }
        $version = $response->header(self::VERSION_HEADER);
        if ($version === null) {
            throw new ProtocolException('GET /projections response without a version');
        }

        return new Projection($type, $id, $version, $response->body);
    }

    public function writeProjections(?string $ticket, ProjectionWrites $writes): ProjectionWriteResult
    {
        $data = Json::decodeObject($this->call('POST', '/projections', $ticket, $writes->toArray())->body);

        return new ProjectionWriteResult(self::versions($data, 'create'), self::versions($data, 'replace'));
    }

    /**
     * @return array<string, string>
     */
    private function headers(?string $ticket, bool $json): array
    {
        $headers = [];
        if ($json) {
            $headers['Content-Type'] = 'application/json';
        }
        if ($ticket !== null) {
            $headers[self::TICKET_HEADER] = $ticket;
        }

        return $headers;
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

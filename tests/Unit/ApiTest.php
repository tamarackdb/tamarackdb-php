<?php

declare(strict_types=1);

namespace TamarackDB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TamarackDB\Event\Event;
use TamarackDB\Exception\ConcurrencyException;
use TamarackDB\Exception\InvalidRequestException;
use TamarackDB\Exception\ProtocolException;
use TamarackDB\Exception\StoreChangedException;
use TamarackDB\Exception\TransportException;
use TamarackDB\Exception\WriteQueueFullException;
use TamarackDB\Http\Response;
use TamarackDB\Internal\Api;
use TamarackDB\Query\EventType;
use TamarackDB\Query\Query;

final class ApiTest extends TestCase
{
    private const string OTHER_STORE = '7c9e6679-7425-40de-944b-e07fc1f90ae7';

    private FakeTransport $transport;

    private Api $api;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->api = new Api($this->transport);
    }

    public function testReadSendsTheQuery(): void
    {
        $this->transport->push(Responses::page([], false));

        $this->drain($this->api->readEvents(new Query(EventType::in('user-created')), 5, 10, null));

        self::assertSame('QUERY', $this->transport->requests[0]['method']);
        self::assertSame('/events', $this->transport->requests[0]['path']);
        self::assertSame(['Content-Type' => 'application/json'], $this->transport->requests[0]['headers']);
        self::assertSame(['query' => [['types' => ['user-created']]], 'limit' => 10, 'afterSequence' => 5], $this->transport->body(0));
    }

    public function testReadOfEveryEvent(): void
    {
        $this->transport->push(Responses::page([], false));

        $this->drain($this->api->readEvents(null, 0, null, null));

        self::assertSame(['query' => '*', 'afterSequence' => 0], $this->transport->body(0));
    }

    public function testReadFollowsThePages(): void
    {
        $this->transport->push(Responses::page([1, 2], true), Responses::page([3], false));

        [$sequences, $end] = $this->drain($this->api->readEvents(null, 0, 2, null));

        self::assertSame([1, 2, 3], $sequences);
        self::assertSame([Responses::STORE, 3], $end);
        self::assertSame(2, $this->transport->body(1)['afterSequence']);
    }

    public function testReadWithNothingNewEndsWhereItStarted(): void
    {
        $this->transport->push(Responses::page([], false));

        [$sequences, $end] = $this->drain($this->api->readEvents(null, 42, null, null));

        self::assertSame([], $sequences);
        self::assertSame([Responses::STORE, 42], $end);
    }

    public function testReadOfAnotherStoreThanTheGivenOne(): void
    {
        $this->transport->push(Responses::page([1], false, self::OTHER_STORE));

        $this->expectException(StoreChangedException::class);

        $this->drain($this->api->readEvents(null, 0, null, Responses::STORE));
    }

    public function testReadWhoseStoreChangesBetweenPages(): void
    {
        $this->transport->push(Responses::page([1], true), Responses::page([2], false, self::OTHER_STORE));

        $this->expectException(StoreChangedException::class);

        $this->drain($this->api->readEvents(null, 0, 1, null));
    }

    public function testReadWithoutAStoreId(): void
    {
        $this->transport->push(new Response(200, [], Responses::trailer(false) . "\n"));

        $this->expectException(ProtocolException::class);

        $this->drain($this->api->readEvents(null, 0, null, null));
    }

    public function testReadResumesACutPage(): void
    {
        $this->transport->push(
            new CutStream([Responses::eventLine(1), Responses::eventLine(2)]),
            Responses::page([3], false),
        );

        [$sequences, $end] = $this->drain($this->api->readEvents(null, 0, null, null));

        self::assertSame([1, 2, 3], $sequences);
        self::assertSame([Responses::STORE, 3], $end);
        self::assertSame(2, $this->transport->body(1)['afterSequence']);
    }

    public function testReadResumesAPageWithoutItsTrailer(): void
    {
        $this->transport->push(
            new Response(200, ['x-tamarackdb-store' => Responses::STORE], Responses::eventLine(1) . "\n"),
            Responses::page([2], false),
        );

        [$sequences] = $this->drain($this->api->readEvents(null, 0, null, null));

        self::assertSame([1, 2], $sequences);
    }

    public function testReadChecksTheStoreOfAResumedPage(): void
    {
        $this->transport->push(
            new CutStream([Responses::eventLine(1)]),
            Responses::page([2], false, self::OTHER_STORE),
        );

        $this->expectException(StoreChangedException::class);

        $this->drain($this->api->readEvents(null, 0, null, null));
    }

    public function testReadGivesUpAfterThreeCutsWithoutProgress(): void
    {
        $this->transport->push(new CutStream([]), new CutStream([]), new CutStream([]));

        try {
            $this->drain($this->api->readEvents(null, 0, null, null));
            self::fail('expected a TransportException');
        } catch (TransportException) {
            self::assertCount(3, $this->transport->requests);
        }
    }

    public function testReadStopsOnAServerError(): void
    {
        $this->transport->push(Responses::error(400, 'InvalidRequest', 'bad query'));

        $this->expectException(InvalidRequestException::class);

        $this->drain($this->api->readEvents(null, 0, null, null));
    }

    public function testWrite(): void
    {
        $this->transport->push(new Response(200, ['x-tamarackdb-store' => Responses::STORE], json_encode([
            'events' => [
                ['sequence' => 7, 'time' => '2026-09-01T14:23:05.123456Z'],
                ['sequence' => 8, 'time' => '2026-09-01T14:23:05.123456Z'],
            ],
            'projections' => ['create' => [['version' => 'v1']], 'replace' => [['version' => 'v2'], ['version' => 'v3']]],
        ], JSON_THROW_ON_ERROR)));

        $result = $this->api->write(['events' => []]);

        self::assertSame('POST', $this->transport->requests[0]['method']);
        self::assertSame('/write', $this->transport->requests[0]['path']);
        self::assertSame(['events' => []], $this->transport->body(0));
        self::assertSame(Responses::STORE, $result->store);
        self::assertSame([7, 8], array_map(static fn($event): int => $event->sequence, $result->events));
        self::assertEquals(new \DateTimeImmutable('2026-09-01T14:23:05.123456Z'), $result->events[0]->time);
        self::assertSame(['v1'], $result->createVersions);
        self::assertSame(['v2', 'v3'], $result->replaceVersions);
    }

    public function testWriteWithoutAStoreId(): void
    {
        $this->transport->push(Responses::json(['events' => [], 'projections' => ['create' => [], 'replace' => []]]));

        $this->expectException(ProtocolException::class);

        $this->api->write([]);
    }

    public function testWriteConflict(): void
    {
        $this->transport->push(Responses::error(409, 'ConcurrencyException', 'conditions[0] no longer holds'));

        $this->expectException(ConcurrencyException::class);

        $this->api->write([]);
    }

    public function testWriteQueueFull(): void
    {
        $this->transport->push(Responses::error(503, 'WriteQueueFull'));

        $this->expectException(WriteQueueFullException::class);

        $this->api->write([]);
    }

    public function testGetProjection(): void
    {
        $this->transport->push(new Response(200, ['x-tamarackdb-version' => 'v1'], '{"name":"Ada"}'));

        $projection = $this->api->getProjection('user/profile', 'a b');

        self::assertSame('/projections/user%2Fprofile/a%20b', $this->transport->requests[0]['path']);
        self::assertNotNull($projection);
        self::assertSame('v1', $projection->version);
        self::assertSame('{"name":"Ada"}', $projection->payload);
    }

    public function testGetMissingProjection(): void
    {
        $this->transport->push(Responses::error(404, 'ProjectionNotFound'));

        self::assertNull($this->api->getProjection('user-profile', '1'));
    }

    public function testGetProjectionWithoutAVersion(): void
    {
        $this->transport->push(new Response(200, [], 'payload'));

        $this->expectException(ProtocolException::class);

        $this->api->getProjection('user-profile', '1');
    }

    public function testBulkDeletesAndReset(): void
    {
        $this->transport->push(Responses::noContent(), Responses::noContent(), Responses::noContent());

        $this->api->deleteProjectionsByType('user/profile');
        $this->api->deleteAllProjections();
        $this->api->reset();

        self::assertSame(
            [['DELETE', '/projections/user%2Fprofile'], ['DELETE', '/projections'], ['POST', '/reset']],
            array_map(static fn(array $request): array => [$request['method'], $request['path']], $this->transport->requests),
        );
    }

    public function testHealth(): void
    {
        $this->transport->push(Responses::json(['status' => 'ok', 'version' => 'v0.26.0']));

        $health = $this->api->health();

        self::assertSame('ok', $health->status);
        self::assertSame('v0.26.0', $health->version);
    }

    /**
     * @param \Generator<int, Event, mixed, array{string, int}> $events
     *
     * @return array{list<int>, array{string, int}}
     */
    private function drain(\Generator $events): array
    {
        $sequences = [];
        foreach ($events as $event) {
            $sequences[] = $event->sequence;
        }

        return [$sequences, $events->getReturn()];
    }
}

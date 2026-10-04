<?php

declare(strict_types=1);

namespace TamarackDB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TamarackDB\Client;
use TamarackDB\Event\Event;
use TamarackDB\Event\NewEvent;
use TamarackDB\Event\PendingEvent;
use TamarackDB\Exception\ConcurrencyException;
use TamarackDB\Exception\InvalidArgumentException;
use TamarackDB\Exception\InvalidRequestException;
use TamarackDB\Exception\NoActiveTransactionException;
use TamarackDB\Exception\ProtocolException;
use TamarackDB\Exception\StoreChangedException;
use TamarackDB\Exception\TransactionAlreadyActiveException;
use TamarackDB\Exception\TransactionNotFoundException;
use TamarackDB\Exception\TransportException;
use TamarackDB\Http\Response;
use TamarackDB\Projection\ProjectionWrites;
use TamarackDB\Query\AllEvents;
use TamarackDB\Query\EventType;
use TamarackDB\Query\Identifier;
use TamarackDB\Query\NoEvents;
use TamarackDB\Query\Query;

final class ClientTest extends TestCase
{
    private const string TX = '7d1e4b2a-3c5f-4e6d-9a8b-0c1d2e3f4a5b';

    private FakeTransport $transport;

    private Client $client;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->client = new Client($this->transport);
    }

    public function testBeginTransaction(): void
    {
        $this->transport->push(Responses::json(['txId' => self::TX]));

        $this->client->beginTransaction();

        self::assertTrue($this->client->inTransaction());
        self::assertSame('POST', $this->transport->requests[0]['method']);
        self::assertSame('/tx', $this->transport->requests[0]['path']);
    }

    public function testBeginTransactionTwice(): void
    {
        $this->begin();

        $this->expectException(TransactionAlreadyActiveException::class);
        $this->client->beginTransaction();
    }

    public function testCommit(): void
    {
        $this->begin(Responses::noContent());

        $this->client->commit();

        self::assertFalse($this->client->inTransaction());
        self::assertSame(['POST', '/tx/' . self::TX . '/commit'], $this->request(1));
    }

    public function testACommitErrorEndsTheTransaction(): void
    {
        $this->begin(Responses::error(409, 'ConcurrencyException', 'conditions[0] no longer holds'));

        try {
            $this->client->commit();
            self::fail('expected a ConcurrencyException');
        } catch (ConcurrencyException) {
        }

        self::assertFalse($this->client->inTransaction());
    }

    public function testACommitTransportFailureEndsTheTransaction(): void
    {
        $this->begin(new TransportException('connection dropped'));

        try {
            $this->client->commit();
            self::fail('expected a TransportException');
        } catch (TransportException) {
        }

        self::assertFalse($this->client->inTransaction());
    }

    public function testRollback(): void
    {
        $this->begin(Responses::noContent());

        $this->client->rollback();

        self::assertFalse($this->client->inTransaction());
        self::assertSame(['DELETE', '/tx/' . self::TX], $this->request(1));
    }

    public function testRollbackWithoutATransaction(): void
    {
        $this->client->rollback();

        self::assertSame([], $this->transport->requests);
    }

    public function testRollbackNeverThrows(): void
    {
        $this->begin(new TransportException('refused'));

        $this->client->rollback();

        self::assertFalse($this->client->inTransaction());
    }

    public function testCommitWithoutATransaction(): void
    {
        $this->expectException(NoActiveTransactionException::class);
        $this->client->commit();
    }

    public function testAppendWithoutATransaction(): void
    {
        try {
            $this->client->appendEvents([new NewEvent('a')]);
            self::fail('expected a NoActiveTransactionException');
        } catch (NoActiveTransactionException) {
        }

        self::assertSame([], $this->transport->requests);
    }

    public function testReadEventsFollowsPages(): void
    {
        $this->transport->push(Responses::page([1, 2], true), Responses::page([3], false));

        $events = iterator_to_array($this->client->readEvents(new AllEvents(), pageSize: 2), false);

        self::assertSame([1, 2, 3], array_map(self::sequence(...), $events));
        self::assertSame(['query' => 'all', 'limit' => 2], $this->transport->body(0));
        self::assertSame(['query' => 'all', 'limit' => 2, 'afterSequence' => 2], $this->transport->body(1));
        self::assertSame('QUERY', $this->transport->requests[0]['method']);
        self::assertSame('/events', $this->transport->requests[0]['path']);
    }

    public function testReadEventsParsesEvents(): void
    {
        $this->transport->push(Responses::page([7], false));

        $event = iterator_to_array($this->client->readEvents(new AllEvents()), false)[0];

        self::assertInstanceOf(Event::class, $event);
        self::assertSame(7, $event->sequence);
        self::assertSame('2026-09-01T14:23:05.123456+00:00', $event->time->format('Y-m-d\TH:i:s.uP'));
        self::assertSame('user-created', $event->type);
        self::assertSame(['userId' => '7', 'tag' => ['a', 'b']], $event->identifiers);
        self::assertSame(['tenantId' => 'acme'], $event->metadata);
        self::assertSame('payload-7', $event->payload);
    }

    public function testReadEventsSendsFilters(): void
    {
        $this->transport->push(Responses::page([], false));

        iterator_to_array($this->client->readEvents(
            new Query(EventType::in('user-created')),
            afterSequence: 10,
        ));

        self::assertSame([
            'query' => [['types' => ['user-created']]],
            'afterSequence' => 10,
        ], $this->transport->body(0));
    }

    public function testReadNoEvents(): void
    {
        $this->transport->push(Responses::page([], false));

        self::assertSame([], iterator_to_array($this->client->readEvents(new NoEvents()), false));
        self::assertSame(['query' => 'none'], $this->transport->body(0));
    }

    public function testReadEventsGivesTheStoreId(): void
    {
        $this->transport->push(Responses::page([1], false));

        $events = $this->client->readEvents(new AllEvents());
        self::assertNull($events->storeId());
        iterator_to_array($events, false);

        self::assertSame(Responses::STORE, $events->storeId());
    }

    public function testReadEventsFromTheGivenStore(): void
    {
        $this->transport->push(Responses::page([4], false));

        $events = $this->client->readEvents(new AllEvents(), afterSequence: 3, storeId: Responses::STORE);

        self::assertCount(1, iterator_to_array($events, false));
        self::assertSame(Responses::STORE, $events->storeId());
    }

    public function testReadEventsFromAnotherStore(): void
    {
        $this->transport->push(Responses::page([1], false, 'other-store'));

        $this->expectException(StoreChangedException::class);
        iterator_to_array($this->client->readEvents(new AllEvents(), afterSequence: 3, storeId: Responses::STORE));
    }

    public function testReadEventsWhenTheStoreChangesBetweenPages(): void
    {
        $this->transport->push(Responses::page([1, 2], true), Responses::page([1], false, 'other-store'));

        $seen = [];
        try {
            foreach ($this->client->readEvents(new AllEvents(), pageSize: 2) as $event) {
                $seen[] = self::sequence($event);
            }
            self::fail('expected a StoreChangedException');
        } catch (StoreChangedException) {
        }

        self::assertSame([1, 2], $seen);
    }

    public function testReadEventsWithoutAStoreId(): void
    {
        $this->transport->push(new Response(200, [], Responses::eventLine(1) . "\n"));

        $this->expectException(ProtocolException::class);
        iterator_to_array($this->client->readEvents(new AllEvents()));
    }

    public function testReadEventsResumesAPageWithoutTrailer(): void
    {
        $this->transport->push(
            new Response(200, ['x-tamarackdb-store' => Responses::STORE], Responses::eventLine(1) . "\n" . Responses::eventLine(2) . "\n"),
            Responses::page([3], false),
        );

        $events = iterator_to_array($this->client->readEvents(new AllEvents()), false);

        self::assertSame([1, 2, 3], array_map(self::sequence(...), $events));
        self::assertSame(2, $this->transport->body(1)['afterSequence']);
    }

    public function testReadEventsResumesADroppedConnection(): void
    {
        $this->transport->push(
            new CutStream([Responses::eventLine(1)]),
            new TransportException('refused'),
            Responses::page([2], false),
        );

        $events = iterator_to_array($this->client->readEvents(new AllEvents()), false);

        self::assertSame([1, 2], array_map(self::sequence(...), $events));
        self::assertSame(1, $this->transport->body(1)['afterSequence']);
        self::assertSame(1, $this->transport->body(2)['afterSequence']);
    }

    public function testReadEventsGivesUpAfterRepeatedFailures(): void
    {
        $this->transport->push(
            new TransportException('refused'),
            new TransportException('refused'),
            new TransportException('refused'),
        );

        $this->expectException(TransportException::class);
        iterator_to_array($this->client->readEvents(new AllEvents()));
    }

    public function testReadEventsRejectsGarbage(): void
    {
        $this->transport->push(new Response(200, ['x-tamarackdb-store' => Responses::STORE], "not json\n"));

        $this->expectException(ProtocolException::class);
        iterator_to_array($this->client->readEvents(new AllEvents()));
    }

    public function testGetProjection(): void
    {
        $this->transport->push(new Response(200, ['x-tamarackdb-version' => 'v1'], '{"name":"Ada"}'));

        $projection = $this->client->getProjection('user profile', 'a/b');

        self::assertNotNull($projection);
        self::assertSame('user profile', $projection->type);
        self::assertSame('a/b', $projection->id);
        self::assertSame('v1', $projection->version);
        self::assertSame('{"name":"Ada"}', $projection->payload);
        self::assertSame('/projections/user%20profile/a%2Fb', $this->transport->requests[0]['path']);
    }

    public function testGetMissingProjection(): void
    {
        $this->transport->push(Responses::error(404, 'ProjectionNotFound'));

        self::assertNull($this->client->getProjection('user-profile', '123'));
    }

    public function testWriteProjections(): void
    {
        $this->transport->push(Responses::json([
            'events' => [],
            'projections' => ['create' => [['version' => 'c1']], 'replace' => [['version' => 'r1']]],
        ]));

        $result = $this->client->writeProjections(
            new ProjectionWrites()
                ->create('a', '1', 'x')
                ->replace('a', '2', 'v2', 'y')
                ->delete('a', '3', 'v3'),
        );

        self::assertSame(['c1'], $result->createVersions);
        self::assertSame(['r1'], $result->replaceVersions);
        self::assertSame(['projections' => [
            'create' => [['type' => 'a', 'id' => '1', 'payload' => 'x']],
            'replace' => [['type' => 'a', 'id' => '2', 'version' => 'v2', 'payload' => 'y']],
            'delete' => [['type' => 'a', 'id' => '3', 'version' => 'v3']],
        ]], $this->transport->body(0));
    }

    public function testProjectionWritesRejectARepeatedKey(): void
    {
        $this->expectExceptionMessage('delete[0] has the same type and id as create[0]');
        new ProjectionWrites()->create('a', '1', 'x')->delete('a', '1', 'v');
    }

    public function testRebuildCalls(): void
    {
        $this->transport->push(
            Responses::noContent(),
            Responses::noContent(),
            Responses::json(['events' => [], 'projections' => ['create' => [['version' => 'c1']], 'replace' => []]]),
        );

        $this->client->deleteProjectionsByType('user/profile');
        $this->client->deleteAllProjections();
        $this->client->writeProjections(new ProjectionWrites()->create('a', '1', 'x'));

        self::assertSame(
            [['DELETE', '/projections/user%2Fprofile'], ['DELETE', '/projections'], ['POST', '/write']],
            array_map(static fn(array $r): array => [$r['method'], $r['path']], $this->transport->requests),
        );
    }

    public function testHealth(): void
    {
        $this->transport->push(Responses::json(['status' => 'ok', 'version' => 'v0.25.0']));

        $health = $this->client->health();

        self::assertSame('ok', $health->status);
        self::assertSame('v0.25.0', $health->version);
    }

    public function testAppend(): void
    {
        $this->begin(Responses::json(['time' => '2026-10-03T21:11:07.554310Z']));

        $time = $this->client->appendEvents([new NewEvent('user-renamed', ['userId' => '123'], payload: 'x')]);

        self::assertSame('2026-10-03T21:11:07.554310+00:00', $time->format('Y-m-d\TH:i:s.uP'));
        self::assertSame(['POST', '/tx/' . self::TX . '/events'], $this->request(1));
        self::assertSame('application/json', $this->transport->requests[1]['headers']['Content-Type']);
        self::assertSame(
            ['events' => [['type' => 'user-renamed', 'identifiers' => ['userId' => '123'], 'payload' => 'x']]],
            $this->transport->body(1),
        );
    }

    public function testAppendNoEvent(): void
    {
        $this->begin(Responses::json(['time' => '2026-10-03T21:11:07.554310Z']));

        $this->client->appendEvents([]);

        self::assertSame(['events' => []], $this->transport->body(1));
    }

    public function testATransactionRead(): void
    {
        $this->begin(Responses::txRead([Responses::eventLine(3), self::pendingLine()]));

        $events = iterator_to_array($this->client->readEvents(new Query(Identifier::is('userId', '3'))), false);

        self::assertCount(2, $events);
        self::assertInstanceOf(Event::class, $events[0]);
        self::assertSame(3, $events[0]->sequence);
        self::assertInstanceOf(PendingEvent::class, $events[1]);
        self::assertSame('2026-10-03T21:11:05.123456+00:00', $events[1]->time->format('Y-m-d\TH:i:s.uP'));
        self::assertSame('seat-reserved', $events[1]->type);
        self::assertSame(['showId' => 's1'], $events[1]->identifiers);
        self::assertSame('{}', $events[1]->payload);
        self::assertSame(['QUERY', '/tx/' . self::TX . '/events'], $this->request(1));
        self::assertSame(['query' => [['identifiers' => [['name' => 'userId', 'value' => '3']]]]], $this->transport->body(1));
        self::assertTrue($this->client->inTransaction());
    }

    public function testATransactionReadIsSentRightAway(): void
    {
        $this->begin(Responses::txRead([]));

        $events = $this->client->readEvents(new NoEvents());

        self::assertCount(2, $this->transport->requests);
        self::assertSame(['query' => 'none'], $this->transport->body(1));
        self::assertSame([], iterator_to_array($events, false));
        self::assertNull($events->storeId());
    }

    public function testATransactionReadTakesNoPosition(): void
    {
        $this->begin();

        $this->expectException(InvalidArgumentException::class);
        $this->client->readEvents(new AllEvents(), afterSequence: 3);
    }

    public function testATransactionReadCutShortAbandonsTheTransaction(): void
    {
        $this->begin(new CutStream([Responses::eventLine(1), Responses::eventLine(2)], []), Responses::noContent());

        try {
            $this->client->readEvents(new AllEvents());
            self::fail('expected a TransportException');
        } catch (TransportException) {
        }

        self::assertFalse($this->client->inTransaction());
        self::assertSame(['DELETE', '/tx/' . self::TX], $this->request(2));
    }

    public function testATransactionReadWithoutItsTrailer(): void
    {
        $this->begin(new Response(200, [], Responses::eventLine(1) . "\n"), Responses::noContent());

        try {
            $this->client->readEvents(new AllEvents());
            self::fail('expected a TransportException');
        } catch (TransportException) {
        }

        self::assertFalse($this->client->inTransaction());
        self::assertSame(['DELETE', '/tx/' . self::TX], $this->request(2));
    }

    public function testATransactionReadErrorEndsTheTransaction(): void
    {
        $this->begin(Responses::error(400, 'InvalidRequest', 'a read while a condition is open'));

        try {
            $this->client->readEvents(new AllEvents());
            self::fail('expected an InvalidRequestException');
        } catch (InvalidRequestException) {
        }

        self::assertFalse($this->client->inTransaction());
        self::assertCount(2, $this->transport->requests);
    }

    public function testAServerErrorEndsTheTransaction(): void
    {
        $this->begin(Responses::error(404, 'TransactionNotFound'));

        try {
            $this->client->appendEvents([new NewEvent('a')]);
            self::fail('expected a TransactionNotFoundException');
        } catch (TransactionNotFoundException) {
        }

        self::assertFalse($this->client->inTransaction());
        $this->expectException(NoActiveTransactionException::class);
        $this->client->commit();
    }

    public function testATransportFailureKeepsTheTransaction(): void
    {
        $this->begin(new TransportException('refused'));

        try {
            $this->client->appendEvents([new NewEvent('a')]);
            self::fail('expected a TransportException');
        } catch (TransportException) {
        }

        self::assertTrue($this->client->inTransaction());
    }

    public function testAMissingProjectionKeepsTheTransaction(): void
    {
        $this->begin(Responses::error(404, 'ProjectionNotFound'));

        self::assertNull($this->client->getProjection('a', '1'));
        self::assertTrue($this->client->inTransaction());
    }

    public function testWriteProjectionsInsideATransaction(): void
    {
        $this->begin();

        $this->expectException(TransactionAlreadyActiveException::class);
        $this->client->writeProjections(new ProjectionWrites()->create('a', '1', 'x'));
    }

    public function testResetForgetsTheTransaction(): void
    {
        $this->begin(Responses::noContent());

        $this->client->reset();

        self::assertFalse($this->client->inTransaction());
    }

    private function begin(Response|\Throwable|CutStream ...$then): void
    {
        $this->transport->push(Responses::json(['txId' => self::TX]), ...$then);
        $this->client->beginTransaction();
    }

    /**
     * @return array{string, string}
     */
    private function request(int $index): array
    {
        return [$this->transport->requests[$index]['method'], $this->transport->requests[$index]['path']];
    }

    private static function sequence(Event|PendingEvent $event): int
    {
        self::assertInstanceOf(Event::class, $event);

        return $event->sequence;
    }

    private static function pendingLine(): string
    {
        return json_encode([
            'time' => '2026-10-03T21:11:05.123456Z',
            'type' => 'seat-reserved',
            'identifiers' => ['showId' => 's1'],
            'metadata' => [],
            'payload' => '{}',
        ], JSON_THROW_ON_ERROR);
    }
}

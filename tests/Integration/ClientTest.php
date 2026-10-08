<?php

declare(strict_types=1);

namespace TamarackDB\Tests\Integration;

use PHPUnit\Framework\TestCase;
use TamarackDB\Client;
use TamarackDB\Event\Event;
use TamarackDB\Event\NewEvent;
use TamarackDB\Exception\ConcurrencyException;
use TamarackDB\Exception\InvalidRequestException;
use TamarackDB\Exception\NotPausedException;
use TamarackDB\Exception\PausedException;
use TamarackDB\Exception\StoreChangedException;
use TamarackDB\Projection\ProjectionWrites;
use TamarackDB\Query\AllEvents;
use TamarackDB\Query\EventType;
use TamarackDB\Query\Identifier;
use TamarackDB\Query\NoEvents;
use TamarackDB\Query\Query;

/**
 * What a Client does outside any transaction.
 */
final class ClientTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        $this->client = TestServer::get($this)->client();
        TestServer::reset($this->client);
    }

    public function testHealth(): void
    {
        $health = $this->client->health();

        self::assertSame('ok', $health->status);
        self::assertFalse($health->paused);
        self::assertSame('v0.31.0', $health->version);
    }

    public function testPause(): void
    {
        $this->appendUsers(2);

        $point = $this->client->pause();
        try {
            self::assertSame(2, $point->lastSequence);
            $events = $this->client->readEvents(new AllEvents());
            iterator_to_array($events);
            self::assertSame($events->storeId(), $point->storeId);
            self::assertTrue($this->client->health()->paused);
            $this->client->writeProjections(new ProjectionWrites()->create('user-profile', '1', '{}'));

            $this->expectException(PausedException::class);
            $this->client->beginTransaction();
        } finally {
            $this->client->resume();
        }
    }

    public function testResumeLetsTransactionsBeginAgain(): void
    {
        $this->client->pause();
        $this->client->resume();
        $this->client->resume();

        self::assertFalse($this->client->health()->paused);
        $this->appendUsers(1);
    }

    public function testResetOutsideAPause(): void
    {
        $this->expectException(NotPausedException::class);
        $this->client->reset();
    }

    public function testOptimize(): void
    {
        $this->expectNotToPerformAssertions();
        $this->client->optimize();
    }

    public function testCommittedEventsAreRead(): void
    {
        $tx = $this->client->beginTransaction();
        $tx->readEvents(new NoEvents());
        $time = $tx->appendEvents([
            new NewEvent('user-created', ['userId' => '123', 'courseId' => ['a', 'b']], ['tenantId' => 'acme'], '{"name":"Ada"}'),
            new NewEvent('user-created', ['userId' => '456']),
        ])->time;
        $tx->commit();

        $events = iterator_to_array($this->client->readEvents(new AllEvents()), false);

        self::assertCount(2, $events);
        self::assertSame(1, $events[0]->sequence);
        self::assertSame('user-created', $events[0]->type);
        self::assertSame(['courseId' => ['a', 'b'], 'userId' => '123'], $events[0]->identifiers);
        self::assertSame(['tenantId' => 'acme'], $events[0]->metadata);
        self::assertSame('{"name":"Ada"}', $events[0]->payload);
        self::assertEquals($time, $events[0]->time);
        self::assertEquals($time, $events[1]->time);
        self::assertSame([], $events[1]->metadata);
        self::assertSame('', $events[1]->payload);
    }

    public function testQueryFilters(): void
    {
        $this->appendUsers(5);

        $byId = iterator_to_array($this->client->readEvents(new Query(Identifier::is('userId', '3'))), false);
        self::assertSame([3], self::sequences($byId));

        $byType = iterator_to_array($this->client->readEvents(
            new Query(EventType::in('user-created'), Identifier::is('userId', '1'))
                ->or(EventType::in('user-created'), Identifier::is('userId', '4')),
        ), false);
        self::assertSame([1, 4], self::sequences($byType));

        $after = iterator_to_array($this->client->readEvents(new AllEvents(), afterSequence: 3), false);
        self::assertSame([4, 5], self::sequences($after));

        self::assertSame([], iterator_to_array($this->client->readEvents(new NoEvents()), false));
    }

    public function testPagesAreFollowed(): void
    {
        $this->appendUsers(7);

        $events = iterator_to_array($this->client->readEvents(new AllEvents(), pageSize: 2), false);

        self::assertSame([1, 2, 3, 4, 5, 6, 7], self::sequences($events));
    }

    public function testAnInvalidRequest(): void
    {
        $this->expectException(InvalidRequestException::class);
        iterator_to_array($this->client->readEvents(new AllEvents(), pageSize: 1_000_000));
    }

    public function testFollowingEventsWithTheStoreId(): void
    {
        $this->appendUsers(2);
        $events = $this->client->readEvents(new AllEvents());
        $last = self::sequences(iterator_to_array($events, false))[1];
        $storeId = $events->storeId();
        self::assertNotNull($storeId);

        $this->appendUsers(1);
        $next = $this->client->readEvents(new AllEvents(), afterSequence: $last, storeId: $storeId);
        self::assertSame([3], self::sequences(iterator_to_array($next, false)));
        self::assertSame($storeId, $next->storeId());

        TestServer::reset($this->client);
        $this->expectException(StoreChangedException::class);
        iterator_to_array($this->client->readEvents(new AllEvents(), afterSequence: $last, storeId: $storeId));
    }

    public function testWriteProjections(): void
    {
        $created = $this->client->writeProjections(new ProjectionWrites()->create('user-profile', 'a/b', '{"v":1}'))->createVersions[0];

        $projection = $this->client->getProjection('user-profile', 'a/b');
        self::assertNotNull($projection);
        self::assertSame($created, $projection->version);
        self::assertSame('{"v":1}', $projection->payload);

        $replaced = $this->client->writeProjections(new ProjectionWrites()->replace('user-profile', 'a/b', $created, ''))->replaceVersions[0];
        self::assertNotSame($created, $replaced);
        self::assertSame('', $this->client->getProjection('user-profile', 'a/b')?->payload);

        try {
            $this->client->writeProjections(new ProjectionWrites()->delete('user-profile', 'a/b', $created));
            self::fail('expected a ConcurrencyException');
        } catch (ConcurrencyException) {
        }

        $this->client->writeProjections(new ProjectionWrites()->delete('user-profile', 'a/b', $replaced));
        self::assertNull($this->client->getProjection('user-profile', 'a/b'));
    }

    public function testRebuild(): void
    {
        $this->client->writeProjections(
            new ProjectionWrites()
                ->create('user-profile', '1', 'x')
                ->create('user-profile', '2', 'x')
                ->create('user-list-entry', '1', 'x'),
        );

        $this->client->deleteProjectionsByType('user-profile');
        self::assertNull($this->client->getProjection('user-profile', '1'));
        self::assertNotNull($this->client->getProjection('user-list-entry', '1'));

        $this->client->deleteAllProjections();
        self::assertNull($this->client->getProjection('user-list-entry', '1'));
    }

    private function appendUsers(int $count): void
    {
        $tx = $this->client->beginTransaction();
        $tx->readEvents(new NoEvents());
        $tx->appendEvents(array_map(
            static fn(int $i): NewEvent => new NewEvent('user-created', ['userId' => (string) $i], payload: 'user ' . $i),
            range(1, $count),
        ));
        $tx->commit();
    }

    /**
     * @param list<Event> $events
     *
     * @return list<int>
     */
    private static function sequences(array $events): array
    {
        return array_map(static fn(Event $e): int => $e->sequence, $events);
    }
}

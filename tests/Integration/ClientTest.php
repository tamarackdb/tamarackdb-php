<?php

declare(strict_types=1);

namespace TamarackDB\Tests\Integration;

use PHPUnit\Framework\TestCase;
use TamarackDB\Client;
use TamarackDB\Event\AppendCondition;
use TamarackDB\Event\Event;
use TamarackDB\Event\NewEvent;
use TamarackDB\Exception\ConcurrencyException;
use TamarackDB\Exception\InvalidRequestException;
use TamarackDB\Exception\NoActiveTransactionException;
use TamarackDB\Exception\TicketNotActiveException;
use TamarackDB\Exception\TimeoutException;
use TamarackDB\Projection\ProjectionWrites;
use TamarackDB\Query\EventType;
use TamarackDB\Query\Identifier;
use TamarackDB\Query\Query;

final class ClientTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        $this->client = TestServer::get($this)->client();
        $this->client->reset();
    }

    public function testHealth(): void
    {
        $health = $this->client->health();

        self::assertSame('ok', $health->status);
    }

    public function testCommittedEventsAreRead(): void
    {
        $this->client->beginTransaction();
        $appended = $this->client->appendEvents([
            new NewEvent('user-created', ['userId' => '123', 'courseId' => ['a', 'b']], ['tenantId' => 'acme'], '{"name":"Ada"}'),
            new NewEvent('user-created', ['userId' => '456']),
        ]);
        $this->client->commit();

        self::assertSame([1, 2], array_map(static fn($e): int => $e->sequence, $appended));

        $events = iterator_to_array($this->client->readEvents(null), false);

        self::assertCount(2, $events);
        self::assertSame(1, $events[0]->sequence);
        self::assertSame('user-created', $events[0]->type);
        self::assertSame(['courseId' => ['a', 'b'], 'userId' => '123'], $events[0]->identifiers);
        self::assertSame(['tenantId' => 'acme'], $events[0]->metadata);
        self::assertSame('{"name":"Ada"}', $events[0]->payload);
        self::assertEquals($appended[0]->time, $events[0]->time);
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

        $after = iterator_to_array($this->client->readEvents(null, afterSequence: 3), false);
        self::assertSame([4, 5], self::sequences($after));

        $none = iterator_to_array($this->client->readEvents(null, before: new \DateTimeImmutable('2000-01-01')), false);
        self::assertSame([], $none);
    }

    public function testPagesAreFollowed(): void
    {
        $this->appendUsers(7);

        $events = iterator_to_array($this->client->readEvents(null, pageSize: 2), false);

        self::assertSame([1, 2, 3, 4, 5, 6, 7], self::sequences($events));
    }

    public function testRollbackDiscardsEvents(): void
    {
        $this->client->beginTransaction();
        $this->client->appendEvents([new NewEvent('user-created')]);
        $this->client->rollback();

        self::assertSame([], iterator_to_array($this->client->readEvents(null), false));
    }

    public function testATicketReadSeesItsOwnEvents(): void
    {
        $this->appendUsers(3);
        $other = TestServer::get($this)->client();
        $this->client->beginTransaction();
        $this->client->appendEvents([new NewEvent('user-created', ['userId' => 'new'])]);

        self::assertSame([1, 2, 3, 4], self::sequences(iterator_to_array($this->client->readEvents(null), false)));
        self::assertSame([1, 2, 3], self::sequences(iterator_to_array($other->readEvents(null), false)));

        $this->client->commit();
    }

    public function testStoppingATicketReadKeepsTheTransaction(): void
    {
        $this->appendUsers(50);
        $this->client->beginTransaction();

        foreach ($this->client->readEvents(null, pageSize: 20) as $event) {
            break;
        }
        $this->client->appendEvents([new NewEvent('user-created')]);
        $this->client->commit();

        self::assertCount(51, iterator_to_array($this->client->readEvents(null), false));
    }

    public function testAFailedConditionRollsBack(): void
    {
        $this->appendUsers(2);
        $query = new Query(Identifier::is('userId', '2'));

        $this->client->beginTransaction();
        try {
            $this->client->appendEvents([new NewEvent('user-renamed', ['userId' => '2'])], new AppendCondition($query, 1));
            self::fail('expected a ConcurrencyException');
        } catch (ConcurrencyException $e) {
            self::assertSame(409, $e->statusCode);
        }
        self::assertFalse($this->client->inTransaction());

        $this->client->beginTransaction();
        $appended = $this->client->appendEvents([new NewEvent('user-renamed', ['userId' => '2'])], new AppendCondition($query, 2));
        $this->client->commit();
        self::assertSame(3, $appended[0]->sequence);
    }

    public function testAnInvalidRequest(): void
    {
        $this->expectException(InvalidRequestException::class);
        iterator_to_array($this->client->readEvents(null, pageSize: 1_000_000));
    }

    public function testProjections(): void
    {
        $id = 'a/b c';

        $this->client->beginTransaction();
        self::assertNull($this->client->getProjection('user-profile', $id));
        self::assertTrue($this->client->inTransaction());
        $created = $this->client->writeProjections(new ProjectionWrites()->create('user-profile', $id, '{"v":1}'))->createVersions[0];
        $this->client->commit();

        $projection = $this->client->getProjection('user-profile', $id);
        self::assertNotNull($projection);
        self::assertSame($created, $projection->version);
        self::assertSame('{"v":1}', $projection->payload);

        $this->client->beginTransaction();
        $current = $this->client->getProjection('user-profile', $id);
        self::assertNotNull($current);
        $replaced = $this->client->writeProjections(new ProjectionWrites()->replace('user-profile', $id, $current->version, ''))->replaceVersions[0];
        $this->client->commit();
        self::assertNotSame($created, $replaced);
        self::assertSame('', $this->client->getProjection('user-profile', $id)?->payload);

        $this->client->beginTransaction();
        try {
            $this->client->writeProjections(new ProjectionWrites()->delete('user-profile', $id, $created));
            self::fail('expected a ConcurrencyException');
        } catch (ConcurrencyException) {
        }
        self::assertFalse($this->client->inTransaction());

        $this->client->beginTransaction();
        $this->client->writeProjections(new ProjectionWrites()->delete('user-profile', $id, $replaced));
        $this->client->commit();
        self::assertNull($this->client->getProjection('user-profile', $id));
    }

    public function testRebuild(): void
    {
        $this->client->beginTransaction();
        $this->client->writeProjections(new ProjectionWrites()->create('a', '1', 'x')->create('b', '1', 'y'));
        $this->client->commit();

        $this->client->deleteProjectionsByType('a');
        self::assertNull($this->client->getProjection('a', '1'));
        self::assertNotNull($this->client->getProjection('b', '1'));

        $this->client->deleteAllProjections();
        $version = $this->client->writeProjections(new ProjectionWrites()->create('a', '1', 'rebuilt'))->createVersions[0];
        $this->client->writeProjections(new ProjectionWrites()->replace('a', '1', $version, 'rebuilt again'));

        self::assertSame('rebuilt again', $this->client->getProjection('a', '1')?->payload);
    }

    public function testAWriteWithoutTransactionWaitsForTheActiveOne(): void
    {
        $this->client->beginTransaction();
        $impatient = TestServer::get($this)->client(timeout: 0.3);

        $started = microtime(true);
        try {
            $impatient->deleteAllProjections();
            self::fail('expected a TimeoutException');
        } catch (TimeoutException) {
            self::assertLessThan(2.0, microtime(true) - $started);
        }

        $this->client->rollback();
        $impatient->deleteAllProjections();
    }

    public function testBeginTransactionGivesUpAfterTheTimeout(): void
    {
        $this->client->beginTransaction();
        $impatient = TestServer::get($this)->client(timeout: 0.3);

        $started = microtime(true);
        try {
            $impatient->beginTransaction();
            self::fail('expected a TimeoutException');
        } catch (TimeoutException) {
            self::assertLessThan(2.0, microtime(true) - $started);
        }

        self::assertFalse($impatient->inTransaction());

        $this->client->rollback();
        $impatient->beginTransaction();
        $impatient->rollback();
    }

    public function testResetEndsTheActiveTransaction(): void
    {
        $this->client->beginTransaction();
        TestServer::get($this)->client()->reset();

        try {
            $this->client->commit();
            self::fail('expected a TicketNotActiveException');
        } catch (TicketNotActiveException) {
        }
        self::assertFalse($this->client->inTransaction());
    }

    public function testAppendWithoutATransaction(): void
    {
        $this->expectException(NoActiveTransactionException::class);
        $this->client->appendEvents([new NewEvent('user-created')]);
    }

    private function appendUsers(int $count): void
    {
        $this->client->beginTransaction();
        $this->client->appendEvents(array_map(
            static fn(int $i): NewEvent => new NewEvent('user-created', ['userId' => (string) $i], payload: 'user ' . $i),
            range(1, $count),
        ));
        $this->client->commit();
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

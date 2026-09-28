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
use TamarackDB\Exception\NotPausedException;
use TamarackDB\Exception\PausedException;
use TamarackDB\Exception\TicketNotActiveException;
use TamarackDB\Exception\TimeoutException;
use TamarackDB\Projection\ProjectionWrites;
use TamarackDB\Query\Query;
use TamarackDB\Query\QueryItem;
use TamarackDB\Transaction;

final class ClientTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        $this->client = TestServer::get($this)->client();
        $this->client->resume();
        $this->client->reset();
    }

    public function testHealth(): void
    {
        $health = $this->client->health();

        self::assertSame('ok', $health->status);
        self::assertFalse($health->paused);
    }

    public function testCommittedEventsAreRead(): void
    {
        $appended = $this->client->transactional(static fn(Transaction $tx): array => $tx->append([
            new NewEvent('user-created', ['userId' => '123', 'courseId' => ['a', 'b']], ['tenantId' => 'acme'], '{"name":"Ada"}'),
            new NewEvent('user-created', ['userId' => '456']),
        ]));

        self::assertSame([1, 2], array_map(static fn($e): int => $e->sequence, $appended));

        $events = iterator_to_array($this->client->readEvents(Query::all()), false);

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

        $byId = iterator_to_array($this->client->readEvents(Query::of(new QueryItem(identifiers: ['userId' => '3']))), false);
        self::assertSame([3], self::sequences($byId));

        $byType = iterator_to_array($this->client->readEvents(Query::of(
            new QueryItem(types: ['user-created'], identifiers: ['userId' => '1']),
            new QueryItem(types: ['user-created'], identifiers: ['userId' => '4']),
        )), false);
        self::assertSame([1, 4], self::sequences($byType));

        $after = iterator_to_array($this->client->readEvents(Query::all(), afterSequence: 3), false);
        self::assertSame([4, 5], self::sequences($after));

        $none = iterator_to_array($this->client->readEvents(Query::all(), before: new \DateTimeImmutable('2000-01-01')), false);
        self::assertSame([], $none);
    }

    public function testPagesAreFollowed(): void
    {
        $this->appendUsers(7);

        $events = iterator_to_array($this->client->readEvents(Query::all(), pageSize: 2), false);

        self::assertSame([1, 2, 3, 4, 5, 6, 7], self::sequences($events));
    }

    public function testRollbackDiscardsEvents(): void
    {
        $tx = $this->client->begin();
        $tx->append([new NewEvent('user-created')]);
        $tx->rollback();

        self::assertSame([], iterator_to_array($this->client->readEvents(Query::all()), false));
    }

    public function testTransactionalRollsBackOnFailure(): void
    {
        try {
            $this->client->transactional(static function (Transaction $tx): never {
                $tx->append([new NewEvent('user-created')]);
                throw new \DomainException('handler failed');
            });
        } catch (\DomainException) {
        }

        self::assertSame([], iterator_to_array($this->client->readEvents(Query::all()), false));
        $this->client->begin()->rollback();
    }

    public function testATicketReadSeesItsOwnEvents(): void
    {
        $this->appendUsers(3);
        $tx = $this->client->begin();
        $tx->append([new NewEvent('user-created', ['userId' => 'new'])]);

        self::assertSame([1, 2, 3, 4], self::sequences(iterator_to_array($tx->readEvents(Query::all()), false)));
        self::assertSame([1, 2, 3], self::sequences(iterator_to_array($this->client->readEvents(Query::all()), false)));

        $tx->commit();
    }

    public function testStoppingATicketReadKeepsTheTransaction(): void
    {
        $this->appendUsers(50);
        $tx = $this->client->begin();

        foreach ($tx->readEvents(Query::all(), pageSize: 20) as $event) {
            break;
        }
        $tx->append([new NewEvent('user-created')]);
        $tx->commit();

        self::assertCount(51, iterator_to_array($this->client->readEvents(Query::all()), false));
    }

    public function testAFailedConditionRollsBack(): void
    {
        $this->appendUsers(2);
        $query = Query::of(new QueryItem(identifiers: ['userId' => '2']));

        $tx = $this->client->begin();
        try {
            $tx->append([new NewEvent('user-renamed', ['userId' => '2'])], new AppendCondition($query, 1));
            self::fail('expected a ConcurrencyException');
        } catch (ConcurrencyException $e) {
            self::assertSame(409, $e->statusCode);
        }
        self::assertFalse($tx->isActive());

        $appended = $this->client->transactional(
            static fn(Transaction $tx): array => $tx->append([new NewEvent('user-renamed', ['userId' => '2'])], new AppendCondition($query, 2)),
        );
        self::assertSame(3, $appended[0]->sequence);
    }

    public function testAnInvalidRequest(): void
    {
        $this->expectException(InvalidRequestException::class);
        iterator_to_array($this->client->readEvents(Query::all(), pageSize: 1_000_000));
    }

    public function testProjections(): void
    {
        $id = 'a/b c';

        $created = $this->client->transactional(function (Transaction $tx) use ($id): string {
            self::assertNull($tx->getProjection('user-profile', $id));
            self::assertTrue($tx->isActive());

            return $tx->writeProjections((new ProjectionWrites())->create('user-profile', $id, '{"v":1}'))->createVersions[0];
        });

        $projection = $this->client->getProjection('user-profile', $id);
        self::assertNotNull($projection);
        self::assertSame($created, $projection->version);
        self::assertSame('{"v":1}', $projection->payload);

        $replaced = $this->client->transactional(static function (Transaction $tx) use ($id): string {
            $current = $tx->getProjection('user-profile', $id);
            self::assertNotNull($current);

            return $tx->writeProjections((new ProjectionWrites())->replace('user-profile', $id, $current->version, ''))->replaceVersions[0];
        });
        self::assertNotSame($created, $replaced);
        self::assertSame('', $this->client->getProjection('user-profile', $id)?->payload);

        try {
            $this->client->transactional(
                static fn(Transaction $tx) => $tx->writeProjections((new ProjectionWrites())->delete('user-profile', $id, $created)),
            );
            self::fail('expected a ConcurrencyException');
        } catch (ConcurrencyException) {
        }

        $this->client->transactional(
            static fn(Transaction $tx) => $tx->writeProjections((new ProjectionWrites())->delete('user-profile', $id, $replaced)),
        );
        self::assertNull($this->client->getProjection('user-profile', $id));
    }

    public function testRebuild(): void
    {
        $this->client->transactional(
            static fn(Transaction $tx) => $tx->writeProjections((new ProjectionWrites())->create('a', '1', 'x')->create('b', '1', 'y')),
        );

        try {
            $this->client->deleteAllProjections();
            self::fail('expected a NotPausedException');
        } catch (NotPausedException) {
        }

        $this->client->pause();
        self::assertTrue($this->client->health()->paused);
        try {
            $this->client->begin();
            self::fail('expected a PausedException');
        } catch (PausedException) {
        }

        $this->client->deleteProjectionsByType('a');
        self::assertNull($this->client->getProjection('a', '1'));
        self::assertNotNull($this->client->getProjection('b', '1'));

        $this->client->deleteAllProjections();
        $version = $this->client->writeProjections((new ProjectionWrites())->create('a', '1', 'rebuilt'))->createVersions[0];
        $this->client->writeProjections((new ProjectionWrites())->replace('a', '1', $version, 'rebuilt again'));
        $this->client->resume();

        self::assertSame('rebuilt again', $this->client->getProjection('a', '1')?->payload);
        self::assertFalse($this->client->health()->paused);
    }

    public function testBeginGivesUpAfterTheQueueTimeout(): void
    {
        $tx = $this->client->begin();
        $impatient = TestServer::get($this)->client(queueTimeout: 0.3);

        $started = microtime(true);
        try {
            $impatient->begin();
            self::fail('expected a TimeoutException');
        } catch (TimeoutException) {
            self::assertLessThan(2.0, microtime(true) - $started);
        }

        $tx->rollback();
        $impatient->begin()->rollback();
    }

    public function testResetEndsTheActiveTransaction(): void
    {
        $tx = $this->client->begin();
        $this->client->reset();

        $this->expectException(TicketNotActiveException::class);
        $tx->commit();
    }

    public function testDebug(): void
    {
        $debug = $this->client->debug();

        self::assertArrayHasKey('write', $debug);
        self::assertArrayHasKey('read', $debug);
    }

    private function appendUsers(int $count): void
    {
        $this->client->transactional(static fn(Transaction $tx): array => $tx->append(array_map(
            static fn(int $i): NewEvent => new NewEvent('user-created', ['userId' => (string) $i], payload: 'user ' . $i),
            range(1, $count),
        )));
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

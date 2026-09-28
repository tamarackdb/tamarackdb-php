<?php

declare(strict_types=1);

namespace TamarackDB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TamarackDB\Client;
use TamarackDB\Event\AppendCondition;
use TamarackDB\Event\Event;
use TamarackDB\Event\NewEvent;
use TamarackDB\Exception\ConcurrencyException;
use TamarackDB\Exception\PausedException;
use TamarackDB\Exception\ProtocolException;
use TamarackDB\Exception\TicketNotActiveException;
use TamarackDB\Exception\TransactionEndedException;
use TamarackDB\Exception\TransportException;
use TamarackDB\Http\Response;
use TamarackDB\Projection\ProjectionWrites;
use TamarackDB\Query\Query;
use TamarackDB\Query\QueryItem;
use TamarackDB\Transaction;

final class ClientTest extends TestCase
{
    private const string TICKET = 'a045ad63-5d4b-4847-8eb9-fbddb4e2d65b';

    private FakeTransport $transport;

    private Client $client;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->client = new Client($this->transport, queueTimeout: 2.5);
    }

    public function testBeginWaitsForTheQueueTimeout(): void
    {
        $this->transport->push(Responses::json(['ticket' => self::TICKET]));

        $transaction = $this->client->begin();

        self::assertSame(self::TICKET, $transaction->ticket);
        self::assertTrue($transaction->isActive());
        self::assertSame('POST', $this->transport->requests[0]['method']);
        self::assertSame('/begin', $this->transport->requests[0]['path']);
        self::assertSame(2.5, $this->transport->requests[0]['timeout']);
    }

    public function testBeginWhilePaused(): void
    {
        $this->transport->push(Responses::error(503, 'Paused'));

        $this->expectException(PausedException::class);
        $this->client->begin();
    }

    public function testReadEventsFollowsPages(): void
    {
        $this->transport->push(Responses::page([1, 2], true), Responses::page([3], false));

        $events = iterator_to_array($this->client->readEvents(Query::all(), pageSize: 2), false);

        self::assertSame([1, 2, 3], array_map(static fn(Event $e): int => $e->sequence, $events));
        self::assertSame(['query' => '*', 'limit' => 2], $this->transport->body(0));
        self::assertSame(['query' => '*', 'limit' => 2, 'afterSequence' => 2], $this->transport->body(1));
        self::assertSame('QUERY', $this->transport->requests[0]['method']);
        self::assertSame('/events', $this->transport->requests[0]['path']);
        self::assertArrayNotHasKey('X-Tamarackdb-Ticket', $this->transport->requests[0]['headers']);
        self::assertFalse($this->transport->requests[0]['drainOnAbort']);
    }

    public function testReadEventsParsesEvents(): void
    {
        $this->transport->push(Responses::page([7], false));

        $event = iterator_to_array($this->client->readEvents(Query::all()), false)[0];

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
            Query::of(new QueryItem(types: ['user-created'])),
            afterSequence: 10,
            from: new \DateTimeImmutable('2026-01-01T01:00:00+01:00'),
            before: new \DateTimeImmutable('2026-02-01T00:00:00.5Z'),
        ));

        self::assertSame([
            'query' => [['types' => ['user-created']]],
            'time' => ['from' => '2026-01-01T00:00:00.000000Z', 'before' => '2026-02-01T00:00:00.500000Z'],
            'afterSequence' => 10,
        ], $this->transport->body(0));
    }

    public function testReadEventsResumesAPageWithoutTrailer(): void
    {
        $this->transport->push(
            new Response(200, [], Responses::eventLine(1) . "\n" . Responses::eventLine(2) . "\n"),
            Responses::page([3], false),
        );

        $events = iterator_to_array($this->client->readEvents(Query::all()), false);

        self::assertSame([1, 2, 3], array_map(static fn(Event $e): int => $e->sequence, $events));
        self::assertSame(2, $this->transport->body(1)['afterSequence']);
    }

    public function testReadEventsResumesADroppedConnection(): void
    {
        $this->transport->push(
            new CutStream([Responses::eventLine(1)]),
            new TransportException('refused'),
            Responses::page([2], false),
        );

        $events = iterator_to_array($this->client->readEvents(Query::all()), false);

        self::assertSame([1, 2], array_map(static fn(Event $e): int => $e->sequence, $events));
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
        iterator_to_array($this->client->readEvents(Query::all()));
    }

    public function testReadEventsRejectsGarbage(): void
    {
        $this->transport->push(new Response(200, [], "not json\n"));

        $this->expectException(ProtocolException::class);
        iterator_to_array($this->client->readEvents(Query::all()));
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
        $this->transport->push(Responses::json(['create' => [['version' => 'c1']], 'replace' => [['version' => 'r1']]]));

        $result = $this->client->writeProjections(
            (new ProjectionWrites())
                ->create('a', '1', 'x')
                ->replace('a', '2', 'v2', 'y')
                ->delete('a', '3', 'v3'),
        );

        self::assertSame(['c1'], $result->createVersions);
        self::assertSame(['r1'], $result->replaceVersions);
        self::assertSame([
            'create' => [['type' => 'a', 'id' => '1', 'payload' => 'x']],
            'replace' => [['type' => 'a', 'id' => '2', 'version' => 'v2', 'payload' => 'y']],
            'delete' => [['type' => 'a', 'id' => '3', 'version' => 'v3']],
        ], $this->transport->body(0));
    }

    public function testProjectionWritesRejectARepeatedKey(): void
    {
        $this->expectExceptionMessage('delete[0] has the same type and id as create[0]');
        (new ProjectionWrites())->create('a', '1', 'x')->delete('a', '1', 'v');
    }

    public function testRebuildCalls(): void
    {
        $this->transport->push(Responses::noContent(), Responses::noContent(), Responses::noContent(), Responses::noContent());

        $this->client->pause();
        $this->client->deleteProjectionsByType('user/profile');
        $this->client->deleteAllProjections();
        $this->client->resume();

        self::assertSame(
            [['POST', '/pause', 2.5], ['DELETE', '/projections/user%2Fprofile', null], ['DELETE', '/projections', null], ['POST', '/resume', null]],
            array_map(static fn(array $r): array => [$r['method'], $r['path'], $r['timeout']], $this->transport->requests),
        );
    }

    public function testHealth(): void
    {
        $this->transport->push(Responses::json(['status' => 'ok', 'version' => 'v0.24.0', 'paused' => true]));

        $health = $this->client->health();

        self::assertSame('ok', $health->status);
        self::assertSame('v0.24.0', $health->version);
        self::assertTrue($health->paused);
    }

    public function testAppend(): void
    {
        $this->transport->push(
            Responses::json(['ticket' => self::TICKET]),
            Responses::json(['events' => [['sequence' => 5, 'time' => '2026-09-01T14:25:00.000000Z']]]),
        );
        $transaction = $this->client->begin();

        $appended = $transaction->append(
            [new NewEvent('user-renamed', ['userId' => '123'], payload: 'x')],
            new AppendCondition(Query::of(new QueryItem(identifiers: ['userId' => '123'])), 4),
        );

        self::assertCount(1, $appended);
        self::assertSame(5, $appended[0]->sequence);
        self::assertSame(self::TICKET, $this->transport->requests[1]['headers']['X-Tamarackdb-Ticket']);
        self::assertSame('application/json', $this->transport->requests[1]['headers']['Content-Type']);
        self::assertSame([
            'events' => [['type' => 'user-renamed', 'identifiers' => ['userId' => '123'], 'payload' => 'x']],
            'condition' => [
                'failIfEventsMatch' => [['identifiers' => [['name' => 'userId', 'value' => '123']]]],
                'afterSequence' => 4,
            ],
        ], $this->transport->body(1));
    }

    public function testAnEmptyConditionIsLeftOut(): void
    {
        $this->transport->push(Responses::json(['ticket' => self::TICKET]), Responses::json(['events' => []]));

        $this->client->begin()->append([], new AppendCondition());

        self::assertSame(['events' => []], $this->transport->body(1));
    }

    public function testATicketReadDrainsOnAbort(): void
    {
        $this->transport->push(Responses::json(['ticket' => self::TICKET]), Responses::page([1, 2], false));
        $transaction = $this->client->begin();

        foreach ($transaction->readEvents(Query::all()) as $event) {
            break;
        }

        self::assertTrue($this->transport->requests[1]['drainOnAbort']);
        self::assertSame(self::TICKET, $this->transport->requests[1]['headers']['X-Tamarackdb-Ticket']);
    }

    public function testATicketReadIsNotResumed(): void
    {
        $this->transport->push(Responses::json(['ticket' => self::TICKET]), new CutStream([Responses::eventLine(1)]));
        $transaction = $this->client->begin();

        $this->expectException(TransportException::class);
        iterator_to_array($transaction->readEvents(Query::all()));
    }

    public function testAServerErrorEndsTheTransaction(): void
    {
        $this->transport->push(Responses::json(['ticket' => self::TICKET]), Responses::error(409, 'ConcurrencyException'));
        $transaction = $this->client->begin();

        try {
            $transaction->append([new NewEvent('a')]);
            self::fail('expected a ConcurrencyException');
        } catch (ConcurrencyException) {
        }

        self::assertFalse($transaction->isActive());
        $this->expectException(TransactionEndedException::class);
        $transaction->commit();
    }

    public function testAMissingProjectionKeepsTheTransaction(): void
    {
        $this->transport->push(Responses::json(['ticket' => self::TICKET]), Responses::error(404, 'ProjectionNotFound'));
        $transaction = $this->client->begin();

        self::assertNull($transaction->getProjection('a', '1'));
        self::assertTrue($transaction->isActive());
    }

    public function testTransactionalCommits(): void
    {
        $this->transport->push(Responses::json(['ticket' => self::TICKET]), Responses::noContent());

        $result = $this->client->transactional(static fn(Transaction $tx): string => 'done');

        self::assertSame('done', $result);
        self::assertSame('/commit', $this->transport->requests[1]['path']);
        self::assertSame(self::TICKET, $this->transport->requests[1]['headers']['X-Tamarackdb-Ticket']);
    }

    public function testTransactionalRollsBackOnFailure(): void
    {
        $this->transport->push(Responses::json(['ticket' => self::TICKET]), Responses::noContent());

        try {
            $this->client->transactional(static function (): never {
                throw new \DomainException('handler failed');
            });
        } catch (\DomainException $e) {
            self::assertSame('handler failed', $e->getMessage());
        }

        self::assertSame('/rollback', $this->transport->requests[1]['path']);
    }

    public function testTransactionalKeepsTheOriginalFailure(): void
    {
        $this->transport->push(Responses::json(['ticket' => self::TICKET]), Responses::error(410, 'TicketNotActive'));

        $this->expectException(\DomainException::class);
        $this->client->transactional(static function (): never {
            throw new \DomainException('handler failed');
        });
    }

    public function testTransactionalSkipsTheRollbackAfterAServerError(): void
    {
        $this->transport->push(Responses::json(['ticket' => self::TICKET]), Responses::error(409, 'ConcurrencyException'));

        try {
            $this->client->transactional(static fn(Transaction $tx): array => $tx->append([new NewEvent('a')]));
            self::fail('expected a ConcurrencyException');
        } catch (ConcurrencyException) {
        }

        self::assertCount(2, $this->transport->requests);
    }

    public function testTransactionalLeavesAnEndedTransaction(): void
    {
        $this->transport->push(Responses::json(['ticket' => self::TICKET]), Responses::noContent());

        $this->client->transactional(static fn(Transaction $tx) => $tx->rollback());

        self::assertCount(2, $this->transport->requests);
        self::assertSame('/rollback', $this->transport->requests[1]['path']);
    }

    public function testCommitOnAnInactiveTicket(): void
    {
        $this->transport->push(Responses::json(['ticket' => self::TICKET]), Responses::error(410, 'TicketNotActive'));

        $this->expectException(TicketNotActiveException::class);
        $this->client->begin()->commit();
    }
}

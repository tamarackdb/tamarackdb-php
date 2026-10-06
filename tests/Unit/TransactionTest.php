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
use TamarackDB\Exception\PausedException;
use TamarackDB\Exception\PayloadTooLargeException;
use TamarackDB\Exception\TransactionAlreadyActiveException;
use TamarackDB\Exception\TransactionBusyException;
use TamarackDB\Exception\TransactionNotFoundException;
use TamarackDB\Exception\TransportException;
use TamarackDB\Http\Response;
use TamarackDB\Query\AllEvents;
use TamarackDB\Query\Identifier;
use TamarackDB\Query\NoEvents;
use TamarackDB\Query\Query;
use TamarackDB\Transaction;

final class TransactionTest extends TestCase
{
    private const string TX = '7d1e4b2a-3c5f-4e6d-9a8b-0c1d2e3f4a5b';

    private FakeTransport $transport;

    private Client $client;

    private Transaction $tx;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->client = new Client($this->transport);
    }

    public function testBeginTransaction(): void
    {
        $this->transport->push(Responses::json(['txId' => self::TX]));

        $tx = $this->client->beginTransaction();

        self::assertSame(self::TX, $tx->id);
        self::assertTrue($tx->isActive());
        self::assertTrue($this->client->inTransaction());
        self::assertSame($tx, $this->client->getTransaction());
        self::assertSame('POST', $this->transport->requests[0]['method']);
        self::assertSame('/tx', $this->transport->requests[0]['path']);
    }

    public function testBeginTransactionTwice(): void
    {
        $this->begin();

        $this->expectException(TransactionAlreadyActiveException::class);
        $this->client->beginTransaction();
    }

    public function testBeginAfterTheLastTransactionEnded(): void
    {
        $this->begin(Responses::noContent(), Responses::json(['txId' => 'second']));
        $this->tx->commit();

        $second = $this->client->beginTransaction();

        self::assertSame('second', $second->id);
        self::assertSame($second, $this->client->getTransaction());
    }

    public function testGetTransactionWithoutOne(): void
    {
        $this->expectException(NoActiveTransactionException::class);
        $this->client->getTransaction();
    }

    public function testCommit(): void
    {
        $this->begin(Responses::noContent());

        $this->tx->commit();

        self::assertFalse($this->tx->isActive());
        self::assertFalse($this->client->inTransaction());
        self::assertSame(['POST', '/tx/' . self::TX . '/commit'], $this->request(1));
    }

    public function testACommitErrorEndsTheTransaction(): void
    {
        $this->begin(Responses::error(409, 'ConcurrencyException', 'conditions[0] no longer holds'));

        try {
            $this->tx->commit();
            self::fail('expected a ConcurrencyException');
        } catch (ConcurrencyException) {
        }

        self::assertFalse($this->client->inTransaction());
    }

    public function testACommitTransportFailureEndsTheTransaction(): void
    {
        $this->begin(new TransportException('connection dropped'));

        try {
            $this->tx->commit();
            self::fail('expected a TransportException');
        } catch (TransportException) {
        }

        self::assertFalse($this->client->inTransaction());
    }

    public function testRollback(): void
    {
        $this->begin(Responses::noContent());

        $this->tx->rollback();

        self::assertFalse($this->client->inTransaction());
        self::assertSame(['DELETE', '/tx/' . self::TX], $this->request(1));
    }

    public function testRollbackWhenTheTransactionIsOver(): void
    {
        $this->begin(Responses::noContent());
        $this->tx->commit();

        $this->tx->rollback();

        self::assertCount(2, $this->transport->requests);
    }

    public function testACallWhenTheTransactionIsOver(): void
    {
        $this->begin(Responses::noContent());
        $this->tx->commit();

        try {
            $this->tx->appendEvents([new NewEvent('a')]);
            self::fail('expected a NoActiveTransactionException');
        } catch (NoActiveTransactionException) {
        }

        self::assertCount(2, $this->transport->requests);
    }

    public function testRollbackNeverThrows(): void
    {
        $this->begin(new TransportException('refused'));

        $this->tx->rollback();

        self::assertFalse($this->client->inTransaction());
    }

    public function testAppend(): void
    {
        $this->begin(Responses::json(['time' => '2026-10-03T21:11:07.554310Z']));

        $result = $this->tx->appendEvents([new NewEvent('user-renamed', ['userId' => '123'], payload: 'x')]);

        self::assertSame('2026-10-03T21:11:07.554310+00:00', $result->time->format('Y-m-d\TH:i:s.uP'));
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

        $this->tx->appendEvents([]);

        self::assertSame(['events' => []], $this->transport->body(1));
    }

    public function testATransactionRead(): void
    {
        $this->begin(Responses::txRead([Responses::eventLine(3), self::pendingLine()]));

        $events = $this->tx->readEvents(new Query(Identifier::is('userId', '3')));

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

    public function testATransactionReadOfNoEvents(): void
    {
        $this->begin(Responses::txRead([]));

        self::assertSame([], $this->tx->readEvents(new NoEvents()));
        self::assertSame(['query' => 'none'], $this->transport->body(1));
    }

    public function testATransactionReadCutShortAbandonsTheTransaction(): void
    {
        $this->begin(new CutStream([Responses::eventLine(1), Responses::eventLine(2)], []), Responses::noContent());

        try {
            $this->tx->readEvents(new AllEvents());
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
            $this->tx->readEvents(new AllEvents());
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
            $this->tx->readEvents(new AllEvents());
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
            $this->tx->appendEvents([new NewEvent('a')]);
            self::fail('expected a TransactionNotFoundException');
        } catch (TransactionNotFoundException) {
        }

        self::assertFalse($this->client->inTransaction());
        $this->expectException(NoActiveTransactionException::class);
        $this->tx->commit();
    }

    public function testATransportFailureKeepsTheTransaction(): void
    {
        $this->begin(new TransportException('refused'));

        try {
            $this->tx->appendEvents([new NewEvent('a')]);
            self::fail('expected a TransportException');
        } catch (TransportException) {
        }

        self::assertTrue($this->client->inTransaction());
    }

    public function testAMissingProjectionKeepsTheTransaction(): void
    {
        $this->begin(Responses::error(404, 'ProjectionNotFound'));

        self::assertNull($this->tx->getProjection('a', '1'));
        self::assertTrue($this->client->inTransaction());
        self::assertSame(['GET', '/tx/' . self::TX . '/projections/a/1'], $this->request(1));
    }

    public function testGetProjectionInATransaction(): void
    {
        $this->begin(new Response(200, [], '{"free":37}'));

        $projection = $this->tx->getProjection('show seats', 's/1');

        self::assertNotNull($projection);
        self::assertNull($projection->version);
        self::assertSame('{"free":37}', $projection->payload);
        self::assertSame(['GET', '/tx/' . self::TX . '/projections/show%20seats/s%2F1'], $this->request(1));
    }

    public function testSaveAProjectionReadFirst(): void
    {
        $this->begin(new Response(200, [], '{"free":37}'), self::txWritten());

        $this->tx->getProjection('show-seats', 's1');
        $this->tx->saveProjection('show-seats', 's1', '{"free":36}');

        self::assertCount(3, $this->transport->requests);
        self::assertSame(['POST', '/tx/' . self::TX . '/projections'], $this->request(2));
        self::assertSame(['upsert' => [['type' => 'show-seats', 'id' => 's1', 'payload' => '{"free":36}']]], $this->transport->body(2));
    }

    public function testSaveAProjectionNotReadReadsItFirst(): void
    {
        $this->begin(Responses::error(404, 'ProjectionNotFound'), self::txWritten(), self::txWritten());

        $this->tx->saveProjection('show-seats', 's1', '{"free":40}');
        $this->tx->saveProjection('show-seats', 's1', '{"free":39}');

        self::assertSame([
            ['GET', '/tx/' . self::TX . '/projections/show-seats/s1'],
            ['POST', '/tx/' . self::TX . '/projections'],
            ['POST', '/tx/' . self::TX . '/projections'],
        ], [$this->request(1), $this->request(2), $this->request(3)]);
    }

    public function testDeleteAProjectionNotReadReadsItFirst(): void
    {
        $this->begin(new Response(200, [], 'x'), self::txWritten());

        $this->tx->deleteProjection('seat-hold', 's1-A6');

        self::assertSame(['GET', '/tx/' . self::TX . '/projections/seat-hold/s1-A6'], $this->request(1));
        self::assertSame(['delete' => [['type' => 'seat-hold', 'id' => 's1-A6']]], $this->transport->body(2));
    }

    public function testProjectionsReadInAnEarlierTransactionAreReadAgain(): void
    {
        $this->begin(new Response(200, [], 'x'), Responses::noContent());
        $this->tx->getProjection('a', '1');
        $this->tx->commit();

        $this->begin(new Response(200, [], 'x'), self::txWritten());
        $this->tx->saveProjection('a', '1', 'y');

        self::assertSame(['GET', '/tx/' . self::TX . '/projections/a/1'], $this->request(4));
        self::assertSame(['POST', '/tx/' . self::TX . '/projections'], $this->request(5));
    }

    public function testAProjectionWriteErrorEndsTheTransaction(): void
    {
        $this->begin(new Response(200, [], 'x'), Responses::error(413, 'PayloadTooLarge'));

        try {
            $this->tx->saveProjection('a', '1', 'y');
            self::fail('expected a PayloadTooLargeException');
        } catch (PayloadTooLargeException) {
        }

        self::assertFalse($this->client->inTransaction());
    }

    public function testSaveAProjectionWithAnEmptyId(): void
    {
        $this->begin();

        try {
            $this->tx->saveProjection('a', '', 'x');
            self::fail('expected an InvalidArgumentException');
        } catch (InvalidArgumentException) {
        }

        self::assertCount(1, $this->transport->requests);
        self::assertTrue($this->client->inTransaction());
    }

    public function testBeginTransactionDuringAPause(): void
    {
        $this->transport->push(Responses::error(503, 'Paused'));

        try {
            $this->client->beginTransaction();
            self::fail('expected a PausedException');
        } catch (PausedException) {
        }

        self::assertFalse($this->client->inTransaction());
    }

    public function testABusyTransactionGoesOn(): void
    {
        $this->begin(Responses::error(409, 'TransactionBusy'), Responses::noContent());

        try {
            $this->tx->appendEvents([new NewEvent('a')]);
            self::fail('expected a TransactionBusyException');
        } catch (TransactionBusyException) {
        }

        self::assertTrue($this->client->inTransaction());
        $this->tx->rollback();
        self::assertSame(['DELETE', '/tx/' . self::TX], $this->request(2));
    }

    public function testABusyCommitKeepsTheTransaction(): void
    {
        $this->begin(Responses::error(409, 'TransactionBusy'), Responses::noContent());

        try {
            $this->tx->commit();
            self::fail('expected a TransactionBusyException');
        } catch (TransactionBusyException) {
        }

        self::assertTrue($this->client->inTransaction());
        $this->tx->commit();
        self::assertFalse($this->client->inTransaction());
    }

    private function begin(Response|\Throwable|CutStream ...$then): void
    {
        $this->transport->push(Responses::json(['txId' => self::TX]), ...$then);
        $this->tx = $this->client->beginTransaction();
    }

    /**
     * @return array{string, string}
     */
    private function request(int $index): array
    {
        return [$this->transport->requests[$index]['method'], $this->transport->requests[$index]['path']];
    }

    private static function txWritten(): Response
    {
        return Responses::json(['time' => '2026-10-03T21:11:07.601877Z']);
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

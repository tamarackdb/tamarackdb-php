<?php

declare(strict_types=1);

namespace TamarackDB\Tests\Integration;

use PHPUnit\Framework\TestCase;
use TamarackDB\Client;
use TamarackDB\Event\Event;
use TamarackDB\Event\NewEvent;
use TamarackDB\Event\PendingEvent;
use TamarackDB\Exception\ConcurrencyException;
use TamarackDB\Exception\InvalidRequestException;
use TamarackDB\Exception\TransactionNotFoundException;
use TamarackDB\Query\AllEvents;
use TamarackDB\Query\EventType;
use TamarackDB\Query\Identifier;
use TamarackDB\Query\NoEvents;
use TamarackDB\Query\Query;
use TamarackDB\Transaction;

final class TransactionTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        $this->client = TestServer::get($this)->client();
        $this->client->reset();
    }

    public function testAReadSeesCommittedThenPendingEvents(): void
    {
        $tx = $this->client->beginTransaction();
        $tx->readEvents(new NoEvents());
        $tx->appendEvents([new NewEvent('seat-reserved', ['showId' => 's1', 'seat' => 'A4'])]);
        $tx->commit();

        $tx = $this->client->beginTransaction();
        $tx->readEvents(new NoEvents());
        $time = $tx->appendEvents([new NewEvent('seat-reserved', ['showId' => 's1', 'seat' => 'A5'], payload: '{}')]);
        $events = $tx->readEvents(new Query(Identifier::is('showId', 's1')));
        $tx->appendEvents([]);

        self::assertCount(2, $events);
        self::assertInstanceOf(Event::class, $events[0]);
        self::assertSame(1, $events[0]->sequence);
        self::assertInstanceOf(PendingEvent::class, $events[1]);
        self::assertSame(['seat' => 'A5', 'showId' => 's1'], $events[1]->identifiers);
        self::assertSame('{}', $events[1]->payload);
        self::assertEquals($time, $events[1]->time);

        $tx->commit();

        $committed = iterator_to_array($this->client->readEvents(new AllEvents(), afterSequence: 1), false);
        self::assertCount(1, $committed);
        self::assertSame(2, $committed[0]->sequence);
        self::assertEquals($time, $committed[0]->time);
    }

    public function testRollbackDiscardsEvents(): void
    {
        $tx = $this->client->beginTransaction();
        $tx->readEvents(new NoEvents());
        $tx->appendEvents([new NewEvent('user-created')]);
        $tx->rollback();

        self::assertFalse($this->client->inTransaction());
        self::assertSame([], iterator_to_array($this->client->readEvents(new AllEvents()), false));
    }

    /**
     * The example of the Transactions page: a customer with 950 points is
     * promoted at 1,000. Two commands add 30 and 40 points at the same time,
     * and each decides not to promote.
     */
    public function testADecisionToWriteNothingIsChecked(): void
    {
        $tx = $this->client->beginTransaction();
        $tx->readEvents(new NoEvents());
        $tx->appendEvents([self::points(950)]);
        $tx->commit();

        $other = TestServer::get($this)->client();
        $first = self::addPoints($this->client, 30);
        $second = self::addPoints($other, 40);

        $first->commit();
        try {
            $second->commit();
            self::fail('expected a ConcurrencyException');
        } catch (ConcurrencyException) {
        }

        self::addPoints($other, 40)->commit();

        $types = array_map(
            static fn(Event $e): string => $e->type,
            iterator_to_array($this->client->readEvents(new AllEvents()), false),
        );
        self::assertSame(['points-added', 'points-added', 'points-added', 'customer-promoted'], $types);
    }

    public function testEventsWrittenWithoutARead(): void
    {
        $tx = $this->client->beginTransaction();

        try {
            $tx->appendEvents([new NewEvent('user-created')]);
            self::fail('expected an InvalidRequestException');
        } catch (InvalidRequestException) {
        }

        self::assertFalse($tx->isActive());
        self::assertFalse($this->client->inTransaction());
    }

    public function testProjections(): void
    {
        $tx = $this->client->beginTransaction();
        $tx->saveProjection('show-seats', 's/1', '{"free":40}');
        $projection = $tx->getProjection('show-seats', 's/1');
        self::assertNotNull($projection);
        self::assertNull($projection->version);
        self::assertSame('{"free":40}', $projection->payload);
        self::assertNull($this->client->getProjection('show-seats', 's/1'));
        $tx->commit();

        $committed = $this->client->getProjection('show-seats', 's/1');
        self::assertNotNull($committed);
        self::assertNotNull($committed->version);
        self::assertSame('{"free":40}', $committed->payload);

        $tx = $this->client->beginTransaction();
        $tx->saveProjection('show-seats', 's/1', '{"free":39}');
        $tx->deleteProjection('seat-hold', 's1-A6');
        $tx->commit();
        self::assertSame('{"free":39}', $this->client->getProjection('show-seats', 's/1')?->payload);

        $tx = $this->client->beginTransaction();
        $tx->deleteProjection('show-seats', 's/1');
        self::assertNull($tx->getProjection('show-seats', 's/1'));
        $tx->commit();
        self::assertNull($this->client->getProjection('show-seats', 's/1'));
    }

    public function testAProjectionChangedSinceItWasRead(): void
    {
        $other = TestServer::get($this)->client();
        $first = $this->client->beginTransaction();
        $second = $other->beginTransaction();
        $first->saveProjection('show-seats', 's1', '{"free":40}');
        $second->saveProjection('show-seats', 's1', '{"free":39}');

        $first->commit();

        $this->expectException(ConcurrencyException::class);
        $second->commit();
    }

    public function testAResetEndsTheTransaction(): void
    {
        $tx = $this->client->beginTransaction();
        $tx->readEvents(new NoEvents());

        TestServer::get($this)->client()->reset();

        try {
            $tx->appendEvents([new NewEvent('user-created')]);
            self::fail('expected a ConcurrencyException');
        } catch (ConcurrencyException) {
        }

        self::assertFalse($tx->isActive());
    }

    public function testAnExpiredTransaction(): void
    {
        $client = TestServer::get($this, ['TAMARACKDB_TX_IDLE_TIMEOUT' => '1'])->client();
        $tx = $client->beginTransaction();

        usleep(1_600_000);

        try {
            $tx->readEvents(new NoEvents());
            self::fail('expected a TransactionNotFoundException');
        } catch (TransactionNotFoundException) {
        }

        self::assertFalse($client->inTransaction());
        $tx->rollback();
    }

    /**
     * Adds points to customer c1, and promotes the customer when the total
     * reaches 1,000. Returns the transaction, ready to commit.
     */
    private static function addPoints(Client $client, int $points): Transaction
    {
        $tx = $client->beginTransaction();
        $total = 0;
        foreach ($tx->readEvents(new Query(EventType::in('points-added'), Identifier::is('customerId', 'c1'))) as $event) {
            $total += (int) $event->payload;
        }
        $events = [self::points($points)];
        if ($total < 1000 && $total + $points >= 1000) {
            $events[] = new NewEvent('customer-promoted', ['customerId' => 'c1']);
        }
        $tx->appendEvents($events);

        return $tx;
    }

    private static function points(int $points): NewEvent
    {
        return new NewEvent('points-added', ['customerId' => 'c1'], payload: (string) $points);
    }
}

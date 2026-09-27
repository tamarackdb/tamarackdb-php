<?php

declare(strict_types=1);

namespace TamarackDB\Tests\Integration;

use PHPUnit\Framework\TestCase;
use TamarackDB\Event\AppendCondition;
use TamarackDB\Event\Event;
use TamarackDB\Event\NewEvent;
use TamarackDB\Middleware\AppendHandler;
use TamarackDB\Middleware\AppendMiddleware;
use TamarackDB\Middleware\ReadHandler;
use TamarackDB\Middleware\ReadMiddleware;
use TamarackDB\Middleware\ReadRequest;
use TamarackDB\Query\Query;
use TamarackDB\Transaction;

final class MiddlewareTest extends TestCase
{
    public function testMiddlewaresWrapAppendsAndReads(): void
    {
        $client = TestServer::get($this)->client();
        $client->resume();
        $client->reset();
        $client->addMiddleware(new class implements AppendMiddleware, ReadMiddleware {
            public function append(array $events, ?AppendCondition $condition, string $ticket, AppendHandler $next): array
            {
                return $next->append(array_map(
                    static fn(NewEvent $e): NewEvent => new NewEvent($e->type, $e->identifiers, $e->metadata + ['tenantId' => ['acme']], $e->payload),
                    $events,
                ), $condition, $ticket);
            }

            public function readEvents(ReadRequest $request, ReadHandler $next): \Generator
            {
                foreach ($next->readEvents($request) as $event) {
                    yield new Event($event->sequence, $event->time, strtoupper($event->type), $event->identifiers, $event->metadata, $event->payload);
                }
            }
        });

        $client->transactional(static fn(Transaction $tx): array => $tx->append(array_map(
            static fn(int $i): NewEvent => new NewEvent('user-created', ['userId' => (string) $i]),
            range(1, 30),
        )));

        $events = iterator_to_array($client->readEvents(Query::all(), pageSize: 7), false);
        self::assertCount(30, $events);
        self::assertSame('USER-CREATED', $events[0]->type);
        self::assertSame('acme', $events[29]->metadataValue('tenantId'));

        // Stopping a read through the chain still drains the page, so the
        // transaction survives.
        $client->transactional(static function (Transaction $tx): void {
            foreach ($tx->readEvents(Query::all(), pageSize: 20) as $event) {
                break;
            }
            $tx->append([new NewEvent('user-created')]);
        });
        self::assertCount(31, iterator_to_array($client->readEvents(Query::all()), false));
    }
}

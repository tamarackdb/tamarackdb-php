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

final class MiddlewareTest extends TestCase
{
    public function testMiddlewaresWrapAppendsAndReads(): void
    {
        $client = TestServer::get($this)->client();
        $client->resume();
        $client->reset();
        $client->addMiddleware(new class implements AppendMiddleware, ReadMiddleware {
            public function appendEvents(array $events, ?AppendCondition $condition, string $ticket, AppendHandler $next): array
            {
                return $next->appendEvents(array_map(
                    static fn(NewEvent $e): NewEvent => new NewEvent($e->type, $e->identifiers, $e->metadata + ['tenantId' => 'acme'], $e->payload),
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

        $client->beginTransaction();
        $client->appendEvents(array_map(
            static fn(int $i): NewEvent => new NewEvent('user-created', ['userId' => (string) $i]),
            range(1, 30),
        ));
        $client->commit();

        $events = iterator_to_array($client->readEvents(Query::all(), pageSize: 7), false);
        self::assertCount(30, $events);
        self::assertSame('USER-CREATED', $events[0]->type);
        self::assertSame(['tenantId' => 'acme'], $events[29]->metadata);

        // Stopping a read through the chain still drains the page, so the
        // transaction survives.
        $client->beginTransaction();
        foreach ($client->readEvents(Query::all(), pageSize: 20) as $event) {
            break;
        }
        $client->appendEvents([new NewEvent('user-created')]);
        $client->commit();
        self::assertCount(31, iterator_to_array($client->readEvents(Query::all()), false));
    }
}

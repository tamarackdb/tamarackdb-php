<?php

declare(strict_types=1);

namespace TamarackDB\Tests\Integration;

use PHPUnit\Framework\TestCase;
use TamarackDB\Event\NewEvent;
use TamarackDB\Exception\UnauthorizedException;
use TamarackDB\Query\Query;
use TamarackDB\Transaction;

final class ConnectionTest extends TestCase
{
    public function testUnixSocket(): void
    {
        $client = TestServer::get($this, unixSocket: true)->client();
        $client->reset();

        $client->transactional(static fn(Transaction $tx): array => $tx->append([new NewEvent('socket-event')]));

        $events = iterator_to_array($client->readEvents(Query::all()), false);
        self::assertCount(1, $events);
        self::assertSame('socket-event', $events[0]->type);
    }

    public function testBearerToken(): void
    {
        $server = TestServer::get($this, ['TAMARACKDB_ENABLE_AUTH' => 'true', 'TAMARACKDB_AUTH_TOKEN' => 'test-token']);

        self::assertSame('ok', $server->client('test-token')->health()->status);

        $this->expectException(UnauthorizedException::class);
        $server->client('wrong-token')->health();
    }
}

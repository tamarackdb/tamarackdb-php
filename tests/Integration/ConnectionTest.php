<?php

declare(strict_types=1);

namespace TamarackDB\Tests\Integration;

use PHPUnit\Framework\TestCase;
use TamarackDB\Event\NewEvent;
use TamarackDB\Exception\UnauthorizedException;
use TamarackDB\Query\Query;

final class ConnectionTest extends TestCase
{
    public function testUnixSocket(): void
    {
        $client = TestServer::get($this, unixSocket: true)->client();
        $client->reset();

        $client->beginTransaction();
        $client->appendEvents([new NewEvent('socket-event')]);
        $client->commit();

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

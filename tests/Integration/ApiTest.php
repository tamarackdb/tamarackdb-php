<?php

declare(strict_types=1);

namespace TamarackDB\Tests\Integration;

use PHPUnit\Framework\TestCase;
use TamarackDB\Event\NewEvent;
use TamarackDB\Exception\ConcurrencyException;
use TamarackDB\Exception\InvalidRequestException;
use TamarackDB\Exception\UnauthorizedException;
use TamarackDB\Internal\Api;
use TamarackDB\Query\EventType;
use TamarackDB\Query\Query;

/**
 * The wire format of Api, and the streaming of CurlTransport, against a
 * real server.
 */
final class ApiTest extends TestCase
{
    private Api $api;

    protected function setUp(): void
    {
        $this->api = new Api(TestServer::get($this)->transport());
        $this->api->reset();
    }

    public function testWriteThenReadAcrossPages(): void
    {
        $write = $this->api->write(['events' => [
            new NewEvent('user-created', ['userId' => '1'], ['tenantId' => 'acme'], 'a')->toArray(),
            new NewEvent('user-created', ['userId' => '2'], [], 'b')->toArray(),
            new NewEvent('user-deleted', ['userId' => '1'], [], 'c')->toArray(),
        ]]);
        self::assertSame([1, 2, 3], array_map(static fn($event): int => $event->sequence, $write->events));

        $events = $this->api->readEvents(new Query(EventType::in('user-created')), 0, 1, null);
        $payloads = [];
        foreach ($events as $event) {
            $payloads[] = $event->payload;
        }

        self::assertSame(['a', 'b'], $payloads);
        self::assertSame([$write->store, 2], $events->getReturn());
    }

    public function testReadOfAnEmptyStore(): void
    {
        $events = $this->api->readEvents(null, 0, null, null);
        foreach ($events as $event) {
            self::fail('expected no event');
        }

        [$store, $lastSequence] = $events->getReturn();
        self::assertNotSame('', $store);
        self::assertSame(0, $lastSequence);
    }

    public function testConditionWithTheStoreId(): void
    {
        $store = $this->api->write(['events' => [new NewEvent('user-created', ['userId' => '1'])->toArray()]])->store;
        $condition = ['failIfEventsMatch' => new Query(EventType::in('user-created'))->toArray(), 'afterSequence' => 0, 'store' => $store];

        $this->expectException(ConcurrencyException::class);

        $this->api->write(['conditions' => [$condition]]);
    }

    public function testProjections(): void
    {
        $versions = $this->api->write(['projections' => ['create' => [['type' => 'user/profile', 'id' => 'a b', 'payload' => 'p1']]]])->createVersions;

        $projection = $this->api->getProjection('user/profile', 'a b');
        self::assertNotNull($projection);
        self::assertSame($versions[0], $projection->version);
        self::assertSame('p1', $projection->payload);

        $this->api->deleteProjectionsByType('user/profile');
        self::assertNull($this->api->getProjection('user/profile', 'a b'));
    }

    public function testServerErrorOnAStreamedRead(): void
    {
        $this->expectException(InvalidRequestException::class);

        foreach ($this->api->readEvents(null, 0, 0, null) as $event) {
            self::fail('expected no event');
        }
    }

    public function testUnixSocketAndBearerToken(): void
    {
        $server = TestServer::get($this, ['TAMARACKDB_ENABLE_AUTH' => 'true', 'TAMARACKDB_AUTH_TOKEN' => 'test-token'], unixSocket: true);

        self::assertSame('ok', new Api($server->transport('test-token'))->health()->status);

        $this->expectException(UnauthorizedException::class);

        new Api($server->transport('wrong-token'))->health();
    }
}

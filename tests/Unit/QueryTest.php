<?php

declare(strict_types=1);

namespace TamarackDB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TamarackDB\Event\Event;
use TamarackDB\Event\NewEvent;
use TamarackDB\Exception\InvalidArgumentException;
use TamarackDB\Query\EventType;
use TamarackDB\Query\Identifier;
use TamarackDB\Query\Metadata;
use TamarackDB\Query\Query;
use TamarackDB\Query\QueryItem;

final class QueryTest extends TestCase
{
    public function testItemsUseTheWireShape(): void
    {
        $query = new Query(
            EventType::in('user-created', 'user-updated'),
            Identifier::is('userId', '123'),
            Identifier::is('tag', 'a'),
            Identifier::is('tag', 'b'),
        )->or(Metadata::is('tenantId', 'acme'));

        self::assertSame([
            [
                'types' => ['user-created', 'user-updated'],
                'identifiers' => [
                    ['name' => 'userId', 'value' => '123'],
                    ['name' => 'tag', 'value' => 'a'],
                    ['name' => 'tag', 'value' => 'b'],
                ],
            ],
            ['metadata' => [['name' => 'tenantId', 'value' => 'acme']]],
        ], $query->toArray());
    }

    public function testAnItemUsesTheCompactShape(): void
    {
        $item = new QueryItem(
            EventType::in('a', 'b'),
            EventType::in('b', 'c'),
            Identifier::is('userId', '123'),
            Identifier::is('tag', 'a'),
            Identifier::is('tag', 'b'),
            Identifier::is('tag', 'a'),
        );

        self::assertSame(['a', 'b', 'c'], $item->types);
        self::assertSame(['userId' => '123', 'tag' => ['a', 'b']], $item->identifiers);
        self::assertSame([], $item->metadata);
    }

    public function testWithAddsFilters(): void
    {
        $item = new QueryItem(Identifier::is('userId', '123'));

        $scoped = $item->with(Metadata::is('tenantId', 'acme'), Identifier::is('userId', '456'));

        self::assertSame(['userId' => ['123', '456']], $scoped->identifiers);
        self::assertSame(['tenantId' => 'acme'], $scoped->metadata);
        self::assertSame(['userId' => '123'], $item->identifiers);
    }

    public function testOrAndMapReturnCopies(): void
    {
        $query = new Query(EventType::in('a'));

        $more = $query->or(EventType::in('b'));
        $mapped = $more->map(static fn(QueryItem $item): QueryItem => $item->with(Metadata::is('tenantId', 'acme')));

        self::assertCount(1, $query->items);
        self::assertCount(2, $more->items);
        self::assertSame([], $more->items[1]->metadata);
        self::assertSame(['tenantId' => 'acme'], $mapped->items[1]->metadata);
    }

    public function testEventTypeNeedsATypeList(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EventType::in();
    }

    public function testAnEmptyEventTypeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EventType::in('a', '');
    }

    public function testAnEmptyIdentifierNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Identifier::is('', '123');
    }

    public function testAnEmptyMetadataNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Metadata::is('', 'acme');
    }

    public function testNewEventUsesTheCompactShape(): void
    {
        $event = new NewEvent('user-created', ['userId' => '123', 'tag' => ['a', 'b']], [], '{"name":"Ada"}');

        self::assertSame([
            'type' => 'user-created',
            'identifiers' => ['userId' => '123', 'tag' => ['a', 'b']],
            'payload' => '{"name":"Ada"}',
        ], $event->toArray());
        self::assertSame(['userId' => '123', 'tag' => ['a', 'b']], $event->identifiers);
    }

    public function testASingleValueListBecomesAString(): void
    {
        $tags = ['userId' => ['123'], 'tag' => ['a', 'b']];

        self::assertSame(['userId' => '123', 'tag' => ['a', 'b']], new NewEvent('a', $tags, $tags)->metadata);
        self::assertSame(['userId' => '123', 'tag' => ['a', 'b']], new Event(1, new \DateTimeImmutable(), 'a', $tags, [], '')->identifiers);
    }

    public function testAnEmptyValueListIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new NewEvent('a', ['userId' => []]);
    }

    public function testNewEventNeedsAType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new NewEvent('');
    }
}

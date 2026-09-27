<?php

declare(strict_types=1);

namespace TamarackDB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TamarackDB\Event\AppendCondition;
use TamarackDB\Event\NewEvent;
use TamarackDB\Exception\InvalidArgumentException;
use TamarackDB\Query\Query;
use TamarackDB\Query\QueryItem;

final class QueryTest extends TestCase
{
    public function testAllIsAStar(): void
    {
        self::assertSame('*', Query::all()->toJsonValue());
        self::assertTrue(Query::all()->isAll());
    }

    public function testItemsUseTheWireShape(): void
    {
        $query = Query::of(
            new QueryItem(types: ['user-created', 'user-updated'], identifiers: ['userId' => '123', 'tag' => ['a', 'b']]),
            new QueryItem(metadata: ['tenantId' => 'acme']),
        );

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
        ], $query->toJsonValue());
    }

    public function testAnEmptyItemIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new QueryItem();
    }

    public function testAnEmptyQueryIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Query::of();
    }

    public function testAnEmptyValueListIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new QueryItem(identifiers: ['userId' => []]);
    }

    public function testNewEventUsesTheCompactShape(): void
    {
        $event = new NewEvent('user-created', ['userId' => '123', 'tag' => ['a', 'b']], [], '{"name":"Ada"}');

        self::assertSame([
            'type' => 'user-created',
            'identifiers' => ['userId' => '123', 'tag' => ['a', 'b']],
            'payload' => '{"name":"Ada"}',
        ], $event->toArray());
        self::assertSame(['userId' => ['123'], 'tag' => ['a', 'b']], $event->identifiers);
    }

    public function testNewEventNeedsAType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new NewEvent('');
    }

    public function testAppendConditionLeavesOutWhatIsNotSet(): void
    {
        self::assertSame([], (new AppendCondition())->toArray());
        self::assertSame(['afterSequence' => 0], (new AppendCondition(afterSequence: 0))->toArray());
        self::assertSame(
            ['failIfEventsMatch' => '*', 'afterSequence' => 12],
            (new AppendCondition(Query::all(), 12))->toArray(),
        );
    }
}

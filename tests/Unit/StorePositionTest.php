<?php

declare(strict_types=1);

namespace TamarackDB\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TamarackDB\CommitResult;
use TamarackDB\Event\AppendedEvent;
use TamarackDB\Exception\InvalidArgumentException;
use TamarackDB\StorePosition;

final class StorePositionTest extends TestCase
{
    private const string STORE = '0f8fad5b-d9cb-469f-a165-70867728950e';

    public function testRoundTripsThroughAString(): void
    {
        $position = StorePosition::fromString(new StorePosition(self::STORE, 42)->toString());

        self::assertSame(self::STORE, $position->store);
        self::assertSame(42, $position->sequence);
    }

    public function testKeepsAStoreIdWithAColon(): void
    {
        $position = StorePosition::fromString(new StorePosition('a:b', 0)->toString());

        self::assertSame('a:b', $position->store);
        self::assertSame(0, $position->sequence);
    }

    #[DataProvider('invalidStrings')]
    public function testRejectsAnInvalidString(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        StorePosition::fromString($value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidStrings(): iterable
    {
        yield 'empty' => [''];
        yield 'no colon' => [self::STORE];
        yield 'no store' => [':42'];
        yield 'no sequence' => [self::STORE . ':'];
        yield 'negative sequence' => [self::STORE . ':-1'];
        yield 'not a number' => [self::STORE . ':4x'];
        yield 'leading zero' => [self::STORE . ':042'];
        yield 'too large' => [self::STORE . ':99999999999999999999'];
    }

    public function testRejectsAnEmptyStore(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StorePosition('', 1);
    }

    public function testRejectsANegativeSequence(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StorePosition(self::STORE, -1);
    }

    public function testCommitResultPositionIsAfterTheLastEvent(): void
    {
        $time = new \DateTimeImmutable();
        $result = new CommitResult(self::STORE, [new AppendedEvent(7, $time), new AppendedEvent(8, $time)]);

        self::assertEquals(new StorePosition(self::STORE, 8), $result->position());
    }

    public function testCommitResultWithoutEventsHasNoPosition(): void
    {
        self::assertNull(new CommitResult(null, [])->position());
        self::assertNull(new CommitResult(self::STORE, [])->position());
    }

    public function testCommitResultEventsNeedAStore(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CommitResult(null, [new AppendedEvent(1, new \DateTimeImmutable())]);
    }
}

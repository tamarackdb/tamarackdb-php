<?php

declare(strict_types=1);

namespace TamarackDB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TamarackDB\CommitResult;
use TamarackDB\Event\AppendedEvent;

final class CommitResultTest extends TestCase
{
    public function testLastSequenceIsTheLastEventWritten(): void
    {
        $time = new \DateTimeImmutable();

        self::assertSame(8, new CommitResult([new AppendedEvent(7, $time), new AppendedEvent(8, $time)])->lastSequence());
    }

    public function testLastSequenceIsNullWithoutEvents(): void
    {
        self::assertNull(new CommitResult([])->lastSequence());
    }
}

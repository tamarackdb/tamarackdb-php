<?php

declare(strict_types=1);

namespace TamarackDB\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TamarackDB\Exception;
use TamarackDB\Exception\ServerException;
use TamarackDB\Http\Response;

final class ServerExceptionTest extends TestCase
{
    /**
     * @return iterable<array{int, string, class-string<ServerException>}>
     */
    public static function codes(): iterable
    {
        yield [400, 'InvalidRequest', Exception\InvalidRequestException::class];
        yield [401, 'Unauthorized', Exception\UnauthorizedException::class];
        yield [404, 'ProjectionNotFound', Exception\ProjectionNotFoundException::class];
        yield [409, 'ConcurrencyException', Exception\ConcurrencyException::class];
        yield [410, 'TicketNotActive', Exception\TicketNotActiveException::class];
        yield [413, 'PayloadTooLarge', Exception\PayloadTooLargeException::class];
        yield [500, 'InternalError', Exception\InternalErrorException::class];
        yield [503, 'TransactionQueueFull', Exception\TransactionQueueFullException::class];
        yield [503, 'ShuttingDown', Exception\ShuttingDownException::class];
        yield [503, 'Unavailable', Exception\UnavailableException::class];
        yield [418, 'SomethingNew', ServerException::class];
    }

    /**
     * @param class-string<ServerException> $class
     */
    #[DataProvider('codes')]
    public function testEachCodeHasItsClass(int $status, string $code, string $class): void
    {
        $e = ServerException::fromResponse(Responses::error($status, $code, 'detail'));

        self::assertSame($class, $e::class);
        self::assertSame($status, $e->statusCode);
        self::assertSame($code, $e->errorCode);
        self::assertSame('detail', $e->detail);
        self::assertSame(\sprintf('TamarackDB responded %d %s: detail', $status, $code), $e->getMessage());
    }

    public function testPlainTextBody(): void
    {
        $e = ServerException::fromResponse(new Response(405, [], "Method Not Allowed\n"));

        self::assertSame(ServerException::class, $e::class);
        self::assertNull($e->errorCode);
        self::assertSame('Method Not Allowed', $e->detail);
        self::assertSame('TamarackDB responded 405: Method Not Allowed', $e->getMessage());
    }
}

<?php

declare(strict_types=1);

namespace TamarackDB\Tests\Unit;

use TamarackDB\Http\Response;

final class Responses
{
    public const string STORE = '5b0c7e2a-1f4d-4a9b-8c3e-6d2f1a0b9e47';

    /**
     * @param array<string, mixed> $data
     */
    public static function json(array $data, int $status = 200): Response
    {
        return new Response($status, ['content-type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
    }

    public static function error(int $status, string $code, ?string $message = null): Response
    {
        return self::json($message === null ? ['error' => $code] : ['error' => $code, 'message' => $message], $status);
    }

    public static function noContent(): Response
    {
        return new Response(204);
    }

    /**
     * An NDJSON page with one event per sequence number, then its trailer.
     *
     * @param list<int> $sequences
     */
    public static function page(array $sequences, bool $hasMore, string $store = self::STORE): Response
    {
        $lines = array_map(self::eventLine(...), $sequences);
        $lines[] = json_encode(['hasMore' => $hasMore], JSON_THROW_ON_ERROR);

        return new Response(200, ['content-type' => 'application/x-ndjson', 'x-tamarackdb-store' => $store], implode("\n", $lines) . "\n");
    }

    public static function eventLine(int $sequence): string
    {
        return json_encode([
            'sequence' => $sequence,
            'time' => '2026-09-01T14:23:05.123456Z',
            'type' => 'user-created',
            'identifiers' => ['userId' => (string) $sequence, 'tag' => ['a', 'b']],
            'metadata' => ['tenantId' => 'acme'],
            'payload' => 'payload-' . $sequence,
        ], JSON_THROW_ON_ERROR);
    }
}

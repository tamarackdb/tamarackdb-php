<?php

declare(strict_types=1);

namespace TamarackDB\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TamarackDB\Event\Event;
use TamarackDB\Event\PendingEvent;
use TamarackDB\Query\EventType;
use TamarackDB\Query\Identifier;
use TamarackDB\Query\Metadata;
use TamarackDB\Query\Query;

/**
 * Replays the query cases shared with the server, which checks the same
 * cases against its SQL.
 */
final class MatcherTest extends TestCase
{
    /**
     * @param array<string, string|list<string>> $identifiers
     * @param array<string, string|list<string>> $metadata
     */
    #[DataProvider('cases')]
    public function testEventMatchesLikeTheServer(?Query $query, string $type, array $identifiers, array $metadata, bool $matches): void
    {
        $event = new Event(1, new \DateTimeImmutable(), $type, $identifiers, $metadata, '');

        self::assertSame($matches, $event->matchesQuery($query));
    }

    /**
     * @param array<string, string|list<string>> $identifiers
     * @param array<string, string|list<string>> $metadata
     */
    #[DataProvider('cases')]
    public function testPendingEventMatchesLikeTheServer(?Query $query, string $type, array $identifiers, array $metadata, bool $matches): void
    {
        $event = new PendingEvent($type, $identifiers, $metadata, '');

        self::assertSame($matches, $event->matchesQuery($query));
    }

    /**
     * @return iterable<string, array{?Query, string, array<string, string|list<string>>, array<string, string|list<string>>, bool}>
     */
    public static function cases(): iterable
    {
        $cases = json_decode(self::read(__DIR__ . '/../Fixtures/query-cases.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsList($cases);
        self::assertNotEmpty($cases);
        foreach ($cases as $case) {
            self::assertIsArray($case);
            self::assertIsString($case['name'] ?? null);
            self::assertIsArray($case['event'] ?? null);
            self::assertIsString($case['event']['type'] ?? null);
            self::assertIsBool($case['matches'] ?? null);

            yield $case['name'] => [
                self::query($case['query'] ?? null),
                $case['event']['type'],
                self::tags($case['event']['identifiers'] ?? []),
                self::tags($case['event']['metadata'] ?? []),
                $case['matches'],
            ];
        }
    }

    private static function read(string $path): string
    {
        $json = file_get_contents($path);
        self::assertIsString($json);

        return $json;
    }

    private static function query(mixed $query): ?Query
    {
        if ($query === '*') {
            return null;
        }
        self::assertIsList($query);
        $result = null;
        foreach ($query as $item) {
            self::assertIsArray($item);
            $filters = [];
            if (isset($item['types'])) {
                self::assertIsList($item['types']);
                $filters[] = EventType::in(...array_map(self::string(...), $item['types']));
            }
            foreach (self::pairs($item['identifiers'] ?? []) as [$name, $value]) {
                $filters[] = Identifier::is($name, $value);
            }
            foreach (self::pairs($item['metadata'] ?? []) as [$name, $value]) {
                $filters[] = Metadata::is($name, $value);
            }
            self::assertNotEmpty($filters);
            $result = $result === null ? new Query(...$filters) : $result->or(...$filters);
        }
        self::assertNotNull($result);

        return $result;
    }

    /**
     * @return list<array{string, string}>
     */
    private static function pairs(mixed $pairs): array
    {
        self::assertIsList($pairs);
        $result = [];
        foreach ($pairs as $pair) {
            self::assertIsArray($pair);
            $result[] = [self::string($pair['name'] ?? null), self::string($pair['value'] ?? null)];
        }

        return $result;
    }

    /**
     * @return array<string, string|list<string>>
     */
    private static function tags(mixed $tags): array
    {
        self::assertIsArray($tags);
        $result = [];
        foreach ($tags as $name => $values) {
            $result[(string) $name] = \is_array($values) ? array_values(array_map(self::string(...), $values)) : self::string($values);
        }

        return $result;
    }

    private static function string(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }
}

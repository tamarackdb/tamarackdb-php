<?php

declare(strict_types=1);

namespace TamarackDB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TamarackDB\Client;
use TamarackDB\Event\AppendCondition;
use TamarackDB\Event\Event;
use TamarackDB\Event\NewEvent;
use TamarackDB\Exception\InvalidArgumentException;
use TamarackDB\Middleware\AppendHandler;
use TamarackDB\Middleware\AppendMiddleware;
use TamarackDB\Middleware\ReadHandler;
use TamarackDB\Middleware\ReadMiddleware;
use TamarackDB\Middleware\ReadRequest;
use TamarackDB\Query\EventType;
use TamarackDB\Query\Metadata;
use TamarackDB\Query\Query;
use TamarackDB\Query\QueryItem;

final class MiddlewareTest extends TestCase
{
    private const string TICKET = 'a045ad63-5d4b-4847-8eb9-fbddb4e2d65b';

    private FakeTransport $transport;

    private Client $client;

    /** @var \ArrayObject<int, string> */
    private \ArrayObject $calls;

    protected function setUp(): void
    {
        $this->calls = new \ArrayObject();
        $this->transport = new FakeTransport();
        $this->client = new Client($this->transport);
    }

    public function testAnAppendMiddlewareChangesTheEvents(): void
    {
        $this->client->addMiddleware(new class implements AppendMiddleware {
            public function appendEvents(array $events, ?AppendCondition $condition, string $ticket, AppendHandler $next): array
            {
                $events = array_map(
                    static fn(NewEvent $e): NewEvent => new NewEvent($e->type, $e->identifiers, $e->metadata + ['ticket' => $ticket], $e->payload),
                    $events,
                );

                return $next->appendEvents($events, $condition, $ticket);
            }
        });
        $this->transport->push(Responses::json(['ticket' => self::TICKET]), Responses::json(['events' => []]));

        $this->client->beginTransaction();
        $this->client->appendEvents([new NewEvent('a', metadata: ['tenantId' => 'acme'])]);

        self::assertSame(
            ['events' => [['type' => 'a', 'metadata' => ['tenantId' => 'acme', 'ticket' => self::TICKET], 'payload' => '']]],
            $this->transport->body(1),
        );
    }

    public function testTheLastMiddlewareAddedRunsFirst(): void
    {
        $this->client->addMiddleware($this->recorder('inner'));
        $this->client->addMiddleware($this->recorder('outer'));
        $this->transport->push(
            Responses::json(['ticket' => self::TICKET]),
            Responses::json(['events' => []]),
            Responses::page([], false),
        );

        $this->client->beginTransaction();
        $this->client->appendEvents([]);
        iterator_to_array($this->client->readEvents(null));

        self::assertSame(
            ['outer append before', 'inner append before', 'inner append after', 'outer append after', 'outer read', 'inner read'],
            $this->calls->getArrayCopy(),
        );
    }

    public function testAnInnerMiddlewareRunsLast(): void
    {
        $this->client->addMiddleware($this->recorder('first'));
        $this->client->addInnerMiddleware($this->recorder('inner'));
        $this->client->addMiddleware($this->recorder('outer'));
        $this->transport->push(
            Responses::json(['ticket' => self::TICKET]),
            Responses::json(['events' => []]),
            Responses::page([], false),
        );

        $this->client->beginTransaction();
        $this->client->appendEvents([]);
        iterator_to_array($this->client->readEvents(null));

        self::assertSame(
            [
                'outer append before', 'first append before', 'inner append before',
                'inner append after', 'first append after', 'outer append after',
                'outer read', 'first read', 'inner read',
            ],
            $this->calls->getArrayCopy(),
        );
    }

    public function testAnInnerMiddlewareSeesWhatIsSent(): void
    {
        /** @var \ArrayObject<int, NewEvent> $sent */
        $sent = new \ArrayObject();
        $this->client->addInnerMiddleware(new class ($sent) implements AppendMiddleware {
            /** @param \ArrayObject<int, NewEvent> $sent */
            public function __construct(private \ArrayObject $sent) {}

            public function appendEvents(array $events, ?AppendCondition $condition, string $ticket, AppendHandler $next): array
            {
                foreach ($events as $event) {
                    $this->sent->append($event);
                }

                return $next->appendEvents($events, $condition, $ticket);
            }
        });
        $this->client->addMiddleware(new class implements AppendMiddleware {
            public function appendEvents(array $events, ?AppendCondition $condition, string $ticket, AppendHandler $next): array
            {
                return $next->appendEvents([new NewEvent('replaced')], $condition, $ticket);
            }
        });
        $this->transport->push(Responses::json(['ticket' => self::TICKET]), Responses::json(['events' => []]));

        $this->client->beginTransaction();
        $this->client->appendEvents([new NewEvent('original')]);

        self::assertSame(['replaced'], array_map(static fn(NewEvent $e): string => $e->type, $sent->getArrayCopy()));
    }

    public function testAReadMiddlewareChangesTheEvents(): void
    {
        $this->client->addMiddleware(new class implements ReadMiddleware {
            public function readEvents(ReadRequest $request, ReadHandler $next): \Generator
            {
                foreach ($next->readEvents($request) as $event) {
                    yield new Event($event->sequence, $event->time, $event->type . '.v2', $event->identifiers, $event->metadata, $event->payload);
                }
            }
        });
        $this->transport->push(Responses::page([1, 2], true), Responses::page([3], false));

        $types = array_map(static fn(Event $e): string => $e->type, iterator_to_array($this->client->readEvents(null), false));

        self::assertSame(['user-created.v2', 'user-created.v2', 'user-created.v2'], $types);
    }

    public function testAReadMiddlewareChangesTheRequest(): void
    {
        $this->client->addMiddleware(new class implements ReadMiddleware {
            public function readEvents(ReadRequest $request, ReadHandler $next): \Generator
            {
                return $next->readEvents($request->withPageSize(50));
            }
        });
        $this->transport->push(Responses::page([], false));

        iterator_to_array($this->client->readEvents(null));

        self::assertSame(['query' => '*', 'limit' => 50], $this->transport->body(0));
    }

    public function testAReadMiddlewareScopesTheQuery(): void
    {
        $this->client->addMiddleware(new class implements ReadMiddleware {
            public function readEvents(ReadRequest $request, ReadHandler $next): \Generator
            {
                $scope = static fn(QueryItem $item): QueryItem => $item->with(Metadata::is('tenantId', 'acme'));

                return $next->readEvents($request->withQuery($request->query?->map($scope) ?? new Query(Metadata::is('tenantId', 'acme'))));
            }
        });
        $this->transport->push(Responses::page([], false), Responses::page([], false));

        iterator_to_array($this->client->readEvents(new Query(EventType::in('a'))->or(EventType::in('b'))));
        iterator_to_array($this->client->readEvents(null));

        $tenant = [['name' => 'tenantId', 'value' => 'acme']];
        self::assertSame([['types' => ['a'], 'metadata' => $tenant], ['types' => ['b'], 'metadata' => $tenant]], $this->transport->body(0)['query']);
        self::assertSame([['metadata' => $tenant]], $this->transport->body(1)['query']);
    }

    public function testAReadMiddlewareCanAnswerOnItsOwn(): void
    {
        $this->client->addMiddleware(new class implements ReadMiddleware {
            public function readEvents(ReadRequest $request, ReadHandler $next): \Generator
            {
                yield new Event(1, new \DateTimeImmutable(), 'cached', [], [], '');
            }
        });

        $events = iterator_to_array($this->client->readEvents(null), false);

        self::assertSame('cached', $events[0]->type);
        self::assertSame([], $this->transport->requests);
    }

    public function testAReadMiddlewareSeesTheTicket(): void
    {
        $tickets = new \ArrayObject();
        $this->client->addMiddleware(new class ($tickets) implements ReadMiddleware {
            /** @param \ArrayObject<int, ?string> $tickets */
            public function __construct(private \ArrayObject $tickets) {}

            public function readEvents(ReadRequest $request, ReadHandler $next): \Generator
            {
                $this->tickets->append($request->ticket);

                return $next->readEvents($request);
            }
        });
        $this->transport->push(Responses::page([], false), Responses::json(['ticket' => self::TICKET]), Responses::page([], false));

        iterator_to_array($this->client->readEvents(null));
        $this->client->beginTransaction();
        iterator_to_array($this->client->readEvents(null));

        self::assertSame([null, self::TICKET], $tickets->getArrayCopy());
    }

    public function testReadRequestValidation(): void
    {
        $request = new ReadRequest(null, afterSequence: 3);
        self::assertSame(3, $request->withPageSize(10)->afterSequence);
        self::assertSame(10, $request->withPageSize(10)->pageSize);

        $this->expectException(InvalidArgumentException::class);
        $request->withAfterSequence(-1);
    }

    private function recorder(string $name): AppendMiddleware&ReadMiddleware
    {
        return new class ($name, $this->calls) implements AppendMiddleware, ReadMiddleware {
            /** @param \ArrayObject<int, string> $calls */
            public function __construct(private string $name, private \ArrayObject $calls) {}

            public function appendEvents(array $events, ?AppendCondition $condition, string $ticket, AppendHandler $next): array
            {
                $this->calls->append($this->name . ' append before');
                $appended = $next->appendEvents($events, $condition, $ticket);
                $this->calls->append($this->name . ' append after');

                return $appended;
            }

            public function readEvents(ReadRequest $request, ReadHandler $next): \Generator
            {
                $this->calls->append($this->name . ' read');

                return $next->readEvents($request);
            }
        };
    }
}

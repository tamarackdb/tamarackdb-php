# tamarackdb-php

PHP client for [TamarackDB](https://tamarackdb.github.io/), an event store
compliant with the [DCB specification](https://dcb.events/specification/).

It covers the whole integration API: transactions, reading and appending
events, Append Conditions, projections, and projection rebuilds. It is
tested against TamarackDB v0.24.0.

## Requirements

- PHP 8.4 or later, with the `curl` and `json` extensions.
- A running TamarackDB server, over TCP or its unix socket.

## Installation

```sh
composer require tamarackdb/tamarackdb-php
```

## Connecting

```php
use TamarackDB\Client;

$client = Client::http('http://127.0.0.1:8085');
$client = Client::unixSocket('/run/tamarackdb/tamarackdb.sock');

// With enableAuth on, and your own limits:
$client = Client::http('http://127.0.0.1:8085', token: 'secret', queueTimeout: 5.0, timeout: 30.0);
```

`queueTimeout` is how long `beginTransaction()` and `pause()` wait for their
turn when another transaction is active (10 seconds by default). Past it,
they throw a `TimeoutException`. Pick it from how long your end user can wait.

## Handling a command

A command runs in one transaction: read, decide, append, let your event
handlers react, write projections, commit. Only one transaction is active at
a time, so keep it short and inside one request of your application.

The client holds the transaction, like PDO: `beginTransaction()` opens it,
and every call until `commit()` or `rollback()` runs inside it.

```php
use TamarackDB\Event\AppendCondition;
use TamarackDB\Event\NewEvent;
use TamarackDB\Query\Query;
use TamarackDB\Query\QueryItem;

$client->beginTransaction();
try {
    $query = Query::of(new QueryItem(identifiers: ['userId' => $userId]));

    $last = null;
    foreach ($client->readEvents($query) as $event) {
        // Build your decision model from $event.
        $last = $event->sequence;
    }

    $client->appendEvents(
        [new NewEvent('user-renamed', ['userId' => $userId], ['tenantId' => 'acme'], json_encode(['name' => $name]))],
        new AppendCondition($query, $last),
    );

    $client->commit();
} catch (\Throwable $e) {
    if ($client->inTransaction()) {
        $client->rollback();
    }
    throw $e;
}
```

Log `$client->getTicket()` with the command it belongs to: when a
transaction expires, the server logs a warning with that ticket.

`beginTransaction()` throws a `TransactionAlreadyActiveException` when the
client already has a transaction. `appendEvents()`, `commit()`, and
`rollback()` throw a `NoActiveTransactionException` outside one.

Any server error inside a transaction rolls it back on the server, except a
missing projection. The client then drops the transaction:
`inTransaction()` returns false. Begin a new one and run the whole command
again. A transport failure leaves the transaction open on the client, so you
can still call `rollback()`.

## Reading events

`readEvents()` returns a generator. It fetches pages as you consume it, and
follows `hasMore` on its own:

```php
foreach ($client->readEvents(Query::all()) as $event) {
    $event->sequence;               // int
    $event->time;                   // DateTimeImmutable, UTC
    $event->type;                   // string
    $event->identifiers;            // ['userId' => '123', 'courseId' => ['a', 'b']]
    $event->metadata;               // ['tenantId' => 'acme']
    $event->payload;                // string, exactly as appended
}
```

In `identifiers` and `metadata`, a name with one value maps to a string,
a name with several values to a list. `NewEvent` and `QueryItem` expose
them the same way.

- Inside a transaction, `readEvents()` also sees the events appended earlier
  in it. The generator stays tied to that transaction, even if you consume
  it later.
- Outside a transaction, it reads committed events only, and never waits for
  the active transaction. Use it to display data, or for a projection
  rebuild.

It takes these filters:

```php
$client->readEvents(
    Query::of(
        new QueryItem(types: ['user-created', 'user-updated'], identifiers: ['userId' => '123']),
        new QueryItem(types: ['some-other-event']),
    ),
    afterSequence: 12345,
    from: new DateTimeImmutable('2026-01-01'),
    before: new DateTimeImmutable('2026-02-01'),
    pageSize: 500,
);
```

Items are combined with OR. Within an item, `types` is an OR, and
`identifiers` and `metadata` are ANDs. A value given as a list,
`['courseId' => ['a', 'b']]`, requires each of them.

When a page without a ticket is cut short, the generator resumes it after
the last event it received, so no event is skipped or repeated. To follow
new events, keep the last `sequence` you got and read again later with it
as `afterSequence`.

You can stop consuming a generator at any time. Inside a transaction, the
rest of the current page is read and discarded, since closing the
connection would roll the transaction back.

## Appending events

```php
$appended = $client->appendEvents([
    new NewEvent('user-created', ['userId' => '123'], ['tenantId' => 'acme'], '{"name":"Ada"}'),
]);

$appended[0]->sequence; // final as soon as appendEvents() returns
$appended[0]->time;
```

The payload is an opaque string: encode it as you like (JSON, XML, ...).
A call carries at most 100 events.

### Append Condition

`new AppendCondition($query, $afterSequence)` makes the append fail with a
`ConcurrencyException` when an event matching `$query` exists after
`$afterSequence`. The transaction is then rolled back.

## Projections

A projection is an opaque payload identified by type and id, written in the
same transaction as the events it's computed from.

```php
use TamarackDB\Projection\ProjectionWrites;

// Inside a transaction, after appending events:
$writes = new ProjectionWrites();

$profile = $client->getProjection('user-profile', '123'); // null when missing
if ($profile === null) {
    $writes->create('user-profile', '123', '{"name":"Ada"}');
} else {
    $writes->replace('user-profile', '123', $profile->version, '{"name":"Ada Lovelace"}');
}
$writes->delete('user-list-entry', '456', $entryVersion);

// One call, right before the commit.
$result = $client->writeProjections($writes);
$result->createVersions;  // new versions, in order
$result->replaceVersions;

$client->commit();
```

Inside a transaction, `getProjection()` sees the projections written earlier
in it, and a missing projection doesn't end the transaction. Outside one, it
reads committed projections only.

`replace` and `delete` carry the version you read. When it no longer
matches, the call fails with a `ConcurrencyException`.

## Projection rebuilds

A rebuild is your application's job. The client gives you the calls it
needs:

```php
$client->pause();                              // waits for queued transactions
$client->deleteProjectionsByType('user-profile'); // or deleteAllProjections()

foreach ($client->readEvents(Query::all()) as $event) {
    // run your projectors, and every so often:
    // $client->writeProjections($writes);     // outside a transaction: commits on its own
}

$client->resume();
```

While paused, `beginTransaction()` throws a `PausedException`. Outside a
pause, `deleteProjectionsByType()`, `deleteAllProjections()`, and
`writeProjections()` without a transaction throw a `NotPausedException`.

## Middlewares

A middleware wraps every append, every read, or both. It can change what
goes in, what comes out, or answer on its own without calling the next
layer.

```php
use TamarackDB\Middleware\AppendHandler;
use TamarackDB\Middleware\AppendMiddleware;

// Adds the tenant to every appended event.
final class TenantMetadata implements AppendMiddleware
{
    public function __construct(private string $tenantId) {}

    public function append(array $events, ?AppendCondition $condition, string $ticket, AppendHandler $next): array
    {
        $events = array_map(fn (NewEvent $e) => new NewEvent(
            $e->type, $e->identifiers, $e->metadata + ['tenantId' => $this->tenantId], $e->payload,
        ), $events);

        return $next->append($events, $condition, $ticket);
    }
}
```

```php
use TamarackDB\Middleware\ReadHandler;
use TamarackDB\Middleware\ReadMiddleware;
use TamarackDB\Middleware\ReadRequest;

// Turns old event versions into the current one.
final class Upcaster implements ReadMiddleware
{
    public function readEvents(ReadRequest $request, ReadHandler $next): \Generator
    {
        foreach ($next->readEvents($request) as $event) {
            yield $event->type === 'user-created.v1' ? $this->toV2($event) : $event;
        }
    }
}
```

```php
$client->addMiddleware(new TenantMetadata('acme'));
$client->addMiddleware(new Upcaster());
```

- The last middleware added is the outermost layer: it runs first.
- A class implementing both interfaces wraps both appends and reads.
- Append middlewares wrap `appendEvents()`, and read middlewares wrap
  `readEvents()`. `$request->ticket` is null outside a transaction.
  `ReadRequest` has `with*()` methods to change the request.
- Pagination happens below every middleware: a read middleware sees one
  continuous stream of events.

A read middleware must never change an event's `sequence`, and should not
leave events out: your application relies on the last Sequence Position it
read for its Append Conditions and to follow new events.

## Errors

Every exception implements `TamarackDB\Exception\TamarackDBException`.

| Exception | When |
|---|---|
| `ConcurrencyException` | 409: an Append Condition failed, or a projection version doesn't match |
| `InvalidRequestException` | 400: the server rejected the request |
| `UnauthorizedException` | 401: missing or wrong token |
| `NotPausedException` | 409: a rebuild call while the server isn't paused |
| `TicketNotActiveException` | 410: the transaction has already ended on the server |
| `PayloadTooLargeException` | 413: an event, a projection, or the request is too large |
| `InternalErrorException` | 500 |
| `TransactionQueueFullException` | 503: too many requests are waiting for a transaction |
| `PausedException` | 503: `beginTransaction()` while the server is paused |
| `ShuttingDownException` | 503: the server is shutting down |
| `UnavailableException` | 503: `health()` only, storage is unreachable |
| `TransactionAlreadyActiveException` | `beginTransaction()` while the client already has a transaction |
| `NoActiveTransactionException` | `appendEvents()`, `commit()`, or `rollback()` without a transaction |
| `TransportException` | no full response: server unreachable, connection dropped |
| `TimeoutException` | a `TransportException`: the client stopped waiting |
| `ProtocolException` | a response this client can't make sense of |
| `InvalidArgumentException` | a value that breaks an API rule, caught before sending |

Every server error extends `ServerException`, with `$statusCode`,
`$errorCode` (such as `"ConcurrencyException"`), and `$detail`.

## Server state

```php
$client->health();  // Health { status, version, paused }
$client->debug();   // GET /debug, as an array
$client->reset();   // devMode only: deletes every event and projection
```

## Development

```sh
composer install
composer test:unit
composer analyse
composer cs
```

The integration tests start their own `tamarackdb-server` processes, with
`devMode` on and an empty data directory, on a free port and on a unix
socket. They are skipped unless `TAMARACKDB_SERVER_BIN` points to a server
binary:

```sh
TAMARACKDB_SERVER_BIN=/path/to/tamarackdb-server composer test
```

## License

MIT, see [LICENSE](LICENSE).

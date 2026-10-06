# tamarackdb-php

PHP client for [TamarackDB](https://tamarackdb.github.io/), an event store
compliant with the [DCB specification](https://dcb.events/specification/).

It covers the whole HTTP API: transactions, reading and writing events,
projections, projection rebuilds, and pauses. It is a low-level client,
meant to be used by an event sourcing framework or directly by an
application. It is tested against TamarackDB v0.30.0.

## Requirements

- PHP 8.5 or later, with the `curl` and `json` extensions.
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
$client = Client::http('http://127.0.0.1:8085', token: 'secret', timeout: 120.0);
```

`timeout` is how long the client waits for a response (60 seconds by
default). It includes the time a request waits for its turn in the server's
queue: a commit, `writeProjections()`, the bulk deletes, `pause()`,
`resume()`, `optimize()`, and `reset()`. Past it, the client throws a
`TimeoutException`.

## Handling a command

A command runs in one transaction, which lives in the server. Each decision
reads events, then writes its events, or none. Your event handlers react,
your projections are read and written, and the commit writes everything at
once, or nothing. How it works is in
[Transactions](https://tamarackdb.github.io/docs/concepts/transactions/).

```php
use TamarackDB\Event\NewEvent;
use TamarackDB\Query\Identifier;
use TamarackDB\Query\Query;

$tx = $client->beginTransaction();
try {
    // One decision: one read, then one write.
    foreach ($tx->readEvents(new Query(Identifier::is('userId', $userId))) as $event) {
        // Build your decision model from $event.
    }
    $result = $tx->appendEvents([
        new NewEvent('user-renamed', ['userId' => $userId], ['tenantId' => 'acme'], json_encode(['name' => $name])),
    ]);
    // Give $result->time to the events before your event handlers react to them.

    // Projections, once the events are written.
    $tx->saveProjection('user-profile', $userId, json_encode(['name' => $name]));

    $tx->commit();
} catch (\Throwable $e) {
    $tx->rollback();
    throw $e;
}
```

- `beginTransaction()` returns the transaction. `$client->getTransaction()`
  returns it too, while it's active, and `$client->inTransaction()` tells
  whether there is one. A client holds at most one active transaction.
- After a `ConcurrencyException` or a `TransactionNotFoundException`, run
  the whole command again, in a new transaction.
- Any server error ends the transaction, except a missing projection and a
  `TransactionBusyException`. `$tx->isActive()` then returns false, and every call but `rollback()`
  throws a `NoActiveTransactionException`. The same goes once the
  transaction is committed or rolled back.
- A transport failure leaves the transaction active on the client, since the
  call may not have reached the server. Call `rollback()`.
- `rollback()` never throws, and does nothing on a transaction that is
  already over: it's safe in any error handler.
- If a commit's response is lost, the commit can't be sent again. See
  [A lost response](https://tamarackdb.github.io/docs/http-api/transactions/#a-lost-response).

## Reading events

### In a transaction

`$tx->readEvents()` returns a list: every committed event that matches
(`Event`), then every event written earlier in the transaction that matches
(`PendingEvent`). The whole response is read before it returns.

```php
foreach ($tx->readEvents($query) as $event) {
    $event->time;                   // DateTimeImmutable, UTC
    $event->type;                   // string
    $event->identifiers;            // ['userId' => '123', 'courseId' => ['a', 'b']]
    $event->metadata;               // ['tenantId' => 'acme']
    $event->payload;                // string, exactly as written
    if ($event instanceof Event) {
        $event->sequence;           // int; a PendingEvent gets its own at commit
    }
}
```

The next call after a read must be `appendEvents()`, with the events of the
decision or an empty list. A decision that rests on no event reads
`new NoEvents()` first. A response cut short, or one the client can't read,
abandons the transaction and throws: run the command again.

### Outside a transaction

`$client->readEvents()` reads committed events, for a projector that
catches up on its own or a projection rebuild. It returns `Events`, which
you iterate once. Pages are fetched as you iterate, and a page cut short is
resumed after the last event received, so no event is skipped or repeated.

```php
use TamarackDB\Query\AllEvents;

$events = $client->readEvents(new AllEvents(), afterSequence: $last, storeId: $storeId, pageSize: 500);
foreach ($events as $event) {
    // Every event is an Event, with its sequence.
    $last = $event->sequence;
}
$storeId = $events->storeId();
```

To follow new events, keep the last Sequence Position you read and the
store ID, and pass both to the next read. If the store was reset in
between, the read throws a `StoreChangedException`: the position no longer
means anything, so start over from the beginning (see
[Store ID](https://tamarackdb.github.io/docs/concepts/store-id/)).

### Queries

Both reads take a `Query`, `new AllEvents()`, or `new NoEvents()`.

```php
use TamarackDB\Query\EventType;
use TamarackDB\Query\Identifier;
use TamarackDB\Query\Metadata;
use TamarackDB\Query\Query;

new Query(
    EventType::in('user-created', 'user-updated'),
    Identifier::is('userId', '123'),
)->or(
    EventType::in('some-other-event'),
    Metadata::is('tenantId', 'acme'),
);
```

The filters given together form one item, and an event must match all of
them. `or()` adds another item, and an event matching any item matches the
query. Within `EventType::in()`, any of the types matches. Give
`Identifier::is()` or `Metadata::is()` twice with the same name to require
both values. To add a filter to every item of a query, use `map()` and
`with()`:
`$query->map(fn (QueryItem $item) => $item->with(Metadata::is('tenantId', 'acme')))`.

In `identifiers` and `metadata`, a name with one value maps to a string,
a name with several values to a list. `NewEvent` and `QueryItem` expose
them the same way.

## Writing events

```php
$result = $tx->appendEvents([
    new NewEvent('user-created', ['userId' => '123'], ['tenantId' => 'acme'], '{"name":"Ada"}'),
]);
```

- The write closes the read before it. `appendEvents([])` is the decision
  to write nothing, and the commit still checks it.
- `$result->time` is the time every event of the write carries, and keeps
  once committed. The events get their Sequence Position at commit.
- The payload is an opaque string: encode it as you like (JSON, XML, ...).

## Projections

A projection is an opaque payload identified by type and id. In a
transaction, it's written with the events it's computed from (see
[Projections](https://tamarackdb.github.io/docs/concepts/projections/)).

```php
$profile = $tx->getProjection('user-profile', '123'); // null when missing
$profile?->payload;

$tx->saveProjection('user-profile', '123', '{"name":"Ada Lovelace"}');
$tx->deleteProjection('user-list-entry', '456');
```

- `getProjection()` sees the changes the transaction made. A missing
  projection doesn't end the transaction. Its `version` is always null:
  the server keeps the version read.
- A transaction must read a projection before it writes it. If it didn't,
  `saveProjection()` and `deleteProjection()` read it first. The server
  refuses that read while a read of events waits for its write, so write
  projections once the events are written.
- At commit, a projection changed by another write since it was read gets a
  `ConcurrencyException`.

## Projection rebuilds

Outside a transaction, the client reads committed projections with their
version, and writes them with `writeProjections()`, all or nothing:

```php
use TamarackDB\Projection\ProjectionWrites;

$profile = $client->getProjection('user-profile', '123'); // with $profile->version

$result = $client->writeProjections(
    new ProjectionWrites()
        ->create('user-list-entry', '789', '{"name":"Grace"}')
        ->replace('user-profile', '123', $profile->version, '{"name":"Ada Lovelace"}')
        ->delete('user-list-entry', '456', $entryVersion),
);
$result->createVersions;  // new versions, in order
$result->replaceVersions;
```

`replace` and `delete` carry the version read. When it no longer matches,
or a created projection already exists, the call throws a
`ConcurrencyException`.

A rebuild is your application's job. The client gives you the calls it
needs:

```php
$client->deleteProjectionsByType('user-profile'); // or deleteAllProjections()

foreach ($client->readEvents(new AllEvents()) as $event) {
    // Run your projectors, and every so often:
    // $client->writeProjections($writes);
}
```

`writeProjections()` and the bulk deletes wait for their turn in the
server's queue. See [rebuilds](https://tamarackdb.github.io/docs/concepts/projections/#rebuilds)
for how to run one.

## Pause

```php
$point = $client->pause();  // returns once the pause is in place
$point->lastSequence;       // int
$point->storeId;            // string

$client->resume();
```

While transactions are still open, `pause()` asks the server again every
`$retryAfter` milliseconds (1000 by default): `$client->pause(retryAfter: 200)`.
See [Pause](https://tamarackdb.github.io/docs/http-api/pause/).

## Errors

Every exception implements `TamarackDB\Exception\TamarackDBException`.

| Exception | When |
|---|---|
| `ConcurrencyException` | 409: a commit whose reads or projections changed since, or a projection version that doesn't match |
| `TransactionBusyException` | 409: another call still runs on the transaction, for example one that timed out. The transaction goes on |
| `NotPausedException` | 409: `reset()` while no pause is in place |
| `InvalidRequestException` | 400: the server rejected the request, or a call that breaks a rule of transactions |
| `UnauthorizedException` | 401: missing or wrong token |
| `TransactionNotFoundException` | 404: the transaction expired, or an error ended it |
| `PayloadTooLargeException` | 413: an event, a projection, or the request is too large |
| `InternalErrorException` | 500 |
| `WriteQueueFullException` | 503: too many requests are waiting in the server's queue |
| `ShuttingDownException` | 503: the server is shutting down |
| `PausedException` | 503: `beginTransaction()` while a pause is requested or in place |
| `UnavailableException` | 503: `health()` only, storage is unreachable |
| `TransactionAlreadyActiveException` | `beginTransaction()` while the client has an active transaction |
| `NoActiveTransactionException` | a call on a transaction that is over, or `getTransaction()` without one |
| `StoreChangedException` | a read outside a transaction got another store ID: the store was reset |
| `TransportException` | no full response: server unreachable, connection dropped |
| `TimeoutException` | a `TransportException`: the client stopped waiting |
| `ProtocolException` | a response this client can't make sense of |
| `InvalidArgumentException` | a value that breaks an API rule, caught before sending |

Every server error extends `ServerException`, with `$statusCode`,
`$errorCode` (such as `"ConcurrencyException"`), and `$detail`.

## Server state

```php
$client->health();    // Health { status, paused, version }
$client->optimize();  // refreshes the statistics SQLite plans queries with
$client->reset();     // devMode only, during a pause: deletes every event and projection
```

## Development

```sh
composer install
composer test:unit
composer analyse
composer cs
```

The integration tests start their own `tamarackdb-server` processes, with
`devMode` on and a new database, on a free port and on a unix socket. They
are skipped unless `TAMARACKDB_SERVER_BIN` points to a server binary, with
`tamarackdb-init` in the same directory. Build both from a clone of the
server repo, at the version stated above, and check it with `-version`:

```sh
TAMARACKDB_SERVER_BIN=/path/to/tamarackdb-server composer test
```

## License

MIT, see [LICENSE](LICENSE).

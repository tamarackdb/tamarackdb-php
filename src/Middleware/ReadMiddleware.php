<?php

declare(strict_types=1);

namespace TamarackDB\Middleware;

use TamarackDB\Event\Event;

/**
 * Wraps every readEvents() call of a Client and of its transactions.
 *
 * A middleware can change the request before calling $next, and change
 * the events it yields, for example to upcast old event versions.
 * Pagination happens below every middleware: it sees one continuous
 * stream of events. Register it with Client::addMiddleware().
 *
 * Never change an event's sequence, and avoid leaving events out: the
 * application relies on the last Sequence Position it read for its Append
 * Conditions and to follow new events.
 */
interface ReadMiddleware
{
    /**
     * @return \Generator<int, Event>
     */
    public function readEvents(ReadRequest $request, ReadHandler $next): \Generator;
}

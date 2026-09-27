<?php

declare(strict_types=1);

namespace TamarackDB\Middleware;

use TamarackDB\Event\AppendCondition;
use TamarackDB\Event\AppendedEvent;
use TamarackDB\Event\NewEvent;

/**
 * Wraps every Transaction::append() call of a Client.
 *
 * A middleware can change the events or the condition before calling
 * $next, act on the result after, or both. Register it with
 * Client::addMiddleware().
 */
interface AppendMiddleware
{
    /**
     * @param list<NewEvent> $events
     *
     * @return list<AppendedEvent>
     */
    public function append(array $events, ?AppendCondition $condition, string $ticket, AppendHandler $next): array;
}

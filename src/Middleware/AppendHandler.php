<?php

declare(strict_types=1);

namespace TamarackDB\Middleware;

use TamarackDB\Event\AppendCondition;
use TamarackDB\Event\AppendedEvent;
use TamarackDB\Event\NewEvent;

/**
 * Appends events inside the transaction identified by $ticket: the next
 * layer an AppendMiddleware hands the call to.
 */
interface AppendHandler
{
    /**
     * @param list<NewEvent> $events
     *
     * @return list<AppendedEvent>
     */
    public function appendEvents(array $events, ?AppendCondition $condition, string $ticket): array;
}

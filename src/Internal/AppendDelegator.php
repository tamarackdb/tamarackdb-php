<?php

declare(strict_types=1);

namespace TamarackDB\Internal;

use TamarackDB\Event\AppendCondition;
use TamarackDB\Middleware\AppendHandler;
use TamarackDB\Middleware\AppendMiddleware;

/**
 * One layer of the append chain: hands the call to $middleware, with
 * $next as the layer below it.
 *
 * @internal
 */
final readonly class AppendDelegator implements AppendHandler
{
    public function __construct(
        private AppendMiddleware $middleware,
        private AppendHandler $next,
    ) {}

    public function appendEvents(array $events, ?AppendCondition $condition, string $ticket): array
    {
        return $this->middleware->appendEvents($events, $condition, $ticket, $this->next);
    }
}

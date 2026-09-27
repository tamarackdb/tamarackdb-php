<?php

declare(strict_types=1);

namespace TamarackDB\Middleware;

use TamarackDB\Event\Event;

/**
 * Reads events: the next layer a ReadMiddleware hands the call to.
 */
interface ReadHandler
{
    /**
     * @return \Generator<int, Event>
     */
    public function readEvents(ReadRequest $request): \Generator;
}

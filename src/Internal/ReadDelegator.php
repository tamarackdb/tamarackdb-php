<?php

declare(strict_types=1);

namespace TamarackDB\Internal;

use TamarackDB\Middleware\ReadHandler;
use TamarackDB\Middleware\ReadMiddleware;
use TamarackDB\Middleware\ReadRequest;

/**
 * One layer of the read chain: hands the call to $middleware, with $next
 * as the layer below it.
 *
 * @internal
 */
final readonly class ReadDelegator implements ReadHandler
{
    public function __construct(
        private ReadMiddleware $middleware,
        private ReadHandler $next,
    ) {}

    public function readEvents(ReadRequest $request): \Generator
    {
        return $this->middleware->readEvents($request, $this->next);
    }
}

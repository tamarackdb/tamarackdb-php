<?php

declare(strict_types=1);

namespace TamarackDB\Internal;

use TamarackDB\Event\AppendedEvent;

/**
 * What POST /write answered: the store ID, each event's Sequence Position
 * and time, and the new version of each created and replaced projection,
 * in request order.
 *
 * @internal
 */
final readonly class WriteResult
{
    /**
     * @param list<AppendedEvent> $events
     * @param list<string> $createVersions
     * @param list<string> $replaceVersions
     */
    public function __construct(
        public string $store,
        public array $events,
        public array $createVersions,
        public array $replaceVersions,
    ) {}
}

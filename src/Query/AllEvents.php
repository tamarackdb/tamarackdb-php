<?php

declare(strict_types=1);

namespace TamarackDB\Query;

/**
 * A query that matches every event.
 *
 *     $client->readEvents(new AllEvents())
 */
final readonly class AllEvents {}

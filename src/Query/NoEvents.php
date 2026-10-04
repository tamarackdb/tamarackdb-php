<?php

declare(strict_types=1);

namespace TamarackDB\Query;

/**
 * A query that matches no event. A decision that rests on no event still
 * reads first in its transaction, with this query.
 *
 *     $client->readEvents(new NoEvents())
 */
final readonly class NoEvents {}

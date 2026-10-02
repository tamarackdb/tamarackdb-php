<?php

declare(strict_types=1);

namespace TamarackDB\Internal;

/**
 * A projection as GET /projections/{type}/{id} answered it.
 *
 * @internal
 */
final readonly class StoredProjection
{
    public function __construct(
        public string $version,
        public string $payload,
    ) {}
}

<?php

declare(strict_types=1);

namespace TamarackDB\Projection;

/**
 * A stored projection. Keep $version to replace or delete it later: it
 * changes on every write. The payload is returned exactly as written.
 */
final readonly class Projection
{
    public function __construct(
        public string $type,
        public string $id,
        public string $version,
        public string $payload,
    ) {}
}

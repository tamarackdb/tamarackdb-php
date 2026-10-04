<?php

declare(strict_types=1);

namespace TamarackDB\Projection;

/**
 * A stored projection. The payload is returned exactly as written.
 *
 * Outside a transaction, $version is the stored version: keep it to replace
 * or delete the projection later with writeProjections(). It changes on
 * every write. Inside a transaction, $version is null: the server keeps the
 * version read.
 */
final readonly class Projection
{
    public function __construct(
        public string $type,
        public string $id,
        public ?string $version,
        public string $payload,
    ) {}
}

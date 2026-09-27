<?php

declare(strict_types=1);

namespace TamarackDB\Projection;

/**
 * The new version of each created and replaced projection, in the order
 * they were added to the ProjectionWrites.
 */
final readonly class ProjectionWriteResult
{
    /**
     * @param list<string> $createVersions
     * @param list<string> $replaceVersions
     */
    public function __construct(
        public array $createVersions,
        public array $replaceVersions,
    ) {}
}

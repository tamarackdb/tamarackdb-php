<?php

declare(strict_types=1);

namespace TamarackDB\Middleware;

use TamarackDB\Exception\InvalidArgumentException;
use TamarackDB\Query\Query;

/**
 * A readEvents() call, as ReadMiddleware sees it. $query is null for a
 * read of every event, and $ticket is null for a read outside any
 * transaction.
 */
final readonly class ReadRequest
{
    public function __construct(
        public ?Query $query,
        public ?int $afterSequence = null,
        public ?\DateTimeInterface $from = null,
        public ?\DateTimeInterface $before = null,
        public ?int $pageSize = null,
        public ?string $ticket = null,
    ) {
        if ($afterSequence !== null && $afterSequence < 0) {
            throw new InvalidArgumentException('afterSequence must not be negative');
        }
        if ($pageSize !== null && $pageSize < 1) {
            throw new InvalidArgumentException('pageSize must be at least 1');
        }
    }

    public function withQuery(?Query $query): self
    {
        return new self($query, $this->afterSequence, $this->from, $this->before, $this->pageSize, $this->ticket);
    }

    public function withAfterSequence(?int $afterSequence): self
    {
        return new self($this->query, $afterSequence, $this->from, $this->before, $this->pageSize, $this->ticket);
    }

    public function withTimeRange(?\DateTimeInterface $from, ?\DateTimeInterface $before): self
    {
        return new self($this->query, $this->afterSequence, $from, $before, $this->pageSize, $this->ticket);
    }

    public function withPageSize(?int $pageSize): self
    {
        return new self($this->query, $this->afterSequence, $this->from, $this->before, $pageSize, $this->ticket);
    }
}

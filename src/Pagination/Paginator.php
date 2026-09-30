<?php

declare(strict_types=1);

namespace Loongs\Orm\Pagination;

use Loongs\Orm\Support\Collection;

/** Length-aware paginator. */
final class Paginator implements \JsonSerializable, \IteratorAggregate, \Countable
{
    public int $lastPage {
        get => max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }

    public bool $hasMorePages {
        get => $this->currentPage < $this->lastPage;
    }

    public ?int $from {
        get => $this->items->isEmpty() ? null : ($this->currentPage - 1) * $this->perPage + 1;
    }

    public ?int $to {
        get => $this->items->isEmpty() ? null : ($this->currentPage - 1) * $this->perPage + $this->items->count();
    }

    public function __construct(
        public readonly Collection $items,
        public readonly int $total,
        public readonly int $perPage,
        public readonly int $currentPage,
    ) {
    }

    public function items(): Collection
    {
        return $this->items;
    }

    public function count(): int
    {
        return $this->items->count();
    }

    public function getIterator(): \Traversable
    {
        return $this->items->getIterator();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'data' => $this->items->toArray(),
            'total' => $this->total,
            'per_page' => $this->perPage,
            'current_page' => $this->currentPage,
            'last_page' => $this->lastPage,
            'from' => $this->from,
            'to' => $this->to,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

<?php

declare(strict_types=1);

namespace Loongs\Orm\Query;

use Closure;

final class JoinClause extends Builder
{
    public function __construct(
        Builder $parent,
        public readonly string $type,
        public readonly string|Expression $table,
    ) {
        parent::__construct($parent->connection);
    }

    /** ON first operator second (column comparison). A Closure builds a nested ON group. */
    public function on(Closure|string $first, ?string $operator = null, ?string $second = null, string $boolean = 'and'): static
    {
        if ($first instanceof Closure) {
            return $this->whereNested($first, $boolean);
        }

        return $this->whereColumn($first, $operator, $second, $boolean);
    }

    public function orOn(Closure|string $first, ?string $operator = null, ?string $second = null): static
    {
        return $this->on($first, $operator, $second, 'or');
    }

    public function newQuery(): static
    {
        return new static($this, $this->type, $this->table);
    }
}

<?php

declare(strict_types=1);

namespace Loongs\Orm\Model;

use Loongs\Orm\Support\Collection as BaseCollection;

/** @extends BaseCollection<int, Model> */
class Collection extends BaseCollection
{
    /** @return list<int|string> */
    public function modelKeys(): array
    {
        return array_values(array_map(static fn (Model $m): mixed => $m->getKey(), $this->items));
    }

    public function find(mixed $key, mixed $default = null): mixed
    {
        $key = $key instanceof Model ? $key->getKey() : $key;
        foreach ($this->items as $m) {
            if ($m->getKey() == $key) {
                return $m;
            }
        }

        return $default;
    }

    /**
     * Eager-load relations for every model here (one query per relation; on the models' connection).
     *
     * @param string|array<int|string, string|\Closure> ...$relations
     */
    public function load(string|array ...$relations): static
    {
        if ($this->items === []) {
            return $this;
        }
        $first = reset($this->items);
        $query = $first->newQueryWithoutScopes()->with(...$relations);
        $this->items = $query->eagerLoadRelations($this->items);

        return $this;
    }

    public function makeHidden(string|array ...$attributes): static
    {
        foreach ($this->items as $m) {
            $m->makeHidden(...$attributes);
        }

        return $this;
    }

    public function makeVisible(string|array ...$attributes): static
    {
        foreach ($this->items as $m) {
            $m->makeVisible(...$attributes);
        }

        return $this;
    }
}

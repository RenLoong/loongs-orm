<?php

declare(strict_types=1);

namespace Loongs\Orm\Model\Relations;

use Loongs\Orm\Model\Builder;
use Loongs\Orm\Model\Collection;
use Loongs\Orm\Model\Model;
use Loongs\Orm\Model\Pivot;
use Loongs\Orm\Query\Builder as QueryBuilder;
use Loongs\Orm\Support\Collection as BaseCollection;

final class BelongsToMany extends Relation
{
    /** @var list<string> */
    private array $pivotColumns = [];

    private ?string $pivotCreatedAt = null;

    private ?string $pivotUpdatedAt = null;

    private bool $joined = false;

    public function __construct(
        Builder $query,
        Model $parent,
        protected string $table,
        protected string $foreignPivotKey,
        protected string $relatedPivotKey,
        protected string $parentKey,
        protected string $relatedKey,
        protected string $relationName,
    ) {
        parent::__construct($query, $parent);
    }

    public function addConstraints(): void
    {
        $this->performJoin();
        $this->query->where($this->table . '.' . $this->foreignPivotKey, '=', $this->parent->getAttribute($this->parentKey));
    }

    public function addEagerConstraints(array $models): void
    {
        $this->performJoin();
        $this->query->whereIn($this->table . '.' . $this->foreignPivotKey, $this->getKeys($models, $this->parentKey));
    }

    private function performJoin(): void
    {
        if ($this->joined) {
            return;
        }
        $this->joined = true;
        $this->query->join($this->table, $this->related->qualifyColumn($this->relatedKey), '=', $this->table . '.' . $this->relatedPivotKey);
    }

    public function withPivot(string ...$columns): static
    {
        $this->pivotColumns = array_values(array_unique(array_merge($this->pivotColumns, $columns)));

        return $this;
    }

    public function withTimestamps(string $createdAt = 'created_at', string $updatedAt = 'updated_at'): static
    {
        $this->pivotCreatedAt = $createdAt;
        $this->pivotUpdatedAt = $updatedAt;

        return $this->withPivot($createdAt, $updatedAt);
    }

    public function get(array $columns = ['*']): Collection
    {
        $b = clone $this->query;
        $base = $b->getQuery();
        if ($base->columns === null) {
            $base->select($columns === ['*'] ? $this->related->getTable() . '.*' : $columns);
        }
        foreach (array_unique([$this->foreignPivotKey, $this->relatedPivotKey, ...$this->pivotColumns]) as $col) {
            $base->addSelect($this->table . '.' . $col . ' as pivot_' . $col);
        }
        $models = $b->get();
        foreach ($models as $m) {
            $attrs = $m->getAttributes();
            $pivot = [];
            foreach ($attrs as $k => $v) {
                if (str_starts_with($k, 'pivot_')) {
                    $pivot[substr($k, 6)] = $v;
                    unset($attrs[$k]);
                }
            }
            $m->setRawAttributes($attrs, true);
            $m->setRelation('pivot', Pivot::fromRaw($this->table, $pivot, $this->parent->getConnectionConfig()));
        }

        return $models;
    }

    public function initRelation(array $models, string $relation): array
    {
        foreach ($models as $m) {
            $m->setRelation($relation, $this->related->newCollection());
        }

        return $models;
    }

    public function match(array $models, Collection $results, string $relation): array
    {
        $dict = [];
        foreach ($results as $r) {
            $dict[(string) $r->getRelation('pivot')->getAttribute($this->foreignPivotKey)][] = $r;
        }
        foreach ($models as $m) {
            $k = (string) $m->getAttribute($this->parentKey);
            if (isset($dict[$k])) {
                $m->setRelation($relation, $this->related->newCollection($dict[$k]));
            }
        }

        return $models;
    }

    public function getResults(): Collection
    {
        return $this->parent->getAttribute($this->parentKey) === null ? $this->related->newCollection() : $this->get();
    }

    /** Pivot table query on the PARENT's connection. */
    public function newPivotQuery(): QueryBuilder
    {
        return $this->parent->getConnection()->table($this->table);
    }

    /** @param array<string, mixed> $attributes */
    public function attach(mixed $ids, array $attributes = []): void
    {
        $rows = [];
        foreach ($this->normaliseIds($ids) as $id => $attrs) {
            $rows[] = $this->pivotRow($id, array_merge($attributes, $attrs));
        }
        // rows may carry different pivot columns (attach([1 => ['level' => 9], 2])): one INSERT per column set
        $groups = [];
        foreach ($rows as $row) {
            ksort($row);
            $groups[implode(',', array_keys($row))][] = $row;
        }
        foreach ($groups as $group) {
            $this->newPivotQuery()->insert($group);
        }
    }

    public function detach(mixed $ids = null): int
    {
        $q = $this->newPivotQuery()->where($this->foreignPivotKey, '=', $this->parent->getAttribute($this->parentKey));
        if ($ids !== null) {
            $list = array_keys($this->normaliseIds($ids));
            if ($list === []) {
                return 0;
            }
            $q->whereIn($this->relatedPivotKey, $list);
        }

        return $q->delete();
    }

    /** @return array{attached: list<int|string>, detached: list<int|string>, updated: list<int|string>} */
    public function sync(mixed $ids, bool $detaching = true): array
    {
        $wanted = $this->normaliseIds($ids);
        $current = $this->newPivotQuery()->where($this->foreignPivotKey, '=', $this->parent->getAttribute($this->parentKey))->pluck($this->relatedPivotKey)->all();
        $currentKeys = array_map('strval', $current);
        $detach = $detaching ? array_values(array_filter($current, static fn ($c): bool => !array_key_exists((string) $c, $wanted))) : [];
        if ($detach !== []) {
            $this->detach($detach);
        }
        $attached = [];
        $updated = [];
        foreach ($wanted as $id => $attrs) {
            if (!in_array((string) $id, $currentKeys, true)) {
                $this->attach([$id => $attrs]);
                $attached[] = $id;
            } elseif ($attrs !== [] && $this->updateExistingPivot($id, $attrs) > 0) {
                $updated[] = $id;
            }
        }

        return ['attached' => $attached, 'detached' => $detach, 'updated' => $updated];
    }

    /** @param array<string, mixed> $attributes */
    public function updateExistingPivot(mixed $id, array $attributes): int
    {
        if ($this->pivotUpdatedAt !== null) {
            $attributes[$this->pivotUpdatedAt] = $this->parent->freshTimestampString();
        }

        return $this->newPivotQuery()
            ->where($this->foreignPivotKey, '=', $this->parent->getAttribute($this->parentKey))
            ->where($this->relatedPivotKey, '=', $id)
            ->update($attributes);
    }

    /** @param array<string, mixed> $attrs @return array<string, mixed> */
    private function pivotRow(int|string $id, array $attrs): array
    {
        $row = [$this->foreignPivotKey => $this->parent->getAttribute($this->parentKey), $this->relatedPivotKey => $id] + $attrs;
        $now = $this->parent->freshTimestampString();
        if ($this->pivotCreatedAt !== null) {
            $row[$this->pivotCreatedAt] ??= $now;
        }
        if ($this->pivotUpdatedAt !== null) {
            $row[$this->pivotUpdatedAt] ??= $now;
        }

        return $row;
    }

    /** @return array<int|string, array<string, mixed>> id => extra pivot attributes */
    private function normaliseIds(mixed $ids): array
    {
        if ($ids instanceof Model) {
            return [$ids->getAttribute($this->relatedKey) => []];
        }
        if ($ids instanceof BaseCollection) {
            $ids = $ids->all();
        }
        $out = [];
        foreach ((array) $ids as $k => $v) {
            if ($v instanceof Model) {
                $out[$v->getAttribute($this->relatedKey)] = [];
            } elseif (is_array($v)) {
                $out[$k] = $v;
            } else {
                $out[$v] = [];
            }
        }

        return $out;
    }
}

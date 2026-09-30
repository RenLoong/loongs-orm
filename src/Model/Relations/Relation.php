<?php

declare(strict_types=1);

namespace Loongs\Orm\Model\Relations;

use Closure;
use Loongs\Orm\Context;
use Loongs\Orm\Model\Builder;
use Loongs\Orm\Model\Collection;
use Loongs\Orm\Model\Model;

/**
 * @mixin Builder
 */
abstract class Relation
{
    /** Coroutine-local flag: build the relation without the per-parent constraint (eager loading). */
    public const string NO_CONSTRAINTS = 'loongs.orm.noConstraints';

    protected Model $related;

    public function __construct(protected Builder $query, protected Model $parent)
    {
        $this->related = $query->getModel();
        if (!Context::get(self::NO_CONSTRAINTS, false)) {
            $this->addConstraints();
        }
    }

    /**
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    public static function noConstraints(Closure $callback): mixed
    {
        return Context::with(self::NO_CONSTRAINTS, true, $callback);
    }

    abstract public function addConstraints(): void;

    /** @param list<Model> $models */
    abstract public function addEagerConstraints(array $models): void;

    /** @param list<Model> $models @return list<Model> */
    abstract public function initRelation(array $models, string $relation): array;

    /** @param list<Model> $models @return list<Model> */
    abstract public function match(array $models, Collection $results, string $relation): array;

    abstract public function getResults(): mixed;

    public function getEager(): Collection
    {
        return $this->get();
    }

    /** @param list<string> $columns */
    public function get(array $columns = ['*']): Collection
    {
        return $this->query->get($columns);
    }

    public function getQuery(): Builder
    {
        return $this->query;
    }

    public function getParent(): Model
    {
        return $this->parent;
    }

    public function getRelated(): Model
    {
        return $this->related;
    }

    /** @param list<Model> $models @return list<int|string> */
    protected function getKeys(array $models, string $key): array
    {
        $keys = [];
        foreach ($models as $m) {
            $v = $m->getAttribute($key);
            if ($v !== null) {
                $keys[(string) $v] = $v;
            }
        }

        return array_values($keys);
    }

    /** @param list<mixed> $parameters */
    public function __call(string $method, array $parameters): mixed
    {
        $result = $this->query->{$method}(...$parameters);

        return $result === $this->query ? $this : $result;
    }

    public function __clone()
    {
        $this->query = clone $this->query;
    }
}

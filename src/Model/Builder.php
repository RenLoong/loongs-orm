<?php

declare(strict_types=1);

namespace Loongs\Orm\Model;

use Closure;
use Generator;
use Loongs\Orm\Connection\ConnectionConfig;
use Loongs\Orm\Exceptions\ModelNotFoundException;
use Loongs\Orm\Exceptions\RelationNotFoundException;
use Loongs\Orm\Pagination\Paginator;
use Loongs\Orm\Query\Builder as QueryBuilder;
use Loongs\Orm\Query\Expression;
use Loongs\Orm\Model\Relations\Relation;

/**
 * Model query builder. Wraps a Query\Builder whose Connection (and thus ConnectionConfig) was
 * fixed when the query was created; every model it returns is bound to that same config, so a
 * later save() / delete() / relation query goes back to the same database.
 *
 * @mixin QueryBuilder
 */
class Builder
{
    /** Methods returned straight from the scoped base query. */
    private const array PASSTHRU = [
        'count', 'sum', 'avg', 'max', 'min', 'exists', 'doesntExist', 'aggregate', 'toSql', 'getBindings',
        'insert', 'insertGetId', 'insertOrIgnore', 'getCountForPagination', 'raw', 'getConnection',
    ];

    protected Model $model;

    /** @var array<string, Closure|null> */
    protected array $eagerLoad = [];

    /** @var array<string, Scope|Closure> */
    protected array $scopes = [];

    /** @var list<string> */
    protected array $removedScopes = [];

    public function __construct(protected QueryBuilder $query)
    {
    }

    public function setModel(Model $model): static
    {
        $this->model = $model;

        return $this;
    }

    public function getModel(): Model
    {
        return $this->model;
    }

    public function getQuery(): QueryBuilder
    {
        return $this->query;
    }

    public function connectionConfig(): ConnectionConfig
    {
        return $this->query->getConnection()->config;
    }

    // ---------------------------------------------------------------- scopes

    public function withGlobalScope(string $id, Scope|Closure $scope): static
    {
        $this->scopes[$id] = $scope;

        return $this;
    }

    public function withoutGlobalScope(string|Scope $scope): static
    {
        $id = is_string($scope) ? $scope : $scope::class;
        $this->removedScopes[] = $id;

        return $this;
    }

    /** @param list<string>|null $scopes */
    public function withoutGlobalScopes(?array $scopes = null): static
    {
        foreach ($scopes ?? array_keys($this->scopes) as $s) {
            $this->removedScopes[] = $s;
        }

        return $this;
    }

    /** Copy of this builder with global scopes applied (scopes are applied at execution time). */
    public function applyScopes(): static
    {
        $b = clone $this;
        $active = array_diff_key($this->scopes, array_flip($this->removedScopes));
        $b->scopes = [];
        if ($active === []) {
            return $b;
        }
        $q = $b->query;
        $hasOr = false;
        foreach ($q->wheres as $w) {
            if (str_starts_with((string) $w['boolean'], 'or')) {
                $hasOr = true;
                break;
            }
        }
        if ($hasOr) {
            // (a or b) and <scope> — keep user conditions grouped before adding scope constraints
            $nested = $q->newQuery();
            $nested->wheres = $q->wheres;
            $nested->bindings['where'] = $q->bindings['where'];
            $q->wheres = [];
            $q->bindings['where'] = [];
            $q->addNestedWhereQuery($nested);
        }
        foreach ($active as $scope) {
            $scope instanceof Scope ? $scope->apply($b, $this->model) : $scope($b);
        }

        return $b;
    }

    public function toBase(): QueryBuilder
    {
        return $this->applyScopes()->query;
    }

    // ---------------------------------------------------------------- where (nested closures get a model builder)

    public function where(Closure|string|array|Expression $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): static
    {
        if ($column instanceof Closure && $operator === null) {
            $nested = (new static($this->query->newQuery()->from($this->query->from ?? $this->model->getTable())))->setModel($this->model);
            $column($nested);
            $this->query->addNestedWhereQuery($nested->getQuery(), $boolean);

            return $this;
        }
        $this->query->where(...func_get_args());

        return $this;
    }

    public function orWhere(Closure|string|array|Expression $column, mixed $operator = null, mixed $value = null): static
    {
        if ($column instanceof Closure && $operator === null) {
            return $this->where($column, null, null, 'or');
        }
        $this->query->orWhere(...func_get_args());

        return $this;
    }

    public function whereKey(mixed $id): static
    {
        return is_array($id) ? $this->whereIn($this->model->getQualifiedKeyName(), $id) : $this->where($this->model->getQualifiedKeyName(), '=', $id);
    }

    // ---------------------------------------------------------------- retrieval

    /** @param list<string|Expression> $columns */
    public function find(mixed $id, array $columns = ['*']): Model|Collection|null
    {
        if (is_array($id)) {
            return $this->findMany($id, $columns);
        }

        return (clone $this)->whereKey($id)->first($columns);
    }

    /** @param list<mixed> $ids @param list<string|Expression> $columns */
    public function findMany(array $ids, array $columns = ['*']): Collection
    {
        return $ids === [] ? $this->model->newCollection() : (clone $this)->whereKey($ids)->get($columns);
    }

    /** @param list<string|Expression> $columns */
    public function findOrFail(mixed $id, array $columns = ['*']): Model|Collection
    {
        $result = $this->find($id, $columns);
        $ids = is_array($id) ? $id : [$id];
        if ($result === null || ($result instanceof Collection && $result->count() !== count(array_unique($ids)))) {
            throw new ModelNotFoundException($this->model::class, array_values($ids));
        }

        return $result;
    }

    /** @param list<string|Expression> $columns */
    public function findOrNew(mixed $id, array $columns = ['*']): Model
    {
        return $this->find($id, $columns) ?? $this->newModelInstance();
    }

    /** @param list<string|Expression> $columns */
    public function first(array $columns = ['*']): ?Model
    {
        return (clone $this)->limit(1)->get($columns)->first();
    }

    /** @param list<string|Expression> $columns */
    public function firstOrFail(array $columns = ['*']): Model
    {
        return $this->first($columns) ?? throw new ModelNotFoundException($this->model::class);
    }

    public function firstWhere(string $column, mixed $operator = null, mixed $value = null): ?Model
    {
        return (clone $this)->where(...func_get_args())->first();
    }

    /** @param list<string|Expression> $columns */
    public function get(array $columns = ['*']): Collection
    {
        $b = $this->applyScopes();
        $models = $b->hydrate($b->query->runSelect($columns));
        if ($models !== [] && $this->eagerLoad !== []) {
            $models = $this->eagerLoadRelations($models);
        }

        return $this->model->newCollection($models);
    }

    /** @param list<array<string, mixed>> $rows @return list<Model> */
    public function hydrate(array $rows): array
    {
        $conn = $this->connectionConfig();

        return array_map(fn (array $row): Model => $this->model->newFromBuilder($row, $conn), $rows);
    }

    /** @return Generator<int, Model> one connection held while iterating; no eager loading */
    public function cursor(): Generator
    {
        $b = $this->applyScopes();
        $conn = $this->connectionConfig();
        foreach ($b->query->cursor() as $row) {
            yield $this->model->newFromBuilder($row, $conn);
        }
    }

    /** @param callable(Collection, int): (bool|void) $callback */
    public function chunk(int $count, callable $callback): bool
    {
        $b = clone $this;
        if ($b->query->orders === []) {
            $b->query->orderBy($this->model->getQualifiedKeyName());
        }
        for ($page = 1; ; $page++) {
            $models = (clone $b)->forPage($page, $count)->get();
            if ($models->isEmpty()) {
                return true;
            }
            if ($callback($models, $page) === false) {
                return false;
            }
            if ($models->count() < $count) {
                return true;
            }
        }
    }

    /** @param callable(Collection, int): (bool|void) $callback */
    public function chunkById(int $count, callable $callback, ?string $column = null): bool
    {
        $column ??= $this->model->getQualifiedKeyName();
        $alias = QueryBuilder::stripColumn($column);
        $last = null;
        for ($page = 1; ; $page++) {
            $b = clone $this;
            $b->query->reorder()->orderBy($column)->limit($count);
            if ($last !== null) {
                $b->query->where($column, '>', $last);
            }
            $models = $b->get();
            if ($models->isEmpty()) {
                return true;
            }
            if ($callback($models, $page) === false) {
                return false;
            }
            $last = $models->last()->getAttribute($alias);
            if ($models->count() < $count) {
                return true;
            }
        }
    }

    /** @param callable(Model, int): (bool|void) $callback */
    public function each(callable $callback, int $count = 1000): bool
    {
        return $this->chunk($count, static function (Collection $models) use ($callback): bool {
            foreach ($models as $key => $model) {
                if ($callback($model, $key) === false) {
                    return false;
                }
            }

            return true;
        });
    }

    /** @param list<string|Expression> $columns */
    public function paginate(?int $perPage = null, int $page = 1, array $columns = ['*']): Paginator
    {
        $perPage ??= $this->model->getPerPage();
        $page = max(1, $page);
        $total = $this->toBase()->getCountForPagination();
        $items = $total > 0 ? (clone $this)->forPage($page, $perPage)->get($columns) : $this->model->newCollection();

        return new Paginator($items, $total, $perPage, $page);
    }

    public function value(string $column): mixed
    {
        return $this->first([$column])?->getAttribute(QueryBuilder::stripColumn($column));
    }

    public function pluck(string $column, ?string $key = null): \Loongs\Orm\Support\Collection
    {
        return $this->toBase()->pluck($column, $key);
    }

    // ---------------------------------------------------------------- writes

    /** @param array<string, mixed> $attributes */
    public function newModelInstance(array $attributes = []): Model
    {
        return $this->model->newInstance($attributes)->setConnection($this->connectionConfig());
    }

    /** @param array<string, mixed> $attributes */
    public function make(array $attributes = []): Model
    {
        return $this->newModelInstance($attributes);
    }

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes = []): Model
    {
        $model = $this->newModelInstance($attributes);
        $model->save();

        return $model;
    }

    /** @param array<string, mixed> $attributes */
    public function forceCreate(array $attributes): Model
    {
        $model = $this->newModelInstance()->forceFill($attributes);
        $model->save();

        return $model;
    }

    /** @param array<string, mixed> $attributes @param array<string, mixed> $values */
    public function firstOrNew(array $attributes = [], array $values = []): Model
    {
        return (clone $this)->where($attributes)->first() ?? $this->newModelInstance(array_merge($attributes, $values));
    }

    /** @param array<string, mixed> $attributes @param array<string, mixed> $values */
    public function firstOrCreate(array $attributes = [], array $values = []): Model
    {
        return (clone $this)->where($attributes)->first() ?? $this->create(array_merge($attributes, $values));
    }

    /** @param array<string, mixed> $attributes @param array<string, mixed> $values */
    public function updateOrCreate(array $attributes, array $values = []): Model
    {
        $model = $this->firstOrNew($attributes);
        $model->fill($values)->save();

        return $model;
    }

    /** @param array<string, mixed> $values */
    public function update(array $values): int
    {
        return $this->toBase()->update($this->addUpdatedAt($values));
    }

    /** @param array<string, mixed> $extra */
    public function increment(string $column, int|float $amount = 1, array $extra = []): int
    {
        return $this->toBase()->increment($column, $amount, $this->addUpdatedAt($extra));
    }

    /** @param array<string, mixed> $extra */
    public function decrement(string $column, int|float $amount = 1, array $extra = []): int
    {
        return $this->toBase()->decrement($column, $amount, $this->addUpdatedAt($extra));
    }

    /** Soft-deleting models: sets deleted_at; others: DELETE. */
    public function delete(): int
    {
        if ($this->usesSoftDeletes()) {
            return $this->update([$this->model->getDeletedAtColumn() => $this->model->freshTimestampString()]);
        }

        return $this->toBase()->delete();
    }

    public function forceDelete(): int
    {
        return $this->toBase()->delete();
    }

    public function withTrashed(bool $withTrashed = true): static
    {
        return $withTrashed ? $this->withoutGlobalScope(SoftDeletingScope::class) : $this;
    }

    public function withoutTrashed(): static
    {
        return $this;
    }

    public function onlyTrashed(): static
    {
        $this->withoutGlobalScope(SoftDeletingScope::class);
        $this->query->whereNotNull($this->model->getQualifiedDeletedAtColumn());

        return $this;
    }

    public function restore(): int
    {
        return (clone $this)->withTrashed()->toBase()->update($this->addUpdatedAt([$this->model->getDeletedAtColumn() => null]));
    }

    private function usesSoftDeletes(): bool
    {
        return method_exists($this->model, 'getDeletedAtColumn');
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private function addUpdatedAt(array $values): array
    {
        $col = $this->model::UPDATED_AT;
        if ($this->model->usesTimestamps() && $col !== null && !array_key_exists($col, $values)) {
            $values[$col] = $this->model->freshTimestampString();
        }

        return $values;
    }

    // ---------------------------------------------------------------- eager loading

    /** with('posts', 'posts.comments', ['roles' => fn ($q) => $q->where(…)]) */
    public function with(string|array ...$relations): static
    {
        foreach ($relations as $rel) {
            foreach (is_array($rel) ? $rel : [$rel] as $name => $constraint) {
                if (is_int($name)) {
                    [$name, $constraint] = [(string) $constraint, null];
                }
                $parts = explode('.', $name);
                for ($i = 1; $i < count($parts); $i++) {
                    $parent = implode('.', array_slice($parts, 0, $i));
                    $this->eagerLoad[$parent] ??= null;
                }
                $this->eagerLoad[$name] = $constraint;
            }
        }

        return $this;
    }

    public function without(string ...$relations): static
    {
        foreach ($relations as $r) {
            foreach (array_keys($this->eagerLoad) as $k) {
                if ($k === $r || str_starts_with($k, $r . '.')) {
                    unset($this->eagerLoad[$k]);
                }
            }
        }

        return $this;
    }

    /** @return array<string, Closure|null> */
    public function getEagerLoads(): array
    {
        return $this->eagerLoad;
    }

    /** @param list<Model> $models @return list<Model> */
    public function eagerLoadRelations(array $models): array
    {
        foreach ($this->eagerLoad as $name => $constraint) {
            if (!str_contains($name, '.')) {
                $models = $this->eagerLoadRelation($models, $name, $constraint);
            }
        }

        return $models;
    }

    /** @param list<Model> $models @return list<Model> */
    protected function eagerLoadRelation(array $models, string $name, ?Closure $constraint): array
    {
        $relation = $this->getRelation($name);
        $relation->addEagerConstraints($models);
        if ($constraint !== null) {
            $constraint($relation);
        }
        $models = $relation->initRelation($models, $name);

        return $relation->match($models, $relation->getEager(), $name);
    }

    /** Unconstrained relation, on this builder's connection, with nested eager loads attached. */
    public function getRelation(string $name): Relation
    {
        $parent = $this->model->newInstance()->setConnection($this->connectionConfig());
        $relation = Relation::noConstraints(static function () use ($parent, $name): mixed {
            if (!$parent->isRelation($name)) {
                throw RelationNotFoundException::make($parent, $name);
            }

            return (fn () => $this->{$name}())->call($parent);
        });
        if (!$relation instanceof Relation) {
            throw RelationNotFoundException::make($parent, $name);
        }
        $nested = [];
        foreach ($this->eagerLoad as $k => $c) {
            if (str_starts_with($k, $name . '.')) {
                $nested[substr($k, strlen($name) + 1)] = $c;
            }
        }
        if ($nested !== []) {
            $relation->getQuery()->with($nested);
        }

        return $relation;
    }

    // ---------------------------------------------------------------- forwarding

    /** @param list<mixed> $parameters */
    public function __call(string $method, array $parameters): mixed
    {
        $scope = 'scope' . ucfirst($method);
        if (method_exists($this->model, $scope)) {
            $builder = $this;
            // local scope scopeX(Builder $query, ...$args); may be protected
            $result = (fn () => $this->{$scope}($builder, ...$parameters))->call($this->model);

            return $result instanceof self ? $result : $this;
        }
        if (in_array($method, self::PASSTHRU, true)) {
            return $this->toBase()->{$method}(...$parameters);
        }
        $result = $this->query->{$method}(...$parameters);

        return $result === $this->query ? $this : $result;
    }

    public function __clone()
    {
        $this->query = clone $this->query;
    }
}

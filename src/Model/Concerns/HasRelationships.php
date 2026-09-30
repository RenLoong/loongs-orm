<?php

declare(strict_types=1);

namespace Loongs\Orm\Model\Concerns;

use Loongs\Orm\Exceptions\RelationNotFoundException;
use Loongs\Orm\Model\Model;
use Loongs\Orm\Model\Relations\BelongsTo;
use Loongs\Orm\Model\Relations\BelongsToMany;
use Loongs\Orm\Model\Relations\HasMany;
use Loongs\Orm\Model\Relations\HasOne;
use Loongs\Orm\Model\Relations\Relation;
use Loongs\Orm\Support\Inflector;
use ReflectionMethod;
use ReflectionNamedType;

trait HasRelationships
{
    /** @var array<string, mixed> loaded relations of THIS instance */
    protected array $relations = [];

    /** @param class-string<Model> $related */
    public function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): HasOne
    {
        $instance = $this->newRelatedInstance($related);

        return new HasOne($instance->newQuery(), $this, $instance->getTable() . '.' . ($foreignKey ?? $this->getForeignKey()), $localKey ?? $this->getKeyName());
    }

    /** @param class-string<Model> $related */
    public function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): HasMany
    {
        $instance = $this->newRelatedInstance($related);

        return new HasMany($instance->newQuery(), $this, $instance->getTable() . '.' . ($foreignKey ?? $this->getForeignKey()), $localKey ?? $this->getKeyName());
    }

    /** @param class-string<Model> $related */
    public function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null, ?string $relation = null): BelongsTo
    {
        $relation ??= debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? 'related';
        $instance = $this->newRelatedInstance($related);
        $ownerKey ??= $instance->getKeyName();
        $foreignKey ??= Inflector::snake($relation) . '_' . $ownerKey;

        return new BelongsTo($instance->newQuery(), $this, $foreignKey, $ownerKey, $relation);
    }

    /** @param class-string<Model> $related */
    public function belongsToMany(
        string $related,
        ?string $table = null,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
        ?string $parentKey = null,
        ?string $relatedKey = null,
        ?string $relation = null,
    ): BelongsToMany {
        $relation ??= debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? 'related';
        $instance = $this->newRelatedInstance($related);
        if ($table === null) {
            $names = [Inflector::snake(Inflector::classBasename($this)), Inflector::snake(Inflector::classBasename($instance))];
            sort($names);
            $table = implode('_', $names);
        }

        return new BelongsToMany(
            $instance->newQuery(),
            $this,
            $table,
            $foreignPivotKey ?? $this->getForeignKey(),
            $relatedPivotKey ?? $instance->getForeignKey(),
            $parentKey ?? $this->getKeyName(),
            $relatedKey ?? $instance->getKeyName(),
            $relation,
        );
    }

    /**
     * Related model instance. Unless the related class declares its own $connection (e.g. a
     * central-database model), it runs on THIS model's connection — the tenant it was loaded from.
     *
     * @template T of Model
     * @param class-string<T> $class
     * @return T
     */
    protected function newRelatedInstance(string $class): Model
    {
        $instance = new $class();
        if (!$instance->hasDeclaredConnection()) {
            $instance->setConnection($this->getConnectionConfig());
        }

        return $instance;
    }

    public function isRelation(string $key): bool
    {
        if (!method_exists($this, $key)) {
            return false;
        }
        $rm = new ReflectionMethod($this, $key);
        $orm = dirname(__DIR__, 2);
        if ($rm->isStatic() || $rm->getNumberOfRequiredParameters() > 0 || str_starts_with((string) $rm->getFileName(), $orm)) {
            return false;
        }
        $type = $rm->getReturnType();
        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            return is_a($type->getName(), Relation::class, true);
        }

        return $type === null;
    }

    protected function getRelationshipFromMethod(string $method): mixed
    {
        $relation = (fn () => $this->{$method}())->call($this);
        if (!$relation instanceof Relation) {
            throw RelationNotFoundException::make($this, $method);
        }
        $results = $relation->getResults();
        $this->setRelation($method, $results);

        return $results;
    }

    public function relationLoaded(string $key): bool
    {
        return array_key_exists($key, $this->relations);
    }

    public function getRelation(string $key): mixed
    {
        return $this->relations[$key] ?? null;
    }

    public function setRelation(string $key, mixed $value): static
    {
        $this->relations[$key] = $value;

        return $this;
    }

    public function unsetRelation(string $key): static
    {
        unset($this->relations[$key]);

        return $this;
    }

    /** @return array<string, mixed> */
    public function getRelations(): array
    {
        return $this->relations;
    }

    /** @param array<string, mixed> $relations */
    public function setRelations(array $relations): static
    {
        $this->relations = $relations;

        return $this;
    }

    /** Eager-load relations onto this instance (runs on this model's connection). */
    public function load(string|array ...$relations): static
    {
        $this->newCollection([$this])->load(...$relations);

        return $this;
    }

    public function loadMissing(string ...$relations): static
    {
        $missing = array_values(array_filter($relations, fn (string $r): bool => !$this->relationLoaded(explode('.', $r)[0])));

        return $missing === [] ? $this : $this->load(...$missing);
    }

    /** @return array<string, mixed> */
    public function relationsToArray(): array
    {
        $out = [];
        foreach ($this->relations as $key => $value) {
            $out[Inflector::snake($key)] = match (true) {
                $value === null => null,
                is_object($value) && method_exists($value, 'toArray') => $value->toArray(),
                default => $value,
            };
        }

        return $out;
    }
}

<?php

declare(strict_types=1);

namespace Loongs\Orm\Model\Concerns;

use Loongs\Orm\Exceptions\RelationNotFoundException;
use Loongs\Orm\Model\Model;
use Loongs\Orm\Model\Relations\BelongsTo;
use Loongs\Orm\Model\Relations\BelongsToMany;
use Loongs\Orm\Model\Relations\HasMany;
use Loongs\Orm\Model\Relations\HasManyThrough;
use Loongs\Orm\Model\Relations\HasOne;
use Loongs\Orm\Model\Relations\HasOneThrough;
use Loongs\Orm\Model\Relations\MorphMany;
use Loongs\Orm\Model\Relations\MorphOne;
use Loongs\Orm\Model\Relations\MorphTo;
use Loongs\Orm\Model\Relations\MorphToMany;
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
     * Inverse polymorphic: {name}_type + {name}_id on this model. $name defaults to the calling
     * method's name (commentable() → commentable_type / commentable_id).
     */
    public function morphTo(?string $name = null, ?string $type = null, ?string $id = null, ?string $ownerKey = null): MorphTo
    {
        $name ??= debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? 'morphable';
        $type ??= Inflector::snake($name) . '_type';
        $id ??= Inflector::snake($name) . '_id';
        $class = $this->getAttribute($type);
        if ($class === null || $class === '') {
            // eager loading (built on an empty instance) or no target: placeholder, no query of its own
            $query = $this->newQueryWithoutScopes()->setEagerLoads([]);

            return new MorphTo($query, $this, $id, $ownerKey, $type, $name, true);
        }
        $instance = $this->newRelatedInstance(Relation::resolveMorphType((string) $class));

        return new MorphTo($instance->newQuery(), $this, $id, $ownerKey, $type, $name, false);
    }

    /**
     * Polymorphic one-to-one: related table has {name}_type / {name}_id.
     *
     * @param class-string<Model> $related
     */
    public function morphOne(string $related, string $name, ?string $type = null, ?string $id = null, ?string $localKey = null): MorphOne
    {
        $instance = $this->newRelatedInstance($related);
        $table = $instance->getTable();

        return new MorphOne($instance->newQuery(), $this, $table . '.' . ($type ?? $name . '_type'), $this->getMorphClass(), $table . '.' . ($id ?? $name . '_id'), $localKey ?? $this->getKeyName());
    }

    /**
     * Polymorphic one-to-many: $post->comments via comments.commentable_type / commentable_id.
     *
     * @param class-string<Model> $related
     */
    public function morphMany(string $related, string $name, ?string $type = null, ?string $id = null, ?string $localKey = null): MorphMany
    {
        $instance = $this->newRelatedInstance($related);
        $table = $instance->getTable();

        return new MorphMany($instance->newQuery(), $this, $table . '.' . ($type ?? $name . '_type'), $this->getMorphClass(), $table . '.' . ($id ?? $name . '_id'), $localKey ?? $this->getKeyName());
    }

    /**
     * Polymorphic many-to-many from the typed side: $post->tags() via taggables
     * (tag_id, taggable_id, taggable_type). Defaults: table = plural($name), foreign pivot key
     * {name}_id, related pivot key tag_id.
     *
     * @param class-string<Model> $related
     */
    public function morphToMany(
        string $related,
        string $name,
        ?string $table = null,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
        ?string $parentKey = null,
        ?string $relatedKey = null,
        ?string $relation = null,
    ): MorphToMany {
        $relation ??= debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? 'related';
        $instance = $this->newRelatedInstance($related);

        return new MorphToMany(
            $instance->newQuery(), $this, $name . '_type', $this->getMorphClass(), $table ?? Inflector::plural($name),
            $foreignPivotKey ?? $name . '_id', $relatedPivotKey ?? $instance->getForeignKey(),
            $parentKey ?? $this->getKeyName(), $relatedKey ?? $instance->getKeyName(), $relation, false,
        );
    }

    /**
     * Inverse of morphToMany: $tag->posts() — pivot rows whose {name}_type is Post's morph class.
     *
     * @param class-string<Model> $related
     */
    public function morphedByMany(
        string $related,
        string $name,
        ?string $table = null,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
        ?string $parentKey = null,
        ?string $relatedKey = null,
        ?string $relation = null,
    ): MorphToMany {
        $relation ??= debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? 'related';
        $instance = $this->newRelatedInstance($related);

        return new MorphToMany(
            $instance->newQuery(), $this, $name . '_type', $instance->getMorphClass(), $table ?? Inflector::plural($name),
            $foreignPivotKey ?? $this->getForeignKey(), $relatedPivotKey ?? $name . '_id',
            $parentKey ?? $this->getKeyName(), $relatedKey ?? $instance->getKeyName(), $relation, true,
        );
    }

    /**
     * Far relation through an intermediate model: Country::posts() through User.
     * Keys: $firstKey on the through table (users.country_id), $secondKey on the related table
     * (posts.user_id), $localKey on this model (countries.id), $secondLocalKey on the through model (users.id).
     *
     * @param class-string<Model> $related
     * @param class-string<Model> $through
     */
    public function hasManyThrough(string $related, string $through, ?string $firstKey = null, ?string $secondKey = null, ?string $localKey = null, ?string $secondLocalKey = null): HasManyThrough
    {
        $throughInstance = $this->newRelatedInstance($through);
        $instance = $this->newRelatedInstance($related);

        return new HasManyThrough(
            $instance->newQuery(), $this, $throughInstance,
            $firstKey ?? $this->getForeignKey(), $secondKey ?? $throughInstance->getForeignKey(),
            $localKey ?? $this->getKeyName(), $secondLocalKey ?? $throughInstance->getKeyName(),
        );
    }

    /**
     * @param class-string<Model> $related
     * @param class-string<Model> $through
     */
    public function hasOneThrough(string $related, string $through, ?string $firstKey = null, ?string $secondKey = null, ?string $localKey = null, ?string $secondLocalKey = null): HasOneThrough
    {
        $throughInstance = $this->newRelatedInstance($through);
        $instance = $this->newRelatedInstance($related);

        return new HasOneThrough(
            $instance->newQuery(), $this, $throughInstance,
            $firstKey ?? $this->getForeignKey(), $secondKey ?? $throughInstance->getForeignKey(),
            $localKey ?? $this->getKeyName(), $secondLocalKey ?? $throughInstance->getKeyName(),
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

<?php

declare(strict_types=1);

namespace Loongs\Orm\Model\Relations;

use Closure;
use Loongs\Orm\Model\Builder;
use Loongs\Orm\Model\Collection;
use Loongs\Orm\Model\Model;

/**
 * Inverse polymorphic relation: $comment->commentable is a Post, a Video, … depending on
 * commentable_type. Lookups run on the child's connection (its tenant) unless the target class
 * declares its own $connection.
 *
 * Eager loading (Comment::on($t)->with('commentable')) runs ONE query per distinct type. Builder
 * calls made in an eager constraint (with(['commentable' => fn ($q) => $q->withoutGlobalScopes()]))
 * are replayed on every type's query; per-type constraints / nested loads: constrain([...]) and
 * morphWith([...]).
 */
final class MorphTo extends Relation
{
    /** @var array<string, array<string, list<Model>>> type => (string) id => child models (eager) */
    private array $dictionary = [];

    /** @var array<string, array<string, Model>> type => (string) owner key => result (eager) */
    private array $resultsByType = [];

    /** @var list<array{string, list<mixed>}> builder calls replayed on every type's eager query */
    private array $macroBuffer = [];

    /** @var array<class-string, Closure> */
    private array $typeConstraints = [];

    /** @var array<class-string, list<string>> */
    private array $typeWith = [];

    /** @param bool $placeholder true when built without a concrete type (eager loading / empty type) */
    public function __construct(
        Builder $query,
        Model $child,
        protected string $foreignKey,
        protected ?string $ownerKey,
        protected string $morphType,
        protected string $relationName,
        private readonly bool $placeholder,
    ) {
        parent::__construct($query, $child);
    }

    public function addConstraints(): void
    {
        if ($this->placeholder) {
            return;
        }
        $this->query->where($this->related->qualifyColumn($this->ownerKey ?? $this->related->getKeyName()), '=', $this->parent->getAttribute($this->foreignKey));
    }

    public function addEagerConstraints(array $models): void
    {
        foreach ($models as $m) {
            $type = $m->getAttribute($this->morphType);
            $id = $m->getAttribute($this->foreignKey);
            if ($type !== null && $type !== '' && $id !== null) {
                $this->dictionary[(string) $type][(string) $id][] = $m;
            }
        }
    }

    public function initRelation(array $models, string $relation): array
    {
        foreach ($models as $m) {
            $m->setRelation($relation, null);
        }

        return $models;
    }

    /** One query per type, on the connection of the models being loaded. */
    public function getEager(): Collection
    {
        $all = [];
        $this->resultsByType = [];
        foreach ($this->dictionary as $type => $byId) {
            $class = Relation::resolveMorphType($type);
            $instance = $this->newRelatedFor($class);
            $ownerKey = $this->ownerKey ?? $instance->getKeyName();
            $query = $instance->newQuery();
            $nested = array_merge($this->query->getEagerLoads(), array_fill_keys($this->typeWith[$class] ?? [], null));
            if ($nested !== []) {
                $query->with($nested);
            }
            foreach ($this->macroBuffer as [$method, $parameters]) {
                $query->{$method}(...$parameters);
            }
            if (isset($this->typeConstraints[$class])) {
                ($this->typeConstraints[$class])($query);
            }
            $fk = $this->foreignKey;
            $ids = array_map(static fn (array $children): mixed => $children[0]->getAttribute($fk), array_values($byId));
            foreach ($query->whereIn($instance->qualifyColumn($ownerKey), $ids)->get() as $result) {
                $this->resultsByType[$type][(string) $result->getAttribute($ownerKey)] = $result;
                $all[] = $result;
            }
        }

        return new Collection($all);
    }

    public function match(array $models, Collection $results, string $relation): array
    {
        foreach ($this->dictionary as $type => $byId) {
            foreach ($byId as $id => $children) {
                $result = $this->resultsByType[$type][$id] ?? null;
                if ($result !== null) {
                    foreach ($children as $child) {
                        $child->setRelation($relation, $result);
                    }
                }
            }
        }

        return $models;
    }

    public function getResults(): ?Model
    {
        if ($this->placeholder || $this->parent->getAttribute($this->foreignKey) === null) {
            return null;
        }

        return $this->query->first();
    }

    /** Point the child at $model: sets {name}_id and {name}_type (morph alias). Does not save. */
    public function associate(?Model $model): Model
    {
        if ($model === null) {
            return $this->dissociate();
        }
        $this->parent->setAttribute($this->foreignKey, $model->getAttribute($this->ownerKey ?? $model->getKeyName()));
        $this->parent->setAttribute($this->morphType, $model->getMorphClass());
        $this->parent->setRelation($this->relationName, $model);

        return $this->parent;
    }

    /** Clear {name}_id and {name}_type. Does not save. */
    public function dissociate(): Model
    {
        $this->parent->setAttribute($this->foreignKey, null);
        $this->parent->setAttribute($this->morphType, null);
        $this->parent->setRelation($this->relationName, null);

        return $this->parent;
    }

    /**
     * Per-type eager constraints: ->constrain([Post::class => fn (Builder $q) => $q->where(…)]).
     *
     * @param array<class-string, Closure> $constraints
     */
    public function constrain(array $constraints): static
    {
        $this->typeConstraints = array_merge($this->typeConstraints, $constraints);

        return $this;
    }

    /**
     * Per-type nested eager loads: ->morphWith([Post::class => ['author'], Video::class => ['channel']]).
     *
     * @param array<class-string, list<string>|string> $with
     */
    public function morphWith(array $with): static
    {
        foreach ($with as $class => $relations) {
            $this->typeWith[$class] = array_values(array_unique(array_merge($this->typeWith[$class] ?? [], (array) $relations)));
        }

        return $this;
    }

    public function getMorphType(): string
    {
        return $this->morphType;
    }

    public function getForeignKeyName(): string
    {
        return $this->foreignKey;
    }

    /** Target instance on the child's connection unless the target class declares its own. */
    private function newRelatedFor(string $class): Model
    {
        $instance = new $class();
        if (!$instance->hasDeclaredConnection()) {
            $instance->setConnection($this->parent->getConnectionConfig());
        }

        return $instance;
    }

    /** @param list<mixed> $parameters */
    public function __call(string $method, array $parameters): mixed
    {
        if ($this->placeholder) {
            // eager-constraint calls: replayed on each type's query in getEager()
            $this->macroBuffer[] = [$method, $parameters];

            return $this;
        }

        return parent::__call($method, $parameters);
    }
}

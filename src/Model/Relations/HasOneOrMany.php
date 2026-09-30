<?php

declare(strict_types=1);

namespace Loongs\Orm\Model\Relations;

use Loongs\Orm\Model\Builder;
use Loongs\Orm\Model\Collection;
use Loongs\Orm\Model\Model;
use Loongs\Orm\Query\Builder as QueryBuilder;

abstract class HasOneOrMany extends Relation
{
    /** @param string $foreignKey qualified related.fk */
    public function __construct(Builder $query, Model $parent, protected string $foreignKey, protected string $localKey)
    {
        parent::__construct($query, $parent);
    }

    public function addConstraints(): void
    {
        $this->query->where($this->foreignKey, '=', $this->getParentKey());
        $this->query->whereNotNull($this->foreignKey);
    }

    public function addEagerConstraints(array $models): void
    {
        $this->query->whereIn($this->foreignKey, $this->getKeys($models, $this->localKey));
    }

    public function getParentKey(): mixed
    {
        return $this->parent->getAttribute($this->localKey);
    }

    public function getForeignKeyName(): string
    {
        return QueryBuilder::stripColumn($this->foreignKey);
    }

    /** @param list<Model> $models @return list<Model> */
    protected function matchOneOrMany(array $models, Collection $results, string $relation, bool $one): array
    {
        $fk = $this->getForeignKeyName();
        $dict = [];
        foreach ($results as $r) {
            $dict[(string) $r->getAttribute($fk)][] = $r;
        }
        foreach ($models as $m) {
            $key = (string) $m->getAttribute($this->localKey);
            if (isset($dict[$key])) {
                $m->setRelation($relation, $one ? $dict[$key][0] : $this->related->newCollection($dict[$key]));
            }
        }

        return $models;
    }

    /** Save a related model with the foreign key set — on the parent's connection unless it declares its own. */
    public function save(Model $model): Model
    {
        $model->setAttribute($this->getForeignKeyName(), $this->getParentKey());
        if ($model->getBoundConnection() === null && !$model->hasDeclaredConnection()) {
            $model->setConnection($this->query->connectionConfig());
        }
        $model->save();

        return $model;
    }

    /** @param array<string, mixed> $attributes */
    public function make(array $attributes = []): Model
    {
        $model = $this->related->newInstance($attributes)->setConnection($this->query->connectionConfig());
        $model->setAttribute($this->getForeignKeyName(), $this->getParentKey());

        return $model;
    }

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes = []): Model
    {
        $model = $this->make($attributes);
        $model->save();

        return $model;
    }

    /** @param list<array<string, mixed>> $records */
    public function createMany(array $records): Collection
    {
        return $this->related->newCollection(array_map($this->create(...), $records));
    }
}

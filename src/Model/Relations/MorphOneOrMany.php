<?php

declare(strict_types=1);

namespace Loongs\Orm\Model\Relations;

use Loongs\Orm\Model\Builder;
use Loongs\Orm\Model\Model;
use Loongs\Orm\Query\Builder as QueryBuilder;

/** hasOne / hasMany plus a {name}_type = parent morph class constraint. */
abstract class MorphOneOrMany extends HasOneOrMany
{
    /**
     * @param string $morphType qualified related.{name}_type
     * @param string $morphClass value stored in the type column for the parent (morph alias or class)
     */
    public function __construct(Builder $query, Model $parent, protected string $morphType, protected string $morphClass, string $foreignKey, string $localKey)
    {
        parent::__construct($query, $parent, $foreignKey, $localKey);
    }

    public function addConstraints(): void
    {
        parent::addConstraints();
        $this->query->where($this->morphType, '=', $this->morphClass);
    }

    public function addEagerConstraints(array $models): void
    {
        parent::addEagerConstraints($models);
        $this->query->where($this->morphType, '=', $this->morphClass);
    }

    public function getMorphType(): string
    {
        return QueryBuilder::stripColumn($this->morphType);
    }

    public function getMorphClass(): string
    {
        return $this->morphClass;
    }

    public function save(Model $model): Model
    {
        $model->setAttribute($this->getMorphType(), $this->morphClass);

        return parent::save($model);
    }

    public function make(array $attributes = []): Model
    {
        $model = parent::make($attributes);
        $model->setAttribute($this->getMorphType(), $this->morphClass);

        return $model;
    }
}

<?php

declare(strict_types=1);

namespace Loongs\Orm\Model\Relations;

use Loongs\Orm\Model\Builder;
use Loongs\Orm\Model\Collection;
use Loongs\Orm\Model\Model;

final class BelongsTo extends Relation
{
    /** @param Model $child the model holding the foreign key */
    public function __construct(
        Builder $query,
        protected Model $child,
        protected string $foreignKey,
        protected string $ownerKey,
        protected string $relationName,
    ) {
        parent::__construct($query, $child);
    }

    public function addConstraints(): void
    {
        $this->query->where($this->related->qualifyColumn($this->ownerKey), '=', $this->child->getAttribute($this->foreignKey));
    }

    public function addEagerConstraints(array $models): void
    {
        $this->query->whereIn($this->related->qualifyColumn($this->ownerKey), $this->getKeys($models, $this->foreignKey));
    }

    public function initRelation(array $models, string $relation): array
    {
        foreach ($models as $m) {
            $m->setRelation($relation, null);
        }

        return $models;
    }

    public function match(array $models, Collection $results, string $relation): array
    {
        $dict = [];
        foreach ($results as $r) {
            $dict[(string) $r->getAttribute($this->ownerKey)] = $r;
        }
        foreach ($models as $m) {
            $k = $m->getAttribute($this->foreignKey);
            if ($k !== null && isset($dict[(string) $k])) {
                $m->setRelation($relation, $dict[(string) $k]);
            }
        }

        return $models;
    }

    public function getResults(): ?Model
    {
        return $this->child->getAttribute($this->foreignKey) === null ? null : $this->query->first();
    }

    public function associate(Model|int|string|null $model): Model
    {
        $this->child->setAttribute($this->foreignKey, $model instanceof Model ? $model->getAttribute($this->ownerKey) : $model);
        $this->child->setRelation($this->relationName, $model instanceof Model ? $model : null);

        return $this->child;
    }

    public function dissociate(): Model
    {
        return $this->associate(null);
    }
}

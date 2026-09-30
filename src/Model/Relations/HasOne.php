<?php

declare(strict_types=1);

namespace Loongs\Orm\Model\Relations;

use Loongs\Orm\Model\Collection;
use Loongs\Orm\Model\Model;

final class HasOne extends HasOneOrMany
{
    public function initRelation(array $models, string $relation): array
    {
        foreach ($models as $m) {
            $m->setRelation($relation, null);
        }

        return $models;
    }

    public function match(array $models, Collection $results, string $relation): array
    {
        return $this->matchOneOrMany($models, $results, $relation, true);
    }

    public function getResults(): ?Model
    {
        return $this->getParentKey() === null ? null : $this->query->first();
    }
}

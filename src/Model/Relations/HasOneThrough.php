<?php

declare(strict_types=1);

namespace Loongs\Orm\Model\Relations;

use Loongs\Orm\Model\Collection;
use Loongs\Orm\Model\Model;

/** One far model through an intermediate one (mechanic → car → owner). */
final class HasOneThrough extends HasManyThrough
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
        $dict = $this->buildDictionary($results);
        foreach ($models as $m) {
            $key = (string) $m->getAttribute($this->localKey);
            if (isset($dict[$key])) {
                $m->setRelation($relation, $dict[$key][0]);
            }
        }

        return $models;
    }

    public function getResults(): ?Model
    {
        return $this->parent->getAttribute($this->localKey) === null ? null : $this->query->first();
    }
}

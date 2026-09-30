<?php

declare(strict_types=1);

namespace Loongs\Orm\Model\Relations;

use Loongs\Orm\Model\Collection;

/** $post->comments (comments.commentable_id / commentable_type). */
final class MorphMany extends MorphOneOrMany
{
    public function initRelation(array $models, string $relation): array
    {
        foreach ($models as $m) {
            $m->setRelation($relation, $this->related->newCollection());
        }

        return $models;
    }

    public function match(array $models, Collection $results, string $relation): array
    {
        return $this->matchOneOrMany($models, $results, $relation, false);
    }

    public function getResults(): Collection
    {
        return $this->getParentKey() === null ? $this->related->newCollection() : $this->query->get();
    }
}

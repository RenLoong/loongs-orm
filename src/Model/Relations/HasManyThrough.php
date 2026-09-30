<?php

declare(strict_types=1);

namespace Loongs\Orm\Model\Relations;

use Loongs\Orm\Model\Builder;
use Loongs\Orm\Model\Collection;
use Loongs\Orm\Model\Model;

/**
 * Country → users → posts:
 *   select posts.* from posts inner join users on users.id = posts.user_id where users.country_id = ?
 *
 * Keys (Eloquent order): firstKey = users.country_id, secondKey = posts.user_id,
 * localKey = countries.id, secondLocalKey = users.id. The through table is joined, so related and
 * through tables must live in the parent's database (both run on the parent's connection).
 * Soft-deleted through rows are excluded when the through model uses SoftDeletes.
 */
class HasManyThrough extends Relation
{
    /** Alias of the through key selected for eager matching (removed from the results). */
    public const string THROUGH_KEY = 'loongs_through_key';

    public function __construct(
        Builder $query,
        Model $farParent,
        protected Model $throughParent,
        protected string $firstKey,
        protected string $secondKey,
        protected string $localKey,
        protected string $secondLocalKey,
    ) {
        parent::__construct($query, $farParent);
    }

    public function addConstraints(): void
    {
        $this->performJoin();
        $this->query->where($this->getQualifiedFirstKeyName(), '=', $this->parent->getAttribute($this->localKey));
    }

    public function addEagerConstraints(array $models): void
    {
        $this->performJoin();
        $this->query->whereIn($this->getQualifiedFirstKeyName(), $this->getKeys($models, $this->localKey));
        $this->query->getQuery()->addSelect($this->getQualifiedFirstKeyName() . ' as ' . self::THROUGH_KEY);
    }

    private bool $joined = false;

    protected function performJoin(): void
    {
        if ($this->joined) {
            return;
        }
        $this->joined = true;
        $through = $this->throughParent;
        $this->query->getQuery()->select($this->related->getTable() . '.*');
        $this->query->join($through->getTable(), $through->qualifyColumn($this->secondLocalKey), '=', $this->related->qualifyColumn($this->secondKey));
        if (method_exists($through, 'getQualifiedDeletedAtColumn')) {
            $this->query->whereNull($through->getQualifiedDeletedAtColumn());
        }
    }

    public function getQualifiedFirstKeyName(): string
    {
        return $this->throughParent->qualifyColumn($this->firstKey);
    }

    public function get(array $columns = ['*']): Collection
    {
        $query = clone $this->query;
        if ($columns !== ['*']) {
            $query->getQuery()->select($columns);
        }

        return $query->get();
    }

    public function initRelation(array $models, string $relation): array
    {
        foreach ($models as $m) {
            $m->setRelation($relation, $this->related->newCollection());
        }

        return $models;
    }

    public function match(array $models, Collection $results, string $relation): array
    {
        $dict = $this->buildDictionary($results);
        foreach ($models as $m) {
            $key = (string) $m->getAttribute($this->localKey);
            if (isset($dict[$key])) {
                $m->setRelation($relation, $this->related->newCollection($dict[$key]));
            }
        }

        return $models;
    }

    /** @return array<string, list<Model>> */
    protected function buildDictionary(Collection $results): array
    {
        $dict = [];
        foreach ($results as $r) {
            $attrs = $r->getAttributes();
            $key = (string) ($attrs[self::THROUGH_KEY] ?? '');
            unset($attrs[self::THROUGH_KEY]);
            $r->setRawAttributes($attrs, true);
            $dict[$key][] = $r;
        }

        return $dict;
    }

    public function getResults(): mixed
    {
        return $this->parent->getAttribute($this->localKey) === null ? $this->related->newCollection() : $this->get();
    }

    public function getThroughParent(): Model
    {
        return $this->throughParent;
    }
}

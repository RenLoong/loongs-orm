<?php

declare(strict_types=1);

namespace Loongs\Orm\Model\Relations;

use Closure;
use Loongs\Orm\Context;
use Loongs\Orm\Exceptions\ClassMorphViolationException;
use Loongs\Orm\Model\Builder;
use Loongs\Orm\Model\Collection;
use Loongs\Orm\Model\Model;

/**
 * @mixin Builder
 */
abstract class Relation
{
    /** Coroutine-local flag: build the relation without the per-parent constraint (eager loading). */
    public const string NO_CONSTRAINTS = 'loongs.orm.noConstraints';

    protected Model $related;

    /** @var array<string, class-string<Model>> alias => model class (process-wide configuration, not tenant state) */
    private static array $morphMap = [];

    private static bool $requireMorphMap = false;

    public function __construct(protected Builder $query, protected Model $parent)
    {
        $this->related = $query->getModel();
        if (!Context::get(self::NO_CONSTRAINTS, false)) {
            $this->addConstraints();
        }
    }

    /**
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    public static function noConstraints(Closure $callback): mixed
    {
        return Context::with(self::NO_CONSTRAINTS, true, $callback);
    }

    /**
     * Morph map: which string is stored in *_type columns for which model class.
     *   Relation::morphMap(['post' => Post::class, 'video' => Video::class]);
     * Returns the current map. $merge = false replaces it (morphMap([], false) clears it).
     *
     * @param array<string, class-string<Model>>|null $map
     * @return array<string, class-string<Model>>
     */
    public static function morphMap(?array $map = null, bool $merge = true): array
    {
        if ($map !== null) {
            foreach ($map as $alias => $class) {
                if (!is_string($alias) || $alias === '' || !is_subclass_of($class, Model::class)) {
                    throw new \InvalidArgumentException("Morph map entries must be 'alias' => Model::class, got [{$alias}].");
                }
            }
            self::$morphMap = $merge ? array_merge(self::$morphMap, $map) : $map;
        }

        return self::$morphMap;
    }

    /**
     * morphMap() + requireMorphMap(): every model used polymorphically must have an alias, and
     * *_type values read from the database must be aliases in the map.
     *
     * @param array<string, class-string<Model>> $map
     * @return array<string, class-string<Model>>
     */
    public static function enforceMorphMap(array $map, bool $merge = true): array
    {
        self::requireMorphMap();

        return self::morphMap($map, $merge);
    }

    public static function requireMorphMap(bool $require = true): void
    {
        self::$requireMorphMap = $require;
    }

    public static function requiresMorphMap(): bool
    {
        return self::$requireMorphMap;
    }

    /** @return class-string<Model>|null */
    public static function getMorphedModel(string $alias): ?string
    {
        return self::$morphMap[$alias] ?? null;
    }

    /** Alias for $class, or $class itself when unmapped (throws when the map is enforced). */
    public static function getMorphAlias(string $class): string
    {
        $alias = array_search($class, self::$morphMap, true);
        if ($alias !== false) {
            return (string) $alias;
        }
        if (self::$requireMorphMap) {
            throw ClassMorphViolationException::forClass($class);
        }

        return $class;
    }

    /**
     * Model class for a *_type value read from the database. Only Model subclasses are accepted —
     * a type column never makes the ORM instantiate an arbitrary class.
     *
     * @return class-string<Model>
     */
    public static function resolveMorphType(string $type): string
    {
        $class = self::$morphMap[$type] ?? null;
        if ($class === null) {
            if (self::$requireMorphMap) {
                throw ClassMorphViolationException::forAlias($type);
            }
            $class = $type;
        }
        if (!is_subclass_of($class, Model::class)) {
            throw new \InvalidArgumentException("Morph type [{$type}] is not a model class (use Relation::morphMap()).");
        }

        return $class;
    }

    abstract public function addConstraints(): void;

    /** @param list<Model> $models */
    abstract public function addEagerConstraints(array $models): void;

    /** @param list<Model> $models @return list<Model> */
    abstract public function initRelation(array $models, string $relation): array;

    /** @param list<Model> $models @return list<Model> */
    abstract public function match(array $models, Collection $results, string $relation): array;

    abstract public function getResults(): mixed;

    public function getEager(): Collection
    {
        return $this->get();
    }

    /** @param list<string> $columns */
    public function get(array $columns = ['*']): Collection
    {
        return $this->query->get($columns);
    }

    public function getQuery(): Builder
    {
        return $this->query;
    }

    public function getParent(): Model
    {
        return $this->parent;
    }

    public function getRelated(): Model
    {
        return $this->related;
    }

    /** @param list<Model> $models @return list<int|string> */
    protected function getKeys(array $models, string $key): array
    {
        $keys = [];
        foreach ($models as $m) {
            $v = $m->getAttribute($key);
            if ($v !== null) {
                $keys[(string) $v] = $v;
            }
        }

        return array_values($keys);
    }

    /** @param list<mixed> $parameters */
    public function __call(string $method, array $parameters): mixed
    {
        $result = $this->query->{$method}(...$parameters);

        return $result === $this->query ? $this : $result;
    }

    public function __clone()
    {
        $this->query = clone $this->query;
    }
}

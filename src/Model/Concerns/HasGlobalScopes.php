<?php

declare(strict_types=1);

namespace Loongs\Orm\Model\Concerns;

use Closure;
use Loongs\Orm\Model\Scope;

/** Global scopes are class metadata (registered in booted()), never connection state. */
trait HasGlobalScopes
{
    /** @var array<class-string, array<string, Scope|Closure>> */
    protected static array $globalScopes = [];

    public static function addGlobalScope(string|Scope|Closure $scope, Scope|Closure|null $implementation = null): void
    {
        if (is_string($scope)) {
            if ($implementation === null) {
                throw new \InvalidArgumentException('addGlobalScope(string $id, Scope|Closure $scope)');
            }
            static::$globalScopes[static::class][$scope] = $implementation;
        } elseif ($scope instanceof Closure) {
            static::$globalScopes[static::class][spl_object_hash($scope)] = $scope;
        } else {
            static::$globalScopes[static::class][$scope::class] = $scope;
        }
    }

    public static function hasGlobalScope(string $id): bool
    {
        return isset(static::$globalScopes[static::class][$id]);
    }

    /** @return array<string, Scope|Closure> */
    public static function getGlobalScopes(): array
    {
        return static::$globalScopes[static::class] ?? [];
    }
}

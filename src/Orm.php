<?php

declare(strict_types=1);

namespace Loongs\Orm;

use Closure;
use Loongs\Orm\Connection\Connection;
use Loongs\Orm\Connection\ConnectionConfig;
use Loongs\Orm\Connection\ConnectionResolver;
use Loongs\Orm\Connection\PoolProvider;
use Loongs\Orm\Connection\QueryExecuted;
use Loongs\Orm\Connection\TenantPool;
use Loongs\Orm\Query\Builder;
use Loongs\Orm\Query\Expression;
use Loongs\Orm\Query\Grammar\Grammar;
use Loongs\Orm\Query\Grammar\MySqlGrammar;
use Swoole\Coroutine;

/**
 * Entry point.
 *
 * Process-wide state kept here is configuration only (named connection configs, grammar
 * factories, query listeners, the optional pool provider). No tenant, connection or PDO is
 * remembered: Orm::connection() returns a new lightweight handle on every call and each statement
 * borrows / releases its own connection.
 */
final class Orm
{
    private static ?ConnectionResolver $resolver = null;

    /** @var list<callable(QueryExecuted): void> */
    private static array $listeners = [];

    /** @var array<string, Closure(): Grammar> */
    private static array $grammars = [];

    public static function resolver(): ConnectionResolver
    {
        return self::$resolver ??= new ConnectionResolver();
    }

    public static function setResolver(?ConnectionResolver $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * Named connections, config/database.php shape: ['default' => 'mysql', 'connections' => [...]].
     * A Closure is evaluated on every lookup. Without configure() and inside loongs/framework,
     * config('database') is used.
     *
     * @param array<string, mixed>|Closure(): array<string, mixed> $database
     */
    public static function configure(array|Closure $database): void
    {
        self::resolver()->setDatabaseConfig($database);
    }

    /** Use $pools for named connections (null = never pool). Default: framework db() pools when booted. */
    public static function usePools(?PoolProvider $pools): void
    {
        self::resolver()->setPoolProvider($pools);
    }

    /** OPT-IN bounded idle pool for ad-hoc tenant configs (OFF by default). See TenantPool. */
    public static function enableTenantPool(int $maxTenants = 32, int $perTenant = 4, float $idleSeconds = 30.0): TenantPool
    {
        $pool = new TenantPool($maxTenants, $perTenant, $idleSeconds);
        self::resolver()->enableTenantPool($pool);

        return $pool;
    }

    public static function disableTenantPool(): void
    {
        self::resolver()->tenantPool()?->flush();
        self::resolver()->enableTenantPool(null);
    }

    /**
     * Handle for a connection spec: name, config array, DSN / URL, ConnectionConfig, or null
     * (current tenant scope, else default). A new handle every call; holds no PDO.
     *
     * @param string|array<string, mixed>|ConnectionConfig|null $spec
     */
    public static function connection(string|array|ConnectionConfig|null $spec = null): Connection
    {
        $config = self::resolver()->spec($spec);

        return new Connection(self::resolver(), $config, self::grammar($config->driver()));
    }

    /** @param string|array<string, mixed>|ConnectionConfig|null $spec */
    public static function table(string $table, string|array|ConnectionConfig|null $spec = null): Builder
    {
        return self::connection($spec)->table($table);
    }

    public static function raw(string|int|float $value): Expression
    {
        return new Expression($value);
    }

    /**
     * Run $callback in a transaction on $spec. Every query on the same connection config in this
     * coroutine (models included) uses the transaction's connection until it ends.
     *
     * @template T
     * @param callable(Connection): T $callback
     * @param string|array<string, mixed>|ConnectionConfig|null $spec
     * @return T
     */
    public static function transaction(callable $callback, string|array|ConnectionConfig|null $spec = null, int $attempts = 1): mixed
    {
        return self::connection($spec)->transaction($callback, $attempts);
    }

    /**
     * Run $callback with $spec as the default connection of THIS coroutine (models without an
     * explicit connection, Orm::connection(null), Orm::table()). Context-local, restored on exit,
     * invisible to other coroutines — including coroutines started inside (use Orm::go()).
     *
     * @template T
     * @param string|array<string, mixed>|ConnectionConfig $spec
     * @param callable(): T $callback
     * @return T
     */
    public static function tenant(string|array|ConnectionConfig $spec, callable $callback): mixed
    {
        return Context::with(ConnectionResolver::TENANT_KEY, self::resolver()->spec($spec), $callback);
    }

    public static function currentTenant(): ?ConnectionConfig
    {
        $t = Context::get(ConnectionResolver::TENANT_KEY);

        return $t instanceof ConnectionConfig ? $t : null;
    }

    /** Coroutine::create() that carries the current tenant scope into the new coroutine. */
    public static function go(callable $callback): int|false
    {
        $tenant = self::currentTenant();

        return Coroutine::create(static fn () => $tenant !== null ? self::tenant($tenant, $callback) : $callback());
    }

    public static function grammar(string $driver): Grammar
    {
        $factory = self::$grammars[$driver] ?? null;

        return $factory !== null ? $factory() : match ($driver) {
            'mysql' => new MySqlGrammar(),
            default => throw new \InvalidArgumentException("No grammar registered for driver [{$driver}] (Orm::extendGrammar()).") ,
        };
    }

    /** @param Closure(): Grammar $factory */
    public static function extendGrammar(string $driver, Closure $factory): void
    {
        self::$grammars[$driver] = $factory;
    }

    /** @param callable(QueryExecuted): void $listener */
    public static function listen(callable $listener): void
    {
        self::$listeners[] = $listener;
    }

    public static function flushListeners(): void
    {
        self::$listeners = [];
    }

    public static function hasListeners(): bool
    {
        return self::$listeners !== [];
    }

    public static function dispatch(QueryExecuted $event): void
    {
        foreach (self::$listeners as $l) {
            $l($event);
        }
    }
}

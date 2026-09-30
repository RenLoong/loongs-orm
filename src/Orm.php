<?php

declare(strict_types=1);

namespace Loongs\Orm;

use Closure;
use Loongs\Orm\Connection\Connection;
use Loongs\Orm\Connection\ConnectionConfig;
use Loongs\Orm\Connection\ConnectionResolver;
use Loongs\Orm\Connection\PoolConfig;
use Loongs\Orm\Connection\PoolProvider;
use Loongs\Orm\Connection\QueryExecuted;
use Loongs\Orm\Connection\TenantPool;
use Loongs\Orm\Exceptions\QueryException;
use Loongs\Orm\Query\Builder;
use Loongs\Orm\Query\Expression;
use Loongs\Orm\Query\Grammar\Grammar;
use Loongs\Orm\Query\Grammar\MySqlGrammar;
use Swoole\Coroutine;

/**
 * Entry point.
 *
 * Process-wide state kept here is configuration only (named connection configs, grammar
 * factories, query listeners, pool settings). No tenant or PDO is remembered outside the pools:
 * Orm::connection() returns a new lightweight handle on every call and each statement checks a
 * connection out of its pool (framework PDOPool for named connections, the ORM TenantPool for
 * tenant configs) and returns it.
 */
final class Orm
{
    private static ?ConnectionResolver $resolver = null;

    /** @var list<callable(QueryExecuted): void> */
    private static array $listeners = [];

    /** @var array<string, Closure(): Grammar> */
    private static array $grammars = [];

    /** @var (callable(string): void)|null */
    private static $warningHandler = null;

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

    /** Pools for named connections (null = use the ORM pool for them too). Default: framework db() pools when booted. */
    public static function usePools(?PoolProvider $pools): void
    {
        self::resolver()->setPoolProvider($pools);
    }

    /**
     * ORM pool settings (tenant configs; named configs when no framework pool is booted). Accepts a
     * PoolConfig or ['size' => 8, 'max_tenants' => 64, 'idle_seconds' => 60, 'ttl' => 600,
     * 'wait_timeout' => 3, 'validate' => true]. Without it: config('database.tenant_pool') → ORM_POOL_* env → defaults.
     *
     * @param PoolConfig|array<string, mixed> $config
     */
    public static function configurePool(PoolConfig|array $config): TenantPool
    {
        self::resolver()->configurePool(is_array($config) ? PoolConfig::fromArray($config) : $config);

        return self::resolver()->pool();
    }

    /** The ORM pool of this worker process. */
    public static function pool(): TenantPool
    {
        return self::resolver()->pool();
    }

    /**
     * Pool counters: totals, or open/active/idle/waiting for one spec.
     *
     * @param string|array<string, mixed>|ConnectionConfig|null $spec
     * @return array<string, int>
     */
    public static function poolStats(string|array|ConnectionConfig|null $spec = null): array
    {
        return self::resolver()->pool()->stats($spec === null ? null : self::resolver()->spec($spec));
    }

    /**
     * Check a connection out for manual control. Until release(), every statement on this config in
     * the current coroutine (models, builders, Orm::table()) runs on it. release() is idempotent;
     * exceptions do not release it — use try/finally, or Orm::using().
     *
     *   $c = Orm::acquire($tenant);
     *   try { $c->table('users')->count(); User::on($tenant)->find(1); } finally { $c->release(); }
     *
     * @param string|array<string, mixed>|ConnectionConfig|null $spec
     */
    public static function acquire(string|array|ConnectionConfig|null $spec = null): Connection
    {
        $config = self::resolver()->spec($spec);

        return self::resolver()->acquire($config, self::grammar($config->driver(), $config->prefix()));
    }

    /**
     * acquire() + $callback($connection) + release, whatever happens: on an exception an open
     * transaction is rolled back and a lost / broken connection is discarded instead of reused.
     *
     * @template T
     * @param string|array<string, mixed>|ConnectionConfig|null $spec
     * @param callable(Connection): T $callback
     * @return T
     */
    public static function using(string|array|ConnectionConfig|null $spec, callable $callback): mixed
    {
        $c = self::acquire($spec);
        try {
            return $callback($c);
        } catch (\Throwable $e) {
            if (QueryException::causedByLostConnection($e)) {
                $c->discard();
            }
            throw $e;
        } finally {
            $c->release();
        }
    }

    /** Where safety-net warnings go (default: error_log). null restores the default. @param (callable(string): void)|null $handler */
    public static function onWarning(?callable $handler): void
    {
        self::$warningHandler = $handler;
    }

    public static function warn(string $message): void
    {
        $h = self::$warningHandler;
        if ($h !== null) {
            $h($message);
            return;
        }
        error_log('[loongs/orm] WARN ' . $message);
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

        return new Connection(self::resolver(), $config, self::grammar($config->driver(), $config->prefix()));
    }

    /**
     * The resolved connection config for a spec (null = current Orm::tenant() scope, else the
     * default connection) — prefix(), engine(), charset(), collation(), database() … Resolved on
     * every call, never cached.
     *
     * @param string|array<string, mixed>|ConnectionConfig|null $spec
     */
    public static function config(string|array|ConnectionConfig|null $spec = null): ConnectionConfig
    {
        return self::resolver()->spec($spec);
    }

    /** Table prefix of a connection (null = current tenant scope, else default). @param string|array<string, mixed>|ConnectionConfig|null $spec */
    public static function prefix(string|array|ConnectionConfig|null $spec = null): string
    {
        return self::config($spec)->prefix();
    }

    /**
     * Prefixed, unquoted table name for raw SQL: Orm::tableName('users') → "app_users". Prefer
     * Orm::connection()->wrapTable('users') (quoted) when building SQL strings.
     *
     * @param string|array<string, mixed>|ConnectionConfig|null $spec
     */
    public static function tableName(string $table, string|array|ConnectionConfig|null $spec = null): string
    {
        return self::config($spec)->table($table);
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

    /**
     * Polymorphic type map (alias stored in *_type columns => model class). Same as Relation::morphMap().
     *
     * @param array<string, class-string<Model\Model>>|null $map
     * @return array<string, class-string<Model\Model>>
     */
    public static function morphMap(?array $map = null, bool $merge = true): array
    {
        return Model\Relations\Relation::morphMap($map, $merge);
    }

    /**
     * Morph map + require an alias for every polymorphic model. Same as Relation::enforceMorphMap().
     *
     * @param array<string, class-string<Model\Model>> $map
     * @return array<string, class-string<Model\Model>>
     */
    public static function enforceMorphMap(array $map, bool $merge = true): array
    {
        return Model\Relations\Relation::enforceMorphMap($map, $merge);
    }

    /** A new grammar instance (one per connection handle) compiling with $tablePrefix. */
    public static function grammar(string $driver, string $tablePrefix = ''): Grammar
    {
        $factory = self::$grammars[$driver] ?? null;

        return ($factory !== null ? $factory() : match ($driver) {
            'mysql' => new MySqlGrammar(),
            default => throw new \InvalidArgumentException("No grammar registered for driver [{$driver}] (Orm::extendGrammar()).") ,
        })->setTablePrefix($tablePrefix);
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

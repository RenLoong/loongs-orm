<?php

declare(strict_types=1);

namespace Loongs\Orm\Connection;

use InvalidArgumentException;
use Loongs\Orm\Context;
use Loongs\Orm\Query\Grammar\Grammar;
use Swoole\Coroutine;
use WeakMap;
use WeakReference;

/**
 * Turns a connection spec into a ConnectionConfig and hands out Leases.
 *
 * Every connection comes from a pool and goes back to the same pool:
 *  - a registered LeaseProvider that claims the config (Orm::addLeaseProvider) → its pool;
 *  - named connection + framework PDOPool booted in this worker → the framework pool;
 *  - anything else (config array / DSN, or a named config without a booted framework pool) → the
 *    ORM ConnectionPool, one bounded bucket per full config fingerprint (credentials included).
 * Outside the pools nothing holds a PDO: handles and models keep a ConnectionConfig only; leases
 * live for one statement, one transaction, or one acquire()/using() block.
 */
final class ConnectionResolver
{
    public const string HELD_KEY = 'loongs.orm.held';

    /** @var array<string, mixed>|\Closure(): array<string, mixed>|null ['default' => …, 'connections' => […]] */
    private array|\Closure|null $database = null;

    private ?PoolProvider $pools = null;

    private bool $autoFramework = true;

    private ?ConnectionPool $pool = null;

    /** @var (\Closure(): (string|array<string, mixed>|ConnectionConfig|null))|null */
    private ?\Closure $defaultResolver = null;

    /** @var list<LeaseProvider> */
    private array $providers = [];

    private ?PoolConfig $poolConfig = null;

    /** @var WeakMap<object, int> identity serial per PDO object (diagnostics; weak → no retention) */
    private WeakMap $serials;

    private int $nextSerial = 0;

    public function __construct()
    {
        $this->serials = new WeakMap();
    }

    /** @param array<string, mixed>|\Closure(): array<string, mixed> $database config/database.php shape */
    public function setDatabaseConfig(array|\Closure $database): void
    {
        $this->database = $database;
    }

    public function setPoolProvider(?PoolProvider $pools, bool $autoFramework = false): void
    {
        $this->pools = $pools;
        $this->autoFramework = $autoFramework;
    }

    /** Replace the ORM pool settings (idle connections of the previous pool are closed). */
    public function configurePool(PoolConfig $config): void
    {
        $this->poolConfig = $config;
        $this->pool?->flush();
        $this->pool = null;
    }

    /** The ORM pool of this process, created on first use from the resolved PoolConfig. */
    public function pool(): ConnectionPool
    {
        return $this->pool ??= new ConnectionPool(
            $this->resolvePoolConfig(),
            static fn (ConnectionConfig $c): object => PdoFactory::make($c),
            fn (object $pdo): int => $this->serial($pdo),
        );
    }

    /**
     * Where a call without a connection goes (spec null): $resolver() is evaluated on EVERY such
     * resolution and may return a spec, or null for the configured default connection. Keep it
     * stateless / coroutine-safe (read coroutine context, never a static "current" value).
     *
     * @param (\Closure(): (string|array<string, mixed>|ConnectionConfig|null))|null $resolver
     */
    public function setDefaultResolver(?\Closure $resolver): void
    {
        $this->defaultResolver = $resolver;
    }

    /** @return (\Closure(): (string|array<string, mixed>|ConnectionConfig|null))|null */
    public function defaultResolver(): ?\Closure
    {
        return $this->defaultResolver;
    }

    public function addLeaseProvider(LeaseProvider $provider): void
    {
        if (!in_array($provider, $this->providers, true)) {
            $this->providers[] = $provider;
        }
    }

    public function removeLeaseProvider(LeaseProvider $provider): void
    {
        $this->providers = array_values(array_filter($this->providers, static fn (LeaseProvider $p): bool => $p !== $provider));
    }

    /** @return list<LeaseProvider> */
    public function leaseProviders(): array
    {
        return $this->providers;
    }

    public function resolvePoolConfig(): PoolConfig
    {
        if ($this->poolConfig !== null) {
            return $this->poolConfig;
        }
        $fromConfig = $this->databaseConfig()['orm_pool'] ?? [];

        return PoolConfig::fromArray(array_replace(PoolConfig::envOverrides(), is_array($fromConfig) ? $fromConfig : []));
    }

    /**
     * Normalise a spec. null → the default resolver (setDefaultResolver), else the default named connection.
     *
     * @param string|array<string, mixed>|ConnectionConfig|null $spec
     */
    public function spec(string|array|ConnectionConfig|null $spec = null): ConnectionConfig
    {
        if ($spec instanceof ConnectionConfig) {
            return $spec;
        }
        if ($spec === null) {
            $resolved = $this->defaultResolver !== null ? ($this->defaultResolver)() : null;
            if ($resolved instanceof ConnectionConfig) {
                return $resolved;
            }
            $spec = $resolved ?? $this->defaultName();
        }
        if (is_array($spec)) {
            return ConnectionConfig::adhoc($spec);
        }
        if (str_contains($spec, '://') || preg_match('/^[a-z0-9_]+:[a-z_]+=/i', $spec) === 1) {
            return ConnectionConfig::fromDsn($spec);
        }
        $connections = $this->databaseConfig()['connections'] ?? [];
        if (!is_array($connections) || !isset($connections[$spec]) || !is_array($connections[$spec])) {
            throw new InvalidArgumentException("Database connection [{$spec}] is not configured.");
        }

        return ConnectionConfig::named($spec, $connections[$spec]);
    }

    public function defaultName(): string
    {
        $d = $this->databaseConfig()['default'] ?? 'mysql';

        return is_string($d) && $d !== '' ? $d : 'mysql';
    }

    /** Check a connection out of the right pool (one statement, one transaction or one acquire()). */
    public function lease(ConnectionConfig $c): Lease
    {
        foreach ($this->providers as $provider) {
            $lease = $provider->lease($c);
            if ($lease !== null) {
                return $lease;
            }
        }
        $pools = $this->poolProvider();
        if ($c->name !== null && $pools !== null && $pools->has($c->name)) {
            $name = $c->name;
            $pdo = $pools->get($name);

            return new Lease($pdo, $c, LeaseSource::Framework, $this->serial($pdo), static function (object $pdo, bool $reusable) use ($pools, $name): void {
                $reusable ? $pools->put($name, $pdo) : $pools->discard($name, $pdo);
            });
        }

        return $this->pool()->acquire($c);
    }

    /**
     * Take a lease for manual control (Orm::acquire / Orm::using). Nested acquires of the same config
     * in the same coroutine share one lease (reference counted); it is the coroutine's ambient
     * connection for that config until the last handle releases it.
     */
    public function acquire(ConnectionConfig $c, Grammar $grammar): Connection
    {
        $held = $this->held($c);
        if ($held === null) {
            $lease = $this->lease($c);
            $held = new HeldLease($lease);
            $all = Context::get(self::HELD_KEY);
            $all = is_array($all) ? $all : [];
            $all[$c->key] = WeakReference::create($held);
            Context::set(self::HELD_KEY, $all);
            self::deferReclaim($lease);
        }
        $held->refs++;

        return new Connection($this, $c, $grammar, $held);
    }

    /** The lease acquired for $c by this coroutine, if any. */
    public function held(ConnectionConfig $c): ?HeldLease
    {
        $all = Context::get(self::HELD_KEY);
        $held = is_array($all) && isset($all[$c->key]) ? $all[$c->key]->get() : null;

        return $held instanceof HeldLease && !$held->lease->isReleased() ? $held : null;
    }

    /** Drop one reference; the last one returns the lease to its pool. */
    public function releaseHeld(HeldLease $held, bool $broken = false): void
    {
        if ($broken) {
            $held->lease->markBroken();
        }
        if (--$held->refs > 0) {
            return;
        }
        $key = $held->lease->config->key;
        $all = Context::get(self::HELD_KEY);
        if (is_array($all) && isset($all[$key]) && $all[$key]->get() === $held) {
            unset($all[$key]);
            $all === [] ? Context::forget(self::HELD_KEY) : Context::set(self::HELD_KEY, $all);
        }
        $tx = Context::get(Connection::TX_KEY);
        if (is_array($tx) && isset($tx[$key]) && $tx[$key]->lease === $held->lease) {
            unset($tx[$key]); // an unfinished transaction on this lease: release() rolls it back
            $tx === [] ? Context::forget(Connection::TX_KEY) : Context::set(Connection::TX_KEY, $tx);
        }
        $held->lease->release();
    }

    /** Safety net: reclaim $lease (with a WARN) if it is still out when the current coroutine ends. */
    public static function deferReclaim(Lease $lease): void
    {
        if (!Context::inCoroutine()) {
            return;
        }
        $ref = WeakReference::create($lease);
        Coroutine::defer(static function () use ($ref): void {
            $ref->get()?->reclaim('returned when its coroutine ended', true);
        });
    }

    /** Diagnostic identity of a PDO object (stable while it lives; weakly held). */
    public function serial(object $pdo): int
    {
        return $this->serials[$pdo] ??= ++$this->nextSerial;
    }

    private function poolProvider(): ?PoolProvider
    {
        if ($this->pools !== null) {
            return $this->pools;
        }
        if ($this->autoFramework && FrameworkPoolProvider::available()) {
            return new FrameworkPoolProvider(); // stateless; resolves db() on each call
        }

        return null;
    }

    /** The database config (Orm::configure(), else config('database')). @return array<string, mixed> read on every call (not cached) */
    public function databaseConfig(): array
    {
        $db = $this->database;
        if ($db instanceof \Closure) {
            $db = $db();
        }
        if ($db === null && function_exists('config') && (($GLOBALS['__loongs_app'] ?? null) !== null)) {
            try {
                $db = config('database');
            } catch (\Throwable) {
                $db = null;
            }
        }

        return is_array($db) ? $db : [];
    }
}

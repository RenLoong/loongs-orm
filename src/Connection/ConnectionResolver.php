<?php

declare(strict_types=1);

namespace Loongs\Orm\Connection;

use InvalidArgumentException;
use Loongs\Orm\Context;
use WeakMap;

/**
 * Turns a connection spec into a ConnectionConfig and hands out Leases.
 *
 * Holds NO per-tenant state: no connection / PDO is memoised by name or config. Every lease is
 * either borrowed from a pool of a *named* connection (and put back on release) or a brand-new
 * PDO (closed on release). The only exception is the opt-in TenantPool (off by default).
 */
final class ConnectionResolver
{
    public const string TENANT_KEY = 'loongs.orm.tenant';

    /** @var array<string, mixed>|\Closure(): array<string, mixed>|null ['default' => …, 'connections' => […]] */
    private array|\Closure|null $database = null;

    private ?PoolProvider $pools = null;

    private bool $autoFramework = true;

    private ?TenantPool $tenantPool = null;

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

    public function enableTenantPool(?TenantPool $pool): void
    {
        $this->tenantPool = $pool;
    }

    public function tenantPool(): ?TenantPool
    {
        return $this->tenantPool;
    }

    /**
     * Normalise a spec. null → current tenant scope (coroutine context) → default named connection.
     *
     * @param string|array<string, mixed>|ConnectionConfig|null $spec
     */
    public function spec(string|array|ConnectionConfig|null $spec = null): ConnectionConfig
    {
        if ($spec instanceof ConnectionConfig) {
            return $spec;
        }
        if ($spec === null) {
            $tenant = Context::get(self::TENANT_KEY);
            if ($tenant instanceof ConnectionConfig) {
                return $tenant;
            }
            $spec = $this->defaultName();
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

    /** Borrow a connection for one statement / one transaction. */
    public function lease(ConnectionConfig $c): Lease
    {
        $pools = $this->poolProvider();
        if ($c->name !== null && $pools !== null && $pools->has($c->name)) {
            $name = $c->name;
            $pdo = $pools->get($name);

            return new Lease($pdo, $c, LeaseSource::Pool, $this->serialOf($pdo), static function (object $pdo, bool $healthy) use ($pools, $name): void {
                $pools->put($name, $pdo, $healthy);
            });
        }

        $tp = $c->name === null ? $this->tenantPool : null;
        if ($tp !== null) {
            while (($item = $tp->take($c)) !== null) {
                if ($tp->validate($c, $item['pdo'])) {
                    return new Lease($item['pdo'], $c, LeaseSource::TenantPool, $item['serial'], static function (object $pdo, bool $healthy) use ($tp, $c, $item): void {
                        $tp->give($c, $pdo, $item['serial'], $healthy);
                    });
                }
                $tp->discarded++;
            }
            $pdo = PdoFactory::make($c);
            $serial = $this->serialOf($pdo);

            return new Lease($pdo, $c, LeaseSource::TenantPool, $serial, static function (object $pdo, bool $healthy) use ($tp, $c, $serial): void {
                $tp->give($c, $pdo, $serial, $healthy);
            });
        }

        $pdo = PdoFactory::make($c);

        // Fresh: nothing keeps the PDO after release → it is closed when the lease drops it.
        return new Lease($pdo, $c, LeaseSource::Fresh, $this->serialOf($pdo), static function (object $pdo, bool $healthy): void {
            if (!$healthy) {
                try {
                    $pdo->inTransaction() && $pdo->rollBack();
                } catch (\Throwable) {
                }
            }
        });
    }

    private function serialOf(object $pdo): int
    {
        return $this->serials[$pdo] ??= ++$this->nextSerial;
    }

    private function poolProvider(): ?PoolProvider
    {
        if ($this->pools !== null) {
            return $this->pools;
        }
        if ($this->autoFramework && FrameworkPoolProvider::available()) {
            return new FrameworkPoolProvider();
        }

        return null;
    }

    /** @return array<string, mixed> read on every call (not cached) */
    private function databaseConfig(): array
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

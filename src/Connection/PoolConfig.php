<?php

declare(strict_types=1);

namespace Loongs\Orm\Connection;

use InvalidArgumentException;

/**
 * Settings of the ORM connection pool (config arrays / DSNs, and named configs when no framework
 * pool is booted). Resolution: Orm::configurePool() → config('database.orm_pool') → ORM_POOL_* env → defaults.
 *
 *   size          max open connections per connection config (checked out + idle)        default 8
 *   max_pools     max connection configs (buckets) kept; least recently used idle one evicted  default 64
 *   idle_seconds  idle connections older than this are closed                            default 60
 *   ttl           connections older than this are closed when returned / found idle      default 600 (0 = no limit)
 *   wait_timeout  seconds to wait for a free connection when a config is at size         default 3 (-1 = forever)
 *   validate      check the connection (not in a transaction, SELECT DATABASE()) on checkout  default true
 */
final readonly class PoolConfig
{
    public function __construct(
        public int $size = 8,
        public int $maxPools = 64,
        public float $idleSeconds = 60.0,
        public float $ttl = 600.0,
        public float $waitTimeout = 3.0,
        public bool $validate = true,
    ) {
        if ($size < 1 || $maxPools < 1) {
            throw new InvalidArgumentException('Pool size and max_pools must be >= 1.');
        }
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        $d = new self();

        return new self(
            size: (int) ($a['size'] ?? $d->size),
            maxPools: (int) ($a['max_pools'] ?? $d->maxPools),
            idleSeconds: (float) ($a['idle_seconds'] ?? $d->idleSeconds),
            ttl: (float) ($a['ttl'] ?? $d->ttl),
            waitTimeout: (float) ($a['wait_timeout'] ?? $d->waitTimeout),
            validate: filter_var($a['validate'] ?? $d->validate, FILTER_VALIDATE_BOOL),
        );
    }

    /**
     * Environment overrides, e.g. envOverrides() reads ORM_POOL_SIZE, ORM_POOL_MAX_POOLS,
     * ORM_POOL_IDLE_SECONDS, ORM_POOL_TTL, ORM_POOL_WAIT_TIMEOUT, ORM_POOL_VALIDATE.
     *
     * @param list<string> $keys config keys to look up (upper-cased after $prefix)
     * @return array<string, string>
     */
    public static function envOverrides(string $prefix = 'ORM_POOL_', array $keys = ['size', 'max_pools', 'idle_seconds', 'ttl', 'wait_timeout', 'validate']): array
    {
        $out = [];
        foreach ($keys as $k) {
            $name = $prefix . strtoupper($k);
            $v = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
            if (is_string($v) && $v !== '') {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /** @return array<string, int|float|bool> */
    public function toArray(): array
    {
        return ['size' => $this->size, 'max_pools' => $this->maxPools, 'idle_seconds' => $this->idleSeconds, 'ttl' => $this->ttl, 'wait_timeout' => $this->waitTimeout, 'validate' => $this->validate];
    }
}

<?php

declare(strict_types=1);

namespace Loongs\Orm\Connection;

use PDO;

/**
 * OPT-IN, bounded idle-connection pool for ad-hoc (tenant) configs. OFF by default.
 *
 * Safety rules:
 * - keyed by ConnectionConfig::$key = sha1 of the full normalised config INCLUDING credentials,
 *   so a connection can only ever be handed to a config identical to the one that opened it;
 * - on checkout the connection is validated with SELECT DATABASE(); a mismatch (someone ran
 *   `USE other_db` on it) or any error discards it;
 * - a connection released inside an open transaction, or released as unhealthy, is rolled back
 *   and discarded, never reused;
 * - bounded: at most $maxTenants keys (least recently used key evicted) and $perTenant idle
 *   connections per key; idle connections older than $idleSeconds are closed on the next checkout.
 * It only retains idle connections; it does not cap concurrent connections.
 * take()/give() never yield, so they are atomic under Swoole's cooperative scheduling.
 */
final class TenantPool
{
    /** @var array<string, list<array{pdo: PDO, at: float, serial: int}>> */
    private array $idle = [];

    /** @var array<string, float> key → last use (LRU) */
    private array $used = [];

    public int $hits = 0;

    public int $misses = 0;

    public int $discarded = 0;

    public function __construct(
        public readonly int $maxTenants = 32,
        public readonly int $perTenant = 4,
        public readonly float $idleSeconds = 30.0,
    ) {
    }

    /** @return array{pdo: PDO, serial: int}|null */
    public function take(ConnectionConfig $c): ?array
    {
        $now = microtime(true);
        while (!empty($this->idle[$c->key])) {
            $item = array_pop($this->idle[$c->key]);
            if ($now - $item['at'] > $this->idleSeconds) {
                $this->discarded++;
                continue;
            }
            if ($this->idle[$c->key] === []) {
                unset($this->idle[$c->key], $this->used[$c->key]);
            } else {
                $this->used[$c->key] = $now;
            }
            $this->hits++;

            return ['pdo' => $item['pdo'], 'serial' => $item['serial']];
        }
        unset($this->idle[$c->key], $this->used[$c->key]);
        $this->misses++;

        return null;
    }

    /** Validate a connection just taken from the pool (may yield: runs a query). */
    public function validate(ConnectionConfig $c, PDO $pdo): bool
    {
        try {
            if ($pdo->inTransaction()) {
                return false;
            }
            $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
            return $c->database() === '' || $db === $c->database();
        } catch (\Throwable) {
            return false;
        }
    }

    public function give(ConnectionConfig $c, PDO $pdo, int $serial, bool $healthy): void
    {
        if (!$healthy || $pdo->inTransaction()) {
            try {
                $pdo->inTransaction() && $pdo->rollBack();
            } catch (\Throwable) {
            }
            $this->discarded++;
            return;
        }
        $list = $this->idle[$c->key] ?? [];
        if (count($list) >= $this->perTenant) {
            $this->discarded++;
            return;
        }
        $list[] = ['pdo' => $pdo, 'at' => microtime(true), 'serial' => $serial];
        $this->idle[$c->key] = $list;
        $this->used[$c->key] = microtime(true);
        while (count($this->idle) > $this->maxTenants) {
            $oldest = null;
            foreach ($this->idle as $k => $_) {
                if ($oldest === null || ($this->used[$k] ?? 0) < ($this->used[$oldest] ?? 0)) {
                    $oldest = $k;
                }
            }
            $this->discarded += count($this->idle[$oldest]);
            unset($this->idle[$oldest], $this->used[$oldest]);
        }
    }

    /** @return array{tenants: int, idle: int, hits: int, misses: int, discarded: int} */
    public function stats(): array
    {
        return [
            'tenants' => count($this->idle),
            'idle' => array_sum(array_map('count', $this->idle)),
            'hits' => $this->hits,
            'misses' => $this->misses,
            'discarded' => $this->discarded,
        ];
    }

    public function flush(): void
    {
        $this->idle = [];
        $this->used = [];
    }
}

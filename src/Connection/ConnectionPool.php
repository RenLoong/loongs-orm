<?php

declare(strict_types=1);

namespace Loongs\Orm\Connection;

use Closure;
use Loongs\Orm\Context;
use Loongs\Orm\Exceptions\PoolExhaustedException;
use Swoole\Coroutine\Channel;
use Throwable;

/**
 * Keyed connection pool: one bounded bucket per connection config (always on in the ORM for config
 * arrays / DSNs, and for named connections when no framework pool is booted). Other packages can run
 * their own instance (e.g. behind a LeaseProvider) with their own settings and LeaseSource.
 *
 * - Keyed by ConnectionConfig::fingerprint() = the full normalised config INCLUDING credentials, so a
 *   connection is only ever handed to a config identical to the one that opened it.
 * - Per bucket at most `size` open connections (checked out + idle). When all are busy a coroutine
 *   waits up to `wait_timeout` (hand-off through a Channel), then gets PoolExhaustedException; outside
 *   a coroutine nothing could release a connection while we wait, so it throws immediately.
 * - Checkout validation: not inside a transaction and SELECT DATABASE() equals the configured
 *   database (a connection somebody `USE`d elsewhere, or that the server killed, is discarded).
 * - A connection returned broken / in a failed state is discarded; its slot goes to a waiter or frees up.
 * - Idle connections older than `idle_seconds`, and connections older than `ttl`, are closed (lazy
 *   sweep on checkout, at most once per second, or sweep()). At most `max_pools` buckets: the least
 *   recently used bucket with nothing checked out is evicted.
 * - Per worker process. A pool used before fork() is abandoned (not closed) in the child.
 * All bookkeeping between the yielding calls (connect, validate, Channel::pop) is non-yielding, so it
 * is atomic under Swoole's cooperative scheduling.
 */
final class ConnectionPool
{
    /** @var array<string, PoolBucket> */
    private array $buckets = [];

    /** @var list<mixed> buckets inherited through fork(): kept referenced so their sockets are never closed from the child */
    private array $inherited = [];

    private int $pid;

    private float $lastSweep = 0.0;

    public int $created = 0;

    public int $closed = 0;

    public int $hits = 0;

    public int $discarded = 0;

    public int $waits = 0;

    public int $timeouts = 0;

    public int $evictedPools = 0;

    /**
     * @param Closure(ConnectionConfig): object $connector opens a connection (PdoFactory::make by default)
     * @param Closure(object): int $serialOf          diagnostic identity of a PDO (ConnectionResolver::serial)
     * @param LeaseSource $source                     reported on leases / QueryExecuted events
     */
    public function __construct(
        public readonly PoolConfig $config,
        private readonly Closure $connector,
        private readonly Closure $serialOf,
        public readonly LeaseSource $source = LeaseSource::Pool,
    ) {
        $this->pid = getmypid() ?: 0;
    }

    public function acquire(ConnectionConfig $c): Lease
    {
        $this->guardFork();
        $now = microtime(true);
        if ($now - $this->lastSweep >= 1.0) {
            $this->sweep();
        }
        $b = $this->buckets[$c->fingerprint()] ?? $this->openBucket($c);
        $b->used = $now;
        $deadline = $this->config->waitTimeout < 0 ? null : $now + $this->config->waitTimeout;

        while (true) {
            // 1. idle connection
            while (($item = array_pop($b->idle)) !== null) {
                if ($this->expired($item, microtime(true))) {
                    $this->close($b, $item);
                    continue;
                }
                $b->active++;
                if (!$this->validate($c, $item['pdo'])) {
                    $b->active--;
                    $this->discarded++;
                    $this->close($b, $item);
                    continue;
                }
                $this->hits++;

                return $this->lease($c, $b, $item);
            }
            // 2. room for a new connection
            if ($b->total < $this->config->size) {
                $b->total++;
                $b->active++;

                return $this->lease($c, $b, $this->connect($c, $b));
            }
            // 3. wait for a release
            if (!Context::inCoroutine()) {
                throw new PoolExhaustedException(sprintf('Connection pool for %s exhausted: all %d connections are checked out (not in a coroutine, cannot wait).', $c->describe(), $this->config->size));
            }
            $remaining = $deadline === null ? -1 : $deadline - microtime(true);
            if ($deadline !== null && $remaining <= 0) {
                $this->timeouts++;
                throw $this->exhausted($c);
            }
            $ch = new Channel(1);
            $b->waiters[spl_object_id($ch)] = $ch;
            $this->waits++;
            $got = $ch->pop($remaining);
            unset($b->waiters[spl_object_id($ch)]);
            if ($got === false) {
                $this->timeouts++;
                throw $this->exhausted($c);
            }
            if ($got === 'slot') {
                // a connection was discarded and its slot handed to us (total unchanged)
                $b->active++;

                return $this->lease($c, $b, $this->connect($c, $b));
            }
            // a released connection handed straight to us (still counted as active)
            if (!$this->validate($c, $got['pdo'])) {
                $b->active--;
                $this->discarded++;
                $this->close($b, $got);
                continue;
            }
            $this->hits++;

            return $this->lease($c, $b, $got);
        }
    }

    /** Close expired idle connections and drop empty buckets. */
    public function sweep(): void
    {
        $now = microtime(true);
        $this->lastSweep = $now;
        foreach ($this->buckets as $key => $b) {
            foreach ($b->idle as $i => $item) {
                if ($this->expired($item, $now)) {
                    unset($b->idle[$i]);
                    $this->close($b, $item);
                }
            }
            $b->idle = array_values($b->idle);
            if ($b->total === 0 && $b->active === 0 && $b->waiters === []) {
                unset($this->buckets[$key]);
            }
        }
    }

    /**
     * @return array{pools: int, open: int, active: int, idle: int, waiting: int, created: int, closed: int, hits: int, discarded: int, waits: int, timeouts: int, evicted_pools: int}
     *         or, for one config: array{open: int, active: int, idle: int, waiting: int}
     */
    public function stats(?ConnectionConfig $c = null): array
    {
        if ($c !== null) {
            $b = $this->buckets[$c->fingerprint()] ?? null;

            return ['open' => $b->total ?? 0, 'active' => $b->active ?? 0, 'idle' => $b !== null ? count($b->idle) : 0, 'waiting' => $b !== null ? count($b->waiters) : 0];
        }
        $open = $active = $idle = $waiting = 0;
        foreach ($this->buckets as $b) {
            $open += $b->total;
            $active += $b->active;
            $idle += count($b->idle);
            $waiting += count($b->waiters);
        }

        return ['pools' => count($this->buckets), 'open' => $open, 'active' => $active, 'idle' => $idle, 'waiting' => $waiting,
            'created' => $this->created, 'closed' => $this->closed, 'hits' => $this->hits, 'discarded' => $this->discarded,
            'waits' => $this->waits, 'timeouts' => $this->timeouts, 'evicted_pools' => $this->evictedPools];
    }

    /** True when a bucket for $c exists (open, idle or waited-for connections). */
    public function has(ConnectionConfig $c): bool
    {
        return isset($this->buckets[$c->fingerprint()]);
    }

    /**
     * Retire the bucket of one config: idle connections are closed now, checked-out ones when they
     * come back (they are not reused). The next acquire() opens a fresh bucket. Returns whether a
     * bucket existed.
     */
    public function forget(ConnectionConfig $c): bool
    {
        $key = $c->fingerprint();
        $b = $this->buckets[$key] ?? null;
        if ($b === null) {
            return false;
        }
        foreach ($b->idle as $item) {
            $this->close($b, $item);
        }
        $b->idle = [];
        $b->retired = true;
        unset($this->buckets[$key]);

        return true;
    }

    /** Close every idle connection; checked-out ones are closed when returned. */
    public function flush(): void
    {
        foreach ($this->buckets as $b) {
            foreach ($b->idle as $item) {
                $this->close($b, $item);
            }
            $b->idle = [];
            $b->retired = true;
        }
        $this->buckets = [];
    }

    // ------------------------------------------------------------------ internals

    /** @param array{pdo: object, serial: int, born: float, at: float} $item */
    private function lease(ConnectionConfig $c, PoolBucket $b, array $item): Lease
    {
        $serial = $item['serial'];
        $born = $item['born'];

        // the release callback captures metadata only — never the PDO — so a discarded connection is
        // closed as soon as the Lease lets go of it
        return new Lease($item['pdo'], $c, $this->source, $serial, function (object $pdo, bool $reusable) use ($b, $serial, $born): void {
            $this->checkin($b, ['pdo' => $pdo, 'serial' => $serial, 'born' => $born, 'at' => 0.0], $reusable);
        });
    }

    /** @param array{pdo: object, serial: int, born: float, at: float} $item */
    private function checkin(PoolBucket $b, array $item, bool $reusable): void
    {
        if ((getmypid() ?: 0) !== $this->pid) {
            $this->inherited[] = $item; // never close a parent's socket from a child
            return;
        }
        $now = microtime(true);
        if (!$reusable || $b->retired || ($this->config->ttl > 0 && $now - $item['born'] > $this->config->ttl)) {
            if (!$reusable) {
                $this->discarded++;
            }
            $this->closed++;
            $b->active--;
            if (!$b->retired && ($ch = $this->firstWaiter($b)) !== null) {
                $ch->push('slot'); // slot transferred to the waiter, which opens a new connection (total unchanged)
                return;
            }
            $b->total--;
            return;
        }
        $item['at'] = $now;
        if (($ch = $this->firstWaiter($b)) !== null) {
            $ch->push($item); // hand-off: stays active, now owned by the waiter
            return;
        }
        $b->active--;
        $b->idle[] = $item;
        $b->used = $now;
    }

    private function firstWaiter(PoolBucket $b): ?Channel
    {
        foreach ($b->waiters as $id => $ch) {
            unset($b->waiters[$id]);

            return $ch;
        }

        return null;
    }

    /** @return array{pdo: object, serial: int, born: float, at: float} */
    private function connect(ConnectionConfig $c, PoolBucket $b): array
    {
        try {
            $pdo = ($this->connector)($c);
        } catch (Throwable $e) {
            $b->active--;
            if (($ch = $this->firstWaiter($b)) !== null) {
                $ch->push('slot');
            } else {
                $b->total--;
            }
            throw $e;
        }
        $this->created++;
        $now = microtime(true);

        return ['pdo' => $pdo, 'serial' => ($this->serialOf)($pdo), 'born' => $now, 'at' => $now];
    }

    private function validate(ConnectionConfig $c, object $pdo): bool
    {
        if (!$this->config->validate) {
            return true;
        }
        try {
            if ($pdo->inTransaction()) {
                return false;
            }
            $db = $pdo->query('SELECT DATABASE()')->fetchColumn();

            return $c->database() === '' || $db === $c->database();
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array{pdo: object, serial: int, born: float, at: float} $item */
    private function expired(array $item, float $now): bool
    {
        return ($this->config->idleSeconds > 0 && $now - $item['at'] > $this->config->idleSeconds)
            || ($this->config->ttl > 0 && $now - $item['born'] > $this->config->ttl);
    }

    /** Drop an idle / broken connection of $b (the PDO closes when its last reference goes). */
    private function close(PoolBucket $b, array $item): void
    {
        $b->total--;
        $this->closed++;
    }

    private function openBucket(ConnectionConfig $c): PoolBucket
    {
        if (count($this->buckets) >= $this->config->maxPools) {
            $lru = null;
            foreach ($this->buckets as $key => $b) {
                if ($b->active === 0 && $b->waiters === [] && ($lru === null || $b->used < $this->buckets[$lru]->used)) {
                    $lru = $key;
                }
            }
            if ($lru !== null) {
                $victim = $this->buckets[$lru];
                foreach ($victim->idle as $item) {
                    $this->close($victim, $item);
                }
                $victim->idle = [];
                $victim->retired = true;
                unset($this->buckets[$lru]);
                $this->evictedPools++;
            }
            // every bucket busy: allow a temporary overflow rather than failing the request
        }

        return $this->buckets[$c->fingerprint()] = new PoolBucket();
    }

    private function exhausted(ConnectionConfig $c): PoolExhaustedException
    {
        return new PoolExhaustedException(sprintf('Connection pool for %s exhausted: %d/%d connections busy, no connection freed within %.2fs.',
            $c->describe(), $this->buckets[$c->fingerprint()]->active ?? 0, $this->config->size, $this->config->waitTimeout));
    }

    private function guardFork(): void
    {
        $pid = getmypid() ?: 0;
        if ($pid !== $this->pid) {
            $this->inherited[] = $this->buckets;
            $this->buckets = [];
            $this->pid = $pid;
        }
    }
}

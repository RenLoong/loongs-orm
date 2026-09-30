<?php

declare(strict_types=1);

namespace Loongs\Orm\Connection;

use Closure;
use LogicException;
use Loongs\Orm\Orm;
use PDO;
use Throwable;

/**
 * One connection checked out of a pool.
 *
 * release() puts it back exactly once (idempotent): an open transaction is rolled back first; if the
 * rollback fails or the lease was marked broken, the connection is discarded (closed; the pool
 * refills) instead of being reused. After release the lease holds no PDO.
 *
 * Safety net: a lease that is never released is reclaimed by Coroutine::defer at the end of the
 * coroutine (acquired / transaction leases) or by the destructor, with a WARN via Orm::warn().
 */
final class Lease
{
    private ?object $pdo;

    private bool $released = false;

    private bool $broken = false;

    public readonly float $acquiredAt;

    /** @param Closure(object $pdo, bool $reusable): void $onRelease hands the connection back to its pool */
    public function __construct(
        object $pdo,
        public readonly ConnectionConfig $config,
        public readonly LeaseSource $source,
        public readonly int $serial,
        private readonly Closure $onRelease,
    ) {
        $this->pdo = $pdo;
        $this->acquiredAt = microtime(true);
    }

    /** @return PDO|object PDO or Swoole\Database\PDOProxy */
    public function pdo(): object
    {
        if ($this->pdo === null) {
            throw new LogicException('Connection lease already released (' . $this->config->describe() . ').');
        }

        return $this->pdo;
    }

    public function isReleased(): bool
    {
        return $this->released;
    }

    public function isBroken(): bool
    {
        return $this->broken;
    }

    /** The connection is in an unknown state (lost, killed, protocol error): it will be discarded on release. */
    public function markBroken(): void
    {
        $this->broken = true;
    }

    /** Return the connection to its pool (rollback if needed; discard if broken). Idempotent. */
    public function release(): void
    {
        $this->finish(true);
    }

    /** Close the connection instead of reusing it; the pool opens a replacement when needed. Idempotent. */
    public function discard(): void
    {
        $this->broken = true;
        $this->finish(true);
    }

    /**
     * Safety net for a holder that forgot release().
     *
     * @param bool $canDoIo false in destructors (maybe outside a coroutine): no ROLLBACK round trip —
     *                      a connection with an open transaction is discarded instead
     */
    public function reclaim(string $why, bool $canDoIo): void
    {
        if ($this->released) {
            return;
        }
        Orm::warn(sprintf('connection lease on %s (source=%s, serial #%d, held %.3fs) was never released; %s.',
            $this->config->describe(), $this->source->value, $this->serial, microtime(true) - $this->acquiredAt, $why));
        try {
            $this->finish($canDoIo);
        } catch (Throwable) {
            // pool gone / no coroutine: dropping the PDO closes it
        }
    }

    private function finish(bool $canDoIo): void
    {
        if ($this->released) {
            return;
        }
        $this->released = true;
        $pdo = $this->pdo;
        $this->pdo = null;
        if ($pdo === null) {
            return;
        }
        $reusable = !$this->broken;
        if ($reusable) {
            try {
                if ($pdo->inTransaction()) {
                    if ($canDoIo) {
                        $pdo->rollBack();
                    } else {
                        $reusable = false;
                    }
                }
            } catch (Throwable) {
                $reusable = false;
            }
        }
        ($this->onRelease)($pdo, $reusable);
    }

    public function __destruct()
    {
        if (!$this->released) {
            $this->reclaim('returned by the destructor', false);
        }
    }
}

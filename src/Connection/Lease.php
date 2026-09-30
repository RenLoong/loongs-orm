<?php

declare(strict_types=1);

namespace Loongs\Orm\Connection;

use Closure;
use LogicException;
use PDO;

/**
 * One borrowed connection. Released exactly once; after release the object no longer
 * references the PDO, so a fresh (non-pooled) PDO is closed as soon as the caller drops it.
 */
final class Lease
{
    private ?object $pdo;

    private bool $released = false;

    /** @param Closure(object $pdo, bool $healthy): void $onRelease */
    public function __construct(
        object $pdo,
        public readonly ConnectionConfig $config,
        public readonly LeaseSource $source,
        public readonly int $serial,
        private readonly Closure $onRelease,
    ) {
        $this->pdo = $pdo;
    }

    /** @return PDO|object PDO or Swoole\Database\PDOProxy */
    public function pdo(): object
    {
        if ($this->pdo === null) {
            throw new LogicException('Connection lease already released.');
        }

        return $this->pdo;
    }

    public function isReleased(): bool
    {
        return $this->released;
    }

    /** @param bool $healthy false = the connection is in an unknown state; the pool must not reuse it */
    public function release(bool $healthy = true): void
    {
        if ($this->released) {
            return;
        }
        $this->released = true;
        $pdo = $this->pdo;
        $this->pdo = null;
        if ($pdo !== null) {
            ($this->onRelease)($pdo, $healthy);
        }
    }
}

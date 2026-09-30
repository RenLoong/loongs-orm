<?php

declare(strict_types=1);

namespace Loongs\Orm\Connection;

/** Supplies pooled connections for named connections (e.g. the framework's Swoole PDOPool). */
interface PoolProvider
{
    /** True when a pool for $name is available right now (e.g. pools booted in this worker). */
    public function has(string $name): bool;

    /** @throws \Loongs\Orm\Exceptions\PoolExhaustedException when no connection frees up in time */
    public function get(string $name): object;

    /** Return a reusable connection (not in a transaction). */
    public function put(string $name, object $pdo): void;

    /** Close a broken connection; the pool replaces it. */
    public function discard(string $name, object $pdo): void;
}

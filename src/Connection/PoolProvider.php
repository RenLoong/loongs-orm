<?php

declare(strict_types=1);

namespace Loongs\Orm\Connection;

/** Supplies pooled connections for named connections (e.g. the framework's Swoole PDOPool). */
interface PoolProvider
{
    /** True when a pool for $name is available right now (e.g. pools booted in this worker). */
    public function has(string $name): bool;

    public function get(string $name): object;

    /** @param bool $healthy false → do not reuse the connection (replace it) */
    public function put(string $name, object $pdo, bool $healthy): void;
}

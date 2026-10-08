<?php

declare(strict_types=1);

namespace Loongs\Orm\Connection;

use Swoole\Coroutine\Channel;

/** @internal per-config state of ConnectionPool */
final class PoolBucket
{
    /** @var list<array{pdo: object, serial: int, born: float, at: float}> */
    public array $idle = [];

    /** open connections: checked out + idle + being created */
    public int $total = 0;

    /** checked out (or being created / validated) */
    public int $active = 0;

    /** @var array<int, Channel> coroutines waiting for a connection */
    public array $waiters = [];

    public float $used = 0.0;

    /** evicted / flushed: returned connections are closed, not pooled */
    public bool $retired = false;
}

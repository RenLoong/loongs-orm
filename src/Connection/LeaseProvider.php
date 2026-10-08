<?php

declare(strict_types=1);

namespace Loongs\Orm\Connection;

/**
 * Extension point: lets another package serve some connection configs from its own pool
 * (Orm::addLeaseProvider()). Asked first, in registration order, for every lease (statement,
 * transaction, acquire()); returning null hands the config on to the next provider, then to the
 * framework pool (named connections) and finally to the ORM pool.
 *
 * The Lease's release callback must return the connection to the provider's pool (reusable = false
 * means: close it, it is broken or in an unknown state). ConnectionPool already does all of that:
 *
 *   final class MyPools implements LeaseProvider {
 *       public function __construct(private ConnectionPool $pool) {}
 *       public function lease(ConnectionConfig $c): ?Lease {
 *           return $this->wants($c) ? $this->pool->acquire($c) : null;
 *       }
 *   }
 *
 * Implementations are process-wide configuration: decide from the config alone (no per-request
 * state), and never hand one config's connection to another config.
 */
interface LeaseProvider
{
    public function lease(ConnectionConfig $c): ?Lease;
}

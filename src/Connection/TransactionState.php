<?php

declare(strict_types=1);

namespace Loongs\Orm\Connection;

/** Open transaction of one config in one execution context (coroutine). */
final class TransactionState
{
    /** @param bool $owned true = the transaction borrowed the lease and must release it; false = it runs on a held (acquired) lease */
    public function __construct(
        public readonly Lease $lease,
        public int $level,
        public readonly bool $owned = true,
    ) {
    }
}

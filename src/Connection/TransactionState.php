<?php

declare(strict_types=1);

namespace Loongs\Orm\Connection;

/** Open transaction of one config in one execution context (coroutine). */
final class TransactionState
{
    public function __construct(
        public readonly Lease $lease,
        public int $level,
    ) {
    }
}

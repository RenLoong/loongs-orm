<?php

declare(strict_types=1);

namespace Loongs\Orm\Connection;

/**
 * A lease taken with Orm::acquire() / Orm::using(). It is the ambient connection of its config in
 * the acquiring coroutine (models and query builders on that config use it) until the last handle
 * releases it. The coroutine context only keeps a WeakReference: dropping every handle returns it.
 */
final class HeldLease
{
    public int $refs = 0;

    public function __construct(public readonly Lease $lease)
    {
    }
}

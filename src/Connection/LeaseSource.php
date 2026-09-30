<?php

declare(strict_types=1);

namespace Loongs\Orm\Connection;

enum LeaseSource: string
{
    /** Borrowed from a pool of a named connection (framework PDOPool). */
    case Pool = 'pool';
    /** New PDO created for this call, closed on release. */
    case Fresh = 'fresh';
    /** Opt-in bounded per-tenant pool (off by default). */
    case TenantPool = 'tenant-pool';
}

<?php

declare(strict_types=1);

namespace Loongs\Orm\Connection;

enum LeaseSource: string
{
    /** loongs/framework PDOPool of a named connection (booted in the worker). */
    case Framework = 'framework';
    /** ORM pool: tenant configs (array / DSN), or named configs when no framework pool is booted. */
    case Pool = 'pool';
}

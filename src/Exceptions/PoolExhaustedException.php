<?php

declare(strict_types=1);

namespace Loongs\Orm\Exceptions;

use RuntimeException;

/** No connection became free within wait_timeout (or all are checked out and there is no coroutine to wait in). */
final class PoolExhaustedException extends RuntimeException
{
}

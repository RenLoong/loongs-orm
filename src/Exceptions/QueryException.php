<?php

declare(strict_types=1);

namespace Loongs\Orm\Exceptions;

use RuntimeException;
use Throwable;

final class QueryException extends RuntimeException
{
    /** @param list<mixed> $bindings */
    public function __construct(
        public readonly string $sql,
        public readonly array $bindings,
        public readonly string $connection,
        Throwable $previous,
    ) {
        parent::__construct(sprintf('%s (Connection: %s, SQL: %s)', $previous->getMessage(), $connection, $sql), (int) $previous->getCode(), $previous);
    }

    /** SQL-level errors (syntax, constraint …) leave the connection usable; transport errors do not. */
    public function isRecoverable(): bool
    {
        $m = $this->getPrevious()?->getMessage() ?? '';
        foreach (['server has gone away', 'Lost connection', 'Broken pipe', 'Connection refused', 'Error while sending', 'is dead or not enabled', 'SSL connection has been closed'] as $needle) {
            if (stripos($m, $needle) !== false) {
                return false;
            }
        }

        return true;
    }
}

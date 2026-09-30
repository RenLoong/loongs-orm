<?php

declare(strict_types=1);

namespace Loongs\Orm\Exceptions;

use RuntimeException;
use Throwable;

final class QueryException extends RuntimeException
{
    /** MySQL client/server error codes meaning the connection itself is unusable. */
    private const array LOST_CODES = [1053, 1077, 1152, 1156, 1927, 2002, 2003, 2006, 2013, 2014, 2027, 2055, 4031];

    private const array LOST_MESSAGES = ['server has gone away', 'Lost connection', 'Connection was killed', 'Broken pipe', 'Connection refused',
        'Error while sending', 'Error reading result', 'is dead or not enabled', 'SSL connection has been closed', 'Packets out of order',
        'disconnected by the server', 'Commands out of sync', 'Connection reset', 'no connection to the server'];

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
        return !self::causedByLostConnection($this->getPrevious() ?? $this);
    }

    /** True when $e (or a previous exception) says the connection is lost / killed / out of sync. */
    public static function causedByLostConnection(Throwable $e): bool
    {
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            if ($x instanceof \PDOException && is_array($x->errorInfo ?? null) && in_array((int) ($x->errorInfo[1] ?? 0), self::LOST_CODES, true)) {
                return true;
            }
            $m = $x->getMessage();
            foreach (self::LOST_MESSAGES as $needle) {
                if (stripos($m, $needle) !== false) {
                    return true;
                }
            }
        }

        return false;
    }
}

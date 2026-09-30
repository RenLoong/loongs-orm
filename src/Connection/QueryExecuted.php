<?php

declare(strict_types=1);

namespace Loongs\Orm\Connection;

/** Fired after every statement (Orm::listen). Contains no credentials. */
final readonly class QueryExecuted
{
    /** @param list<mixed> $bindings */
    public function __construct(
        public string $sql,
        public array $bindings,
        public float $timeMs,
        public string $connectionKey,
        public string $connection,
        public string $database,
        public LeaseSource $source,
        public int $pdoSerial,
        public bool $inTransaction,
    ) {
    }
}

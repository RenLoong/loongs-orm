<?php

declare(strict_types=1);

namespace Loongs\Orm\Connection;

use BackedEnum;
use DateTimeInterface;
use Generator;
use Loongs\Orm\Context;
use Loongs\Orm\Exceptions\QueryException;
use Loongs\Orm\Orm;
use Loongs\Orm\Query\Builder;
use Loongs\Orm\Query\Expression;
use Loongs\Orm\Query\Grammar\Grammar;
use PDO;
use Throwable;
use UnitEnum;

/**
 * Lightweight handle bound to one ConnectionConfig. Holds no PDO: every statement borrows a
 * connection and releases it right after — except inside a transaction, where the transaction's
 * lease (kept in the coroutine context, keyed by the config key) is used until commit / rollback.
 */
final class Connection
{
    public const string TX_KEY = 'loongs.orm.tx';

    public function __construct(
        private readonly ConnectionResolver $resolver,
        public readonly ConnectionConfig $config,
        private readonly Grammar $grammar,
    ) {
    }

    public function getGrammar(): Grammar
    {
        return $this->grammar;
    }

    public function table(string|Expression $table, ?string $as = null): Builder
    {
        return (new Builder($this))->from($table, $as);
    }

    public function query(): Builder
    {
        return new Builder($this);
    }

    public function raw(string|int|float $value): Expression
    {
        return new Expression($value);
    }

    /**
     * @param list<mixed> $bindings
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        return $this->run($sql, $bindings, static function (object $st): array {
            /** @var list<array<string, mixed>> */
            return $st->fetchAll(PDO::FETCH_ASSOC);
        });
    }

    /** @param list<mixed> $bindings @return array<string, mixed>|null */
    public function selectOne(string $sql, array $bindings = []): ?array
    {
        return $this->select($sql, $bindings)[0] ?? null;
    }

    /** @param list<mixed> $bindings */
    public function insert(string $sql, array $bindings = []): bool
    {
        return $this->run($sql, $bindings, static fn (object $st): bool => true);
    }

    /** Insert and read lastInsertId on the SAME connection. @param list<mixed> $bindings */
    public function insertGetId(string $sql, array $bindings = [], ?string $sequence = null): int|string
    {
        return $this->run($sql, $bindings, static function (object $st, object $pdo) use ($sequence): int|string {
            $id = $pdo->lastInsertId($sequence);
            return is_string($id) && ctype_digit($id) ? (int) $id : (string) $id;
        });
    }

    /** @param list<mixed> $bindings */
    public function affectingStatement(string $sql, array $bindings = []): int
    {
        return $this->run($sql, $bindings, static fn (object $st): int => $st->rowCount());
    }

    /** @param list<mixed> $bindings */
    public function update(string $sql, array $bindings = []): int
    {
        return $this->affectingStatement($sql, $bindings);
    }

    /** @param list<mixed> $bindings */
    public function delete(string $sql, array $bindings = []): int
    {
        return $this->affectingStatement($sql, $bindings);
    }

    /** @param list<mixed> $bindings */
    public function statement(string $sql, array $bindings = []): bool
    {
        return $this->run($sql, $bindings, static fn (object $st): bool => true);
    }

    /** Unprepared statement (DDL etc.) — no bindings. */
    public function unprepared(string $sql): bool
    {
        return $this->withLease(function (object $pdo) use ($sql): bool {
            $start = hrtime(true);
            try {
                $pdo->exec($sql);
            } catch (Throwable $e) {
                throw new QueryException($sql, [], $this->config->describe(), $e);
            }
            $this->fire($sql, [], $start, $pdo);
            return true;
        });
    }

    /**
     * Stream rows; the connection is held until the generator finishes or is destroyed.
     *
     * @param list<mixed> $bindings
     * @return Generator<int, array<string, mixed>>
     */
    public function cursor(string $sql, array $bindings = []): Generator
    {
        $tx = $this->currentTx();
        $lease = $tx?->lease ?? $this->resolver->lease($this->config);
        $healthy = true;
        try {
            $pdo = $lease->pdo();
            $start = hrtime(true);
            try {
                $st = $pdo->prepare($sql);
                $this->bindValues($st, $bindings);
                $st->execute();
            } catch (Throwable $e) {
                $healthy = false;
                throw new QueryException($sql, $bindings, $this->config->describe(), $e);
            }
            $this->fire($sql, $bindings, $start, $pdo, $lease);
            while (($row = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
                yield $row;
            }
            $st->closeCursor();
        } finally {
            if ($tx === null) {
                $lease->release($healthy);
            }
        }
    }

    /**
     * @template T
     * @param callable(Connection): T $callback
     * @return T
     */
    public function transaction(callable $callback, int $attempts = 1): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            $this->beginTransaction();
            try {
                $result = $callback($this);
            } catch (Throwable $e) {
                $this->rollBack();
                if ($attempt < $attempts && $this->isRetryable($e)) {
                    continue;
                }
                throw $e;
            }
            $this->commit();

            return $result;
        }
    }

    public function beginTransaction(): void
    {
        $tx = $this->currentTx();
        if ($tx === null) {
            $lease = $this->resolver->lease($this->config);
            try {
                $lease->pdo()->beginTransaction();
            } catch (Throwable $e) {
                $lease->release(false);
                throw new QueryException('BEGIN', [], $this->config->describe(), $e);
            }
            $this->storeTx(new TransactionState($lease, 1));
            return;
        }
        $tx->level++;
        $this->withTxPdo($tx, 'SAVEPOINT ' . $this->grammar->wrapValue('trans' . $tx->level));
    }

    public function commit(): void
    {
        $tx = $this->currentTx() ?? throw new \LogicException('No active transaction on ' . $this->config->describe());
        if ($tx->level > 1) {
            $this->withTxPdo($tx, 'RELEASE SAVEPOINT ' . $this->grammar->wrapValue('trans' . $tx->level));
            $tx->level--;
            return;
        }
        $this->forgetTx();
        try {
            $tx->lease->pdo()->commit();
        } catch (Throwable $e) {
            $tx->lease->release(false);
            throw new QueryException('COMMIT', [], $this->config->describe(), $e);
        }
        $tx->lease->release(true);
    }

    public function rollBack(): void
    {
        $tx = $this->currentTx();
        if ($tx === null) {
            return;
        }
        if ($tx->level > 1) {
            $this->withTxPdo($tx, 'ROLLBACK TO SAVEPOINT ' . $this->grammar->wrapValue('trans' . $tx->level));
            $tx->level--;
            return;
        }
        $this->forgetTx();
        $healthy = true;
        try {
            $tx->lease->pdo()->rollBack();
        } catch (Throwable) {
            $healthy = false;
        }
        $tx->lease->release($healthy);
    }

    public function transactionLevel(): int
    {
        return $this->currentTx()?->level ?? 0;
    }

    /**
     * Run $fn with one connection (the transaction's, or a borrowed one released afterwards).
     *
     * @template T
     * @param callable(object): T $fn
     * @return T
     */
    public function withLease(callable $fn): mixed
    {
        $tx = $this->currentTx();
        if ($tx !== null) {
            return $fn($tx->lease->pdo());
        }
        $lease = $this->resolver->lease($this->config);
        $healthy = true;
        try {
            return $fn($lease->pdo());
        } catch (Throwable $e) {
            $healthy = !($e instanceof QueryException) || $e->isRecoverable();
            throw $e;
        } finally {
            $lease->release($healthy);
        }
    }

    /**
     * @template T
     * @param list<mixed> $bindings
     * @param callable(object, object): T $then
     * @return T
     */
    private function run(string $sql, array $bindings, callable $then): mixed
    {
        $tx = $this->currentTx();
        $lease = $tx?->lease ?? $this->resolver->lease($this->config);
        $healthy = true;
        try {
            $pdo = $lease->pdo();
            $start = hrtime(true);
            try {
                $st = $pdo->prepare($sql);
                $this->bindValues($st, $bindings);
                $st->execute();
                $result = $then($st, $pdo);
            } catch (Throwable $e) {
                $q = new QueryException($sql, $bindings, $this->config->describe(), $e);
                $healthy = $q->isRecoverable();
                throw $q;
            }
            $this->fire($sql, $bindings, $start, $pdo, $lease);

            return $result;
        } finally {
            if ($tx === null) {
                $lease->release($healthy);
            }
        }
    }

    /** @param list<mixed> $bindings */
    private function bindValues(object $st, array $bindings): void
    {
        $i = 0;
        foreach ($bindings as $value) {
            $i++;
            $value = self::prepareValue($value);
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $st->bindValue($i, $value, $type);
        }
    }

    public static function prepareValue(mixed $value): mixed
    {
        return match (true) {
            is_bool($value) => (int) $value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            is_float($value) => (string) $value,
            $value instanceof \Stringable => (string) $value,
            is_array($value) => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            default => $value,
        };
    }

    /** @param list<mixed> $bindings */
    private function fire(string $sql, array $bindings, int $start, object $pdo, ?Lease $lease = null): void
    {
        if (!Orm::hasListeners()) {
            return;
        }
        $lease ??= $this->currentTx()?->lease;
        Orm::dispatch(new QueryExecuted(
            $sql,
            $bindings,
            (hrtime(true) - $start) / 1e6,
            $this->config->key,
            $this->config->describe(),
            $this->config->database(),
            $lease?->source ?? LeaseSource::Fresh,
            $lease?->serial ?? 0,
            $this->currentTx() !== null,
        ));
    }

    private function withTxPdo(TransactionState $tx, string $sql): void
    {
        try {
            $tx->lease->pdo()->exec($sql);
        } catch (Throwable $e) {
            throw new QueryException($sql, [], $this->config->describe(), $e);
        }
    }

    private function isRetryable(Throwable $e): bool
    {
        $m = $e->getMessage();

        return str_contains($m, 'Deadlock found') || str_contains($m, 'Lock wait timeout') || str_contains($m, '40001');
    }

    private function currentTx(): ?TransactionState
    {
        $all = Context::get(self::TX_KEY);

        return is_array($all) ? ($all[$this->config->key] ?? null) : null;
    }

    private function storeTx(TransactionState $tx): void
    {
        $all = Context::get(self::TX_KEY);
        $all = is_array($all) ? $all : [];
        $all[$this->config->key] = $tx;
        Context::set(self::TX_KEY, $all);
    }

    private function forgetTx(): void
    {
        $all = Context::get(self::TX_KEY);
        if (!is_array($all)) {
            return;
        }
        unset($all[$this->config->key]);
        $all === [] ? Context::forget(self::TX_KEY) : Context::set(self::TX_KEY, $all);
    }
}

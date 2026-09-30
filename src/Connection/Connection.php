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
 * Handle bound to one ConnectionConfig.
 *
 * A plain handle (Orm::connection()) holds no PDO: every statement checks a connection out of its
 * pool and returns it right after. Inside a transaction, or while this coroutine holds a lease for
 * the config (Orm::acquire() / Orm::using()), statements run on that connection instead.
 *
 * A pinned handle (returned by Orm::acquire()) owns a share of a held lease; call release() (or
 * use Orm::using()). release() is idempotent; a forgotten handle is released by its destructor
 * with a WARN. Do not pass a pinned handle to another coroutine.
 */
final class Connection
{
    public const string TX_KEY = 'loongs.orm.tx';

    private bool $handleReleased = false;

    public function __construct(
        private readonly ConnectionResolver $resolver,
        public readonly ConnectionConfig $config,
        private readonly Grammar $grammar,
        private readonly ?HeldLease $hold = null,
    ) {
    }

    /** Pinned handle from Orm::acquire()? */
    public function isPinned(): bool
    {
        return $this->hold !== null;
    }

    public function isReleased(): bool
    {
        return $this->hold !== null && ($this->handleReleased || $this->hold->lease->isReleased());
    }

    /**
     * Give back this handle's share of the held connection; the last share returns it to its pool
     * (an open transaction is rolled back; a broken connection is discarded). Idempotent; no-op on
     * plain handles.
     */
    public function release(): void
    {
        if ($this->hold === null || $this->handleReleased) {
            return;
        }
        $this->handleReleased = true;
        $this->resolver->releaseHeld($this->hold);
    }

    /** Like release(), but the connection is closed instead of reused (the pool opens a new one). */
    public function discard(): void
    {
        if ($this->hold === null || $this->handleReleased) {
            return;
        }
        $this->handleReleased = true;
        $this->resolver->releaseHeld($this->hold, true);
    }

    /** Pool-level info about the connection this handle would use right now (diagnostics). */
    public function leaseInfo(): ?array
    {
        $lease = $this->currentTx()?->lease ?? ($this->hold !== null && !$this->isReleased() ? $this->hold->lease : $this->resolver->held($this->config)?->lease);

        return $lease === null ? null : ['source' => $lease->source->value, 'serial' => $lease->serial, 'broken' => $lease->isBroken()];
    }

    public function __destruct()
    {
        if ($this->hold !== null && !$this->handleReleased && !$this->hold->lease->isReleased()) {
            \Loongs\Orm\Orm::warn(sprintf('acquired connection handle on %s (serial #%d) was dropped without release(); released by its destructor.',
                $this->config->describe(), $this->hold->lease->serial));
            $this->release();
        }
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
                throw new QueryException($sql, [], $this->config->describe(), $e); // withLease marks a lost connection broken
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
        [$lease, $own] = $this->leaseFor();
        try {
            $pdo = $lease->pdo();
            $start = hrtime(true);
            try {
                $st = $pdo->prepare($sql);
                $this->bindValues($st, $bindings);
                $st->execute();
            } catch (Throwable $e) {
                throw $this->queryError($sql, $bindings, $e, $lease);
            }
            $this->fire($sql, $bindings, $start, $pdo, $lease);
            $done = false;
            try {
                while (($row = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
                    yield $row;
                }
                $st->closeCursor();
                $done = true;
            } finally {
                if (!$done) {
                    // abandoned mid-stream: unread rows would desync the protocol for the next user
                    try {
                        $st->closeCursor();
                    } catch (Throwable) {
                        $lease->markBroken();
                    }
                }
            }
        } finally {
            $st = null; // close the server-side statement before the connection goes back
            $pdo = null;
            if ($own) {
                $lease->release();
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
                if (QueryException::causedByLostConnection($e)) {
                    $this->currentTx()?->lease->markBroken();
                }
                $this->rollBack(); // releases (or discards, if broken) an owned lease at level 1
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
            [$lease, $own] = $this->leaseFor();
            try {
                $lease->pdo()->beginTransaction();
            } catch (Throwable $e) {
                $err = $this->queryError('BEGIN', [], $e, $lease);
                $own ? $lease->release() : null;
                throw $err;
            }
            $this->storeTx(new TransactionState($lease, 1, $own));
            if ($own) {
                ConnectionResolver::deferReclaim($lease); // safety net for a begin without commit/rollback
            }
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
            $tx->lease->markBroken(); // commit outcome unknown: never reuse this connection
            $tx->owned ? $tx->lease->release() : null;
            throw new QueryException('COMMIT', [], $this->config->describe(), $e);
        }
        if ($tx->owned) {
            $tx->lease->release();
        }
    }

    public function rollBack(): void
    {
        $tx = $this->currentTx();
        if ($tx === null) {
            return;
        }
        if ($tx->level > 1) {
            $level = $tx->level--;
            if (!$tx->lease->isBroken()) {
                $this->withTxPdo($tx, 'ROLLBACK TO SAVEPOINT ' . $this->grammar->wrapValue('trans' . $level));
            }
            return;
        }
        $this->forgetTx();
        try {
            if (!$tx->lease->isBroken()) {
                $tx->lease->pdo()->rollBack();
            }
        } catch (Throwable) {
            $tx->lease->markBroken();
        }
        if ($tx->owned) {
            $tx->lease->release(); // discarded when broken
        }
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
        [$lease, $own] = $this->leaseFor();
        try {
            return $fn($lease->pdo());
        } catch (Throwable $e) {
            if (QueryException::causedByLostConnection($e)) {
                $lease->markBroken();
            }
            throw $e;
        } finally {
            if ($own) {
                $lease->release();
            }
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
        [$lease, $own] = $this->leaseFor();
        $st = null;
        try {
            $pdo = $lease->pdo();
            $start = hrtime(true);
            try {
                $st = $pdo->prepare($sql);
                $this->bindValues($st, $bindings);
                $st->execute();
                $result = $then($st, $pdo);
            } catch (Throwable $e) {
                $st = null;
                throw $this->queryError($sql, $bindings, $e, $lease);
            }
            // Free the statement while we still own the connection: with native prepares its
            // destructor sends COM_STMT_CLOSE, which must not hit a connection already handed on.
            $st = null;
            $this->fire($sql, $bindings, $start, $pdo, $lease);

            return $result;
        } finally {
            $st = null;
            $pdo = null;
            if ($own) {
                $lease->release(); // back to its pool; discarded if marked broken
            }
        }
    }

    /**
     * The connection for the next statement and whether this call must release it:
     * transaction lease → this pinned handle's lease → lease held by this coroutine → pool checkout.
     *
     * @return array{0: Lease, 1: bool}
     */
    private function leaseFor(): array
    {
        $tx = $this->currentTx();
        if ($tx !== null) {
            return [$tx->lease, false];
        }
        if ($this->hold !== null) {
            if ($this->isReleased()) {
                throw new \LogicException('Connection handle already released (' . $this->config->describe() . ').');
            }
            return [$this->hold->lease, false];
        }
        $held = $this->resolver->held($this->config);
        if ($held !== null) {
            return [$held->lease, false];
        }

        return [$this->resolver->lease($this->config), true];
    }

    /** @param list<mixed> $bindings */
    private function queryError(string $sql, array $bindings, Throwable $e, Lease $lease): QueryException
    {
        $q = $e instanceof QueryException ? $e : new QueryException($sql, $bindings, $this->config->describe(), $e);
        if (!$q->isRecoverable()) {
            $lease->markBroken();
        }

        return $q;
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
        $lease ??= $this->currentTx()?->lease ?? ($this->hold !== null ? $this->hold->lease : $this->resolver->held($this->config)?->lease);
        Orm::dispatch(new QueryExecuted(
            $sql,
            $bindings,
            (hrtime(true) - $start) / 1e6,
            $this->config->key,
            $this->config->describe(),
            $this->config->database(),
            $lease?->source ?? LeaseSource::Pool,
            $lease?->serial ?? 0,
            $this->currentTx() !== null,
        ));
    }

    private function withTxPdo(TransactionState $tx, string $sql): void
    {
        try {
            $tx->lease->pdo()->exec($sql);
        } catch (Throwable $e) {
            throw $this->queryError($sql, [], $e, $tx->lease);
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

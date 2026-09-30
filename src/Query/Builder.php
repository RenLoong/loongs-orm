<?php

declare(strict_types=1);

namespace Loongs\Orm\Query;

use Closure;
use Generator;
use InvalidArgumentException;
use Loongs\Orm\Connection\Connection;
use Loongs\Orm\Pagination\Paginator;
use Loongs\Orm\Support\Collection;

/**
 * Fluent SQL query builder. Every value is a bound parameter ("?"); identifiers are quoted by
 * the grammar; operators and directions are whitelisted. Only Expression / *Raw() inject SQL.
 */
class Builder
{
    public const array OPERATORS = [
        '=', '<', '>', '<=', '>=', '<>', '!=', '<=>',
        'like', 'not like', 'like binary', 'regexp', 'not regexp', 'rlike', '&', '|', '^', '<<', '>>',
    ];

    /** @var list<string|Expression>|null */
    public ?array $columns = null;

    public bool $distinct = false;

    public string|Expression|null $from = null;

    /** @var list<JoinClause> */
    public array $joins = [];

    /** @var list<array<string, mixed>> */
    public array $wheres = [];

    /** @var list<string|Expression> */
    public array $groups = [];

    /** @var list<array<string, mixed>> */
    public array $havings = [];

    /** @var list<array<string, mixed>> */
    public array $orders = [];

    public ?int $limit = null;

    public ?int $offset = null;

    public string|bool|null $lock = null;

    /** @var array{function: string, columns: list<string|Expression>}|null */
    public ?array $aggregate = null;

    /** @var array<string, list<mixed>> */
    public array $bindings = ['select' => [], 'from' => [], 'join' => [], 'where' => [], 'groupBy' => [], 'having' => [], 'order' => []];

    public function __construct(public readonly Connection $connection)
    {
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    public function newQuery(): static
    {
        return new static($this->connection);
    }

    public function raw(string|int|float $value): Expression
    {
        return new Expression($value);
    }

    // ------------------------------------------------------------------ select / from

    public function select(string|Expression|array ...$columns): static
    {
        $this->columns = [];
        $this->bindings['select'] = [];

        return $this->addSelect(...$columns);
    }

    public function addSelect(string|Expression|array ...$columns): static
    {
        foreach ($columns as $c) {
            foreach (is_array($c) ? $c : [$c] as $col) {
                $this->columns[] = $col;
            }
        }
        if ($this->columns === []) {
            $this->columns = null;
        }

        return $this;
    }

    /** @param list<mixed> $bindings */
    public function selectRaw(string $sql, array $bindings = []): static
    {
        $this->columns ??= [];
        $this->columns[] = new Expression($sql);
        array_push($this->bindings['select'], ...$bindings);

        return $this;
    }

    public function distinct(bool $value = true): static
    {
        $this->distinct = $value;

        return $this;
    }

    public function from(string|Expression $table, ?string $as = null): static
    {
        $this->from = $as !== null && is_string($table) ? $table . ' as ' . $as : $table;

        return $this;
    }

    public function table(string $table): static
    {
        return $this->from($table);
    }

    // ------------------------------------------------------------------ joins

    public function join(string|Expression $table, Closure|string $first, ?string $operator = null, ?string $second = null, string $type = 'inner'): static
    {
        $type = strtolower($type);
        if (!in_array($type, ['inner', 'left', 'right', 'cross'], true)) {
            throw new InvalidArgumentException("Invalid join type [{$type}].");
        }
        $join = new JoinClause($this, $type, $table);
        if ($first instanceof Closure) {
            $first($join);
        } else {
            if ($second === null) {
                [$operator, $second] = ['=', $operator];
            }
            $join->on($first, $operator, (string) $second);
        }
        $this->joins[] = $join;
        array_push($this->bindings['join'], ...$join->getBindings());

        return $this;
    }

    public function leftJoin(string|Expression $table, Closure|string $first, ?string $operator = null, ?string $second = null): static
    {
        return $this->join($table, $first, $operator, $second, 'left');
    }

    public function rightJoin(string|Expression $table, Closure|string $first, ?string $operator = null, ?string $second = null): static
    {
        return $this->join($table, $first, $operator, $second, 'right');
    }

    public function crossJoin(string|Expression $table): static
    {
        $this->joins[] = new JoinClause($this, 'cross', $table);

        return $this;
    }

    // ------------------------------------------------------------------ where

    /**
     * where('votes', 100) · where('votes', '>', 100) · where(['a' => 1, ['b', '>', 2]]) ·
     * where(fn ($q) => $q->where(…)->orWhere(…)) (nested group)
     */
    public function where(Closure|string|array|Expression $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): static
    {
        if (is_array($column)) {
            return $this->whereNested(function (self $q) use ($column): void {
                foreach ($column as $key => $val) {
                    if (is_int($key) && is_array($val)) {
                        $q->where(...array_values($val));
                    } else {
                        $q->where((string) $key, '=', $val);
                    }
                }
            }, $boolean);
        }
        if ($column instanceof Closure && $operator === null) {
            return $this->whereNested($column, $boolean);
        }
        if (func_num_args() === 2 || ($value === null && !$this->isOperator($operator))) {
            [$value, $operator] = [$operator, '='];
        }
        $operator = strtolower((string) $operator);
        if (!$this->isOperator($operator)) {
            throw new InvalidArgumentException("Illegal operator [{$operator}].");
        }
        if ($value === null) {
            return match ($operator) {
                '=' => $this->whereNull($column, $boolean),
                '!=', '<>' => $this->whereNull($column, $boolean, true),
                default => throw new InvalidArgumentException('Null value only allowed with = / != / <>.'),
            };
        }
        if ($value instanceof Closure) {
            $sub = $this->newQuery();
            $value($sub);
            $this->wheres[] = ['type' => 'Sub', 'column' => $column, 'operator' => $operator, 'query' => $sub, 'boolean' => $boolean];
            array_push($this->bindings['where'], ...$sub->getBindings());

            return $this;
        }
        $this->wheres[] = ['type' => 'Basic', 'column' => $column, 'operator' => $operator, 'value' => $value, 'boolean' => $boolean];
        if (!$value instanceof Expression) {
            $this->bindings['where'][] = $value;
        }

        return $this;
    }

    public function orWhere(Closure|string|array|Expression $column, mixed $operator = null, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            return $this->where($column, '=', $operator, 'or');
        }

        return $this->where($column, $operator, $value, 'or');
    }

    public function whereNot(Closure $callback, string $boolean = 'and'): static
    {
        return $this->whereNested($callback, $boolean . ' not');
    }

    public function whereColumn(string $first, ?string $operator = null, ?string $second = null, string $boolean = 'and'): static
    {
        if ($second === null) {
            [$operator, $second] = ['=', $operator];
        }
        $operator = strtolower((string) $operator);
        if (!$this->isOperator($operator)) {
            throw new InvalidArgumentException("Illegal operator [{$operator}].");
        }
        $this->wheres[] = ['type' => 'Column', 'first' => $first, 'operator' => $operator, 'second' => (string) $second, 'boolean' => $boolean];

        return $this;
    }

    public function orWhereColumn(string $first, ?string $operator = null, ?string $second = null): static
    {
        return $this->whereColumn($first, $operator, $second, 'or');
    }

    /** @param list<mixed> $bindings */
    public function whereRaw(string $sql, array $bindings = [], string $boolean = 'and'): static
    {
        $this->wheres[] = ['type' => 'Raw', 'sql' => $sql, 'boolean' => $boolean];
        array_push($this->bindings['where'], ...$bindings);

        return $this;
    }

    /** @param list<mixed> $bindings */
    public function orWhereRaw(string $sql, array $bindings = []): static
    {
        return $this->whereRaw($sql, $bindings, 'or');
    }

    /** @param iterable<mixed>|Closure $values */
    public function whereIn(string|Expression $column, iterable|Closure $values, string $boolean = 'and', bool $not = false): static
    {
        if ($values instanceof Closure) {
            $sub = $this->newQuery();
            $values($sub);
            $this->wheres[] = ['type' => $not ? 'NotInSub' : 'InSub', 'column' => $column, 'query' => $sub, 'boolean' => $boolean];
            array_push($this->bindings['where'], ...$sub->getBindings());

            return $this;
        }
        $list = [];
        foreach ($values as $v) {
            $list[] = $v;
        }
        $this->wheres[] = ['type' => $not ? 'NotIn' : 'In', 'column' => $column, 'values' => $list, 'boolean' => $boolean];
        foreach ($list as $v) {
            if (!$v instanceof Expression) {
                $this->bindings['where'][] = $v;
            }
        }

        return $this;
    }

    /** @param iterable<mixed>|Closure $values */
    public function orWhereIn(string $column, iterable|Closure $values): static
    {
        return $this->whereIn($column, $values, 'or');
    }

    /** @param iterable<mixed>|Closure $values */
    public function whereNotIn(string $column, iterable|Closure $values, string $boolean = 'and'): static
    {
        return $this->whereIn($column, $values, $boolean, true);
    }

    /** @param iterable<mixed>|Closure $values */
    public function orWhereNotIn(string $column, iterable|Closure $values): static
    {
        return $this->whereIn($column, $values, 'or', true);
    }

    public function whereNull(string|Expression|array $columns, string $boolean = 'and', bool $not = false): static
    {
        foreach (is_array($columns) ? $columns : [$columns] as $column) {
            $this->wheres[] = ['type' => $not ? 'NotNull' : 'Null', 'column' => $column, 'boolean' => $boolean];
        }

        return $this;
    }

    public function orWhereNull(string $column): static
    {
        return $this->whereNull($column, 'or');
    }

    public function whereNotNull(string|Expression|array $columns, string $boolean = 'and'): static
    {
        return $this->whereNull($columns, $boolean, true);
    }

    public function orWhereNotNull(string $column): static
    {
        return $this->whereNull($column, 'or', true);
    }

    /** @param array{0: mixed, 1: mixed} $values */
    public function whereBetween(string|Expression $column, array $values, string $boolean = 'and', bool $not = false): static
    {
        $values = array_values($values);
        if (count($values) !== 2) {
            throw new InvalidArgumentException('whereBetween expects exactly two values.');
        }
        $this->wheres[] = ['type' => 'Between', 'column' => $column, 'values' => $values, 'not' => $not, 'boolean' => $boolean];
        foreach ($values as $v) {
            if (!$v instanceof Expression) {
                $this->bindings['where'][] = $v;
            }
        }

        return $this;
    }

    /** @param array{0: mixed, 1: mixed} $values */
    public function orWhereBetween(string $column, array $values): static
    {
        return $this->whereBetween($column, $values, 'or');
    }

    /** @param array{0: mixed, 1: mixed} $values */
    public function whereNotBetween(string $column, array $values, string $boolean = 'and'): static
    {
        return $this->whereBetween($column, $values, $boolean, true);
    }

    public function whereExists(Closure $callback, string $boolean = 'and', bool $not = false): static
    {
        $sub = $this->newQuery();
        $callback($sub);
        $this->wheres[] = ['type' => $not ? 'NotExists' : 'Exists', 'query' => $sub, 'boolean' => $boolean];
        array_push($this->bindings['where'], ...$sub->getBindings());

        return $this;
    }

    public function whereNotExists(Closure $callback, string $boolean = 'and'): static
    {
        return $this->whereExists($callback, $boolean, true);
    }

    public function whereNested(Closure $callback, string $boolean = 'and'): static
    {
        $query = $this->newQuery();
        $callback($query);

        return $this->addNestedWhereQuery($query, $boolean);
    }

    public function addNestedWhereQuery(self $query, string $boolean = 'and'): static
    {
        if ($query->wheres !== []) {
            $this->wheres[] = ['type' => 'Nested', 'query' => $query, 'boolean' => $boolean];
            array_push($this->bindings['where'], ...$query->bindings['where']);
        }

        return $this;
    }

    // ------------------------------------------------------------------ group / having / order / limit

    public function groupBy(string|Expression|array ...$groups): static
    {
        foreach ($groups as $g) {
            foreach (is_array($g) ? $g : [$g] as $col) {
                $this->groups[] = $col;
            }
        }

        return $this;
    }

    public function having(string|Expression $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): static
    {
        if (func_num_args() === 2) {
            [$value, $operator] = [$operator, '='];
        }
        $operator = strtolower((string) $operator);
        if (!$this->isOperator($operator)) {
            throw new InvalidArgumentException("Illegal operator [{$operator}].");
        }
        $this->havings[] = ['type' => 'Basic', 'column' => $column, 'operator' => $operator, 'value' => $value, 'boolean' => $boolean];
        if (!$value instanceof Expression) {
            $this->bindings['having'][] = $value;
        }

        return $this;
    }

    public function orHaving(string|Expression $column, mixed $operator = null, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            return $this->having($column, '=', $operator, 'or');
        }

        return $this->having($column, $operator, $value, 'or');
    }

    /** @param list<mixed> $bindings */
    public function havingRaw(string $sql, array $bindings = [], string $boolean = 'and'): static
    {
        $this->havings[] = ['type' => 'Raw', 'sql' => $sql, 'boolean' => $boolean];
        array_push($this->bindings['having'], ...$bindings);

        return $this;
    }

    public function orderBy(string|Expression $column, string $direction = 'asc'): static
    {
        $direction = strtolower($direction);
        if (!in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('Order direction must be "asc" or "desc".');
        }
        $this->orders[] = ['column' => $column, 'direction' => $direction];

        return $this;
    }

    public function orderByDesc(string|Expression $column): static
    {
        return $this->orderBy($column, 'desc');
    }

    /** @param list<mixed> $bindings */
    public function orderByRaw(string $sql, array $bindings = []): static
    {
        $this->orders[] = ['type' => 'Raw', 'sql' => $sql];
        array_push($this->bindings['order'], ...$bindings);

        return $this;
    }

    public function latest(string $column = 'created_at'): static
    {
        return $this->orderBy($column, 'desc');
    }

    public function oldest(string $column = 'created_at'): static
    {
        return $this->orderBy($column, 'asc');
    }

    public function inRandomOrder(): static
    {
        $this->orders[] = ['type' => 'Raw', 'sql' => $this->connection->getGrammar()->compileRandom()];

        return $this;
    }

    public function reorder(): static
    {
        $this->orders = [];
        $this->bindings['order'] = [];

        return $this;
    }

    public function limit(?int $value): static
    {
        $this->limit = $value !== null && $value >= 0 ? $value : null;

        return $this;
    }

    public function take(?int $value): static
    {
        return $this->limit($value);
    }

    public function offset(?int $value): static
    {
        $this->offset = $value !== null && $value > 0 ? $value : null;

        return $this;
    }

    public function skip(?int $value): static
    {
        return $this->offset($value);
    }

    public function forPage(int $page, int $perPage = 15): static
    {
        return $this->offset(max(0, ($page - 1) * $perPage))->limit($perPage);
    }

    public function lockForUpdate(): static
    {
        $this->lock = true;

        return $this;
    }

    public function sharedLock(): static
    {
        $this->lock = false;

        return $this;
    }

    /**
     * @param callable(static, mixed): mixed $callback
     * @param (callable(static, mixed): mixed)|null $default
     */
    public function when(mixed $value, callable $callback, ?callable $default = null): static
    {
        $value = $value instanceof Closure ? $value($this) : $value;
        if ($value) {
            return $callback($this, $value) ?? $this;
        }
        if ($default !== null) {
            return $default($this, $value) ?? $this;
        }

        return $this;
    }

    /** @param callable(static): mixed $callback */
    public function tap(callable $callback): static
    {
        $callback($this);

        return $this;
    }

    // ------------------------------------------------------------------ execution: reads

    /** @param list<string|Expression>|string $columns @return Collection<int, array<string, mixed>> */
    public function get(array|string $columns = ['*']): Collection
    {
        return new Collection($this->runSelect($columns));
    }

    /** @param list<string|Expression>|string $columns @return list<array<string, mixed>> */
    public function runSelect(array|string $columns = ['*']): array
    {
        $original = $this->columns;
        if ($original === null) {
            $this->columns = is_array($columns) ? $columns : [$columns];
        }
        try {
            return $this->connection->select($this->toSql(), $this->getBindings());
        } finally {
            $this->columns = $original;
        }
    }

    /** @param list<string|Expression>|string $columns @return array<string, mixed>|null */
    public function first(array|string $columns = ['*']): ?array
    {
        return (clone $this)->limit(1)->runSelect($columns)[0] ?? null;
    }

    /** @param list<string|Expression>|string $columns @return array<string, mixed>|null */
    public function find(int|string $id, array|string $columns = ['*'], string $key = 'id'): ?array
    {
        return (clone $this)->where($key, '=', $id)->first($columns);
    }

    public function value(string|Expression $column): mixed
    {
        $row = (clone $this)->select($column)->first();

        return $row === null ? null : reset($row);
    }

    /** @return Collection<array-key, mixed> */
    public function pluck(string $column, ?string $key = null): Collection
    {
        $cols = $key === null ? [$column] : [$column, $key];
        $rows = (clone $this)->select(...$cols)->runSelect();
        $valueKey = self::stripColumn($column);
        $keyKey = $key !== null ? self::stripColumn($key) : null;
        $out = [];
        foreach ($rows as $row) {
            if ($keyKey === null) {
                $out[] = $row[$valueKey] ?? null;
            } else {
                $out[(string) ($row[$keyKey] ?? '')] = $row[$valueKey] ?? null;
            }
        }

        return new Collection($out);
    }

    /** @return Generator<int, array<string, mixed>> one connection held while iterating */
    public function cursor(): Generator
    {
        $q = clone $this;
        $q->columns ??= ['*'];

        yield from $this->connection->cursor($q->toSql(), $q->getBindings());
    }

    /** @param callable(Collection<int, array<string, mixed>>, int): (bool|void) $callback */
    public function chunk(int $count, callable $callback): bool
    {
        if ($this->orders === []) {
            throw new \LogicException('chunk() needs an orderBy() for stable pages (or use chunkById()).');
        }
        for ($page = 1; ; $page++) {
            $rows = (clone $this)->forPage($page, $count)->get();
            if ($rows->isEmpty()) {
                return true;
            }
            if ($callback($rows, $page) === false) {
                return false;
            }
            if ($rows->count() < $count) {
                return true;
            }
        }
    }

    /** @param callable(Collection<int, array<string, mixed>>, int): (bool|void) $callback */
    public function chunkById(int $count, callable $callback, string $column = 'id', ?string $alias = null): bool
    {
        $alias ??= self::stripColumn($column);
        $last = null;
        for ($page = 1; ; $page++) {
            $q = (clone $this)->reorder()->orderBy($column)->limit($count);
            if ($last !== null) {
                $q->where($column, '>', $last);
            }
            $rows = $q->get();
            if ($rows->isEmpty()) {
                return true;
            }
            if ($callback($rows, $page) === false) {
                return false;
            }
            $lastRow = $rows->last();
            $last = is_array($lastRow) ? ($lastRow[$alias] ?? null) : $lastRow->{$alias};
            if ($rows->count() < $count || $last === null) {
                return true;
            }
        }
    }

    public function count(string|Expression $column = '*'): int
    {
        return (int) $this->aggregate('count', [$column]);
    }

    public function sum(string|Expression $column): int|float
    {
        $v = $this->aggregate('sum', [$column]);

        return $v === null ? 0 : $v + 0;
    }

    public function avg(string|Expression $column): ?float
    {
        $v = $this->aggregate('avg', [$column]);

        return $v === null ? null : (float) $v;
    }

    public function max(string|Expression $column): mixed
    {
        return $this->aggregate('max', [$column]);
    }

    public function min(string|Expression $column): mixed
    {
        return $this->aggregate('min', [$column]);
    }

    /** @param list<string|Expression> $columns */
    public function aggregate(string $function, array $columns = ['*']): mixed
    {
        $q = clone $this;
        $q->bindings['select'] = [];
        $q->columns = null;
        $q->orders = [];
        $q->bindings['order'] = [];
        $q->limit = null;
        $q->offset = null;
        $q->aggregate = ['function' => $function, 'columns' => $columns];
        if ($this->groups !== [] || $this->distinct) {
            // aggregate over the grouped / distinct result set
            $inner = clone $this;
            $inner->orders = [];
            $inner->bindings['order'] = [];
            $inner->columns ??= $this->groups !== [] ? $this->groups : ['*'];
            $sql = sprintf('select %s(*) as %s from (%s) as %s', $function === 'count' ? 'count' : $function, $this->connection->getGrammar()->wrap('aggregate'), $inner->toSql(), $this->connection->getGrammar()->wrapTable('agg_sub'));
            $row = $this->connection->selectOne($sql, $inner->getBindings());

            return $row['aggregate'] ?? null;
        }
        $row = $this->connection->selectOne($q->toSql(), $q->getBindings());

        return $row['aggregate'] ?? null;
    }

    public function exists(): bool
    {
        $q = clone $this;
        $q->orders = [];
        $q->bindings['order'] = [];
        $sql = $this->connection->getGrammar()->compileExists($q);
        $row = $this->connection->selectOne($sql, $q->getBindings());

        return (bool) ($row['exists'] ?? false);
    }

    public function doesntExist(): bool
    {
        return !$this->exists();
    }

    /** @param list<string|Expression> $columns */
    public function paginate(int $perPage = 15, int $page = 1, array $columns = ['*']): Paginator
    {
        $page = max(1, $page);
        $total = $this->getCountForPagination();
        $items = $total > 0 ? (clone $this)->forPage($page, $perPage)->get($columns) : new Collection();

        return new Paginator($items, $total, $perPage, $page);
    }

    public function getCountForPagination(): int
    {
        $q = clone $this;
        $q->limit = null;
        $q->offset = null;

        return $q->count();
    }

    // ------------------------------------------------------------------ execution: writes

    /** @param array<string, mixed>|list<array<string, mixed>> $values */
    public function insert(array $values): bool
    {
        if ($values === []) {
            return true;
        }
        $rows = array_is_list($values) && is_array($values[0] ?? null) ? $values : [$values];
        $cols = array_keys($rows[0]);
        foreach ($rows as $i => $row) {
            if (array_keys($row) !== $cols) {
                ksort($row);
                $sorted = $cols;
                sort($sorted);
                if (array_keys($row) !== $sorted) {
                    throw new InvalidArgumentException('All rows of a bulk insert must have the same columns.');
                }
                $rows[$i] = array_replace(array_flip($cols), $row);
            }
        }

        return $this->connection->insert($this->connection->getGrammar()->compileInsert($this, $rows), self::valueBindings($rows));
    }

    /** @param array<string, mixed> $values */
    public function insertGetId(array $values, ?string $sequence = null): int|string
    {
        return $this->connection->insertGetId($this->connection->getGrammar()->compileInsert($this, [$values]), self::valueBindings([$values]), $sequence);
    }

    /** @param array<string, mixed>|list<array<string, mixed>> $values */
    public function insertOrIgnore(array $values): int
    {
        $rows = array_is_list($values) && is_array($values[0] ?? null) ? $values : [$values];

        return $this->connection->affectingStatement($this->connection->getGrammar()->compileInsertOrIgnore($this, $rows), self::valueBindings($rows));
    }

    /** @param array<string, mixed> $values */
    public function update(array $values): int
    {
        $grammar = $this->connection->getGrammar();
        $sql = $grammar->compileUpdate($this, $values);
        $bindings = array_merge(
            array_values(array_filter($values, static fn (mixed $v): bool => !$v instanceof Expression)),
            $this->bindings['join'],
            $this->bindings['where'],
            $this->bindings['order'],
        );

        return $this->connection->update($sql, $bindings);
    }

    /** @param array<string, mixed> $extra */
    public function increment(string $column, int|float $amount = 1, array $extra = []): int
    {
        $wrapped = $this->connection->getGrammar()->wrap($column);

        return $this->update([$column => new Expression($wrapped . ' + ' . (is_int($amount) ? $amount : (float) $amount))] + $extra);
    }

    /** @param array<string, mixed> $extra */
    public function decrement(string $column, int|float $amount = 1, array $extra = []): int
    {
        $wrapped = $this->connection->getGrammar()->wrap($column);

        return $this->update([$column => new Expression($wrapped . ' - ' . (is_int($amount) ? $amount : (float) $amount))] + $extra);
    }

    public function delete(int|string|null $id = null): int
    {
        if ($id !== null) {
            $this->where('id', '=', $id);
        }

        return $this->connection->delete($this->connection->getGrammar()->compileDelete($this), array_merge($this->bindings['join'], $this->bindings['where'], $this->bindings['order']));
    }

    public function truncate(): void
    {
        $this->connection->statement($this->connection->getGrammar()->compileTruncate($this));
    }

    // ------------------------------------------------------------------ SQL

    public function toSql(): string
    {
        return $this->connection->getGrammar()->compileSelect($this);
    }

    /** @return list<mixed> */
    public function getBindings(): array
    {
        return array_values(array_merge(
            $this->bindings['select'],
            $this->bindings['from'],
            $this->bindings['join'],
            $this->bindings['where'],
            $this->bindings['groupBy'],
            $this->bindings['having'],
            $this->bindings['order'],
        ));
    }

    public function __clone()
    {
        foreach ($this->joins as $i => $join) {
            $this->joins[$i] = clone $join;
        }
    }

    public function isOperator(mixed $operator): bool
    {
        return is_string($operator) && in_array(strtolower($operator), self::OPERATORS, true);
    }

    public static function stripColumn(string $column): string
    {
        if (preg_match('/\s+as\s+(\S+)$/i', $column, $m)) {
            return $m[1];
        }
        $pos = strrpos($column, '.');

        return $pos === false ? $column : substr($column, $pos + 1);
    }

    /** @param list<array<string, mixed>> $rows @return list<mixed> */
    private static function valueBindings(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            foreach ($row as $v) {
                if (!$v instanceof Expression) {
                    $out[] = $v;
                }
            }
        }

        return $out;
    }
}

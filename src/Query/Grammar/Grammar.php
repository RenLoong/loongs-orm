<?php

declare(strict_types=1);

namespace Loongs\Orm\Query\Grammar;

use Loongs\Orm\Query\Builder;
use Loongs\Orm\Query\Expression;
use Loongs\Orm\Query\JoinClause;

/**
 * SQL compiler. Values are always "?" placeholders; identifiers are quoted by wrapValue().
 * Subclass per dialect (MySqlGrammar) and register it with Orm::grammar().
 */
abstract class Grammar
{
    /**
     * Table prefix of the connection this grammar compiles for (set per Connection handle by
     * Orm::grammar(); never shared). Applied to every table name and to the table segment of
     * qualified columns ("posts.id" → `app_posts`.`id`), including aliases ("users as u" →
     * `app_users` as `app_u`, so "u.id" → `app_u`.`id`). Expressions are never touched.
     */
    protected string $tablePrefix = '';

    /** Quote one identifier segment. */
    abstract public function wrapValue(string $value): string;

    public function setTablePrefix(string $prefix): static
    {
        $this->tablePrefix = $prefix;

        return $this;
    }

    public function getTablePrefix(): string
    {
        return $this->tablePrefix;
    }

    public function compileRandom(): string
    {
        return 'RANDOM()';
    }

    /** Column (or "table.column", "db.table.column", "col as alias"); the table segment gets the prefix. */
    public function wrap(string|Expression $value): string
    {
        if ($value instanceof Expression) {
            return (string) $value;
        }
        if (preg_match('/^(.+?)\s+as\s+(.+)$/i', $value, $m)) {
            return $this->wrap($m[1]) . ' as ' . $this->wrapValue(trim($m[2]));
        }
        $segments = explode('.', $value);
        $last = count($segments) - 1;
        $out = [];
        foreach ($segments as $i => $seg) {
            if ($seg === '*' && $i === $last) {
                $out[] = '*';
            } elseif ($last > 0 && $i === $last - 1) {
                $out[] = $this->wrapValue($this->tablePrefix . $seg);   // table segment
            } else {
                $out[] = $this->wrapValue($seg);
            }
        }

        return implode('.', $out);
    }

    /** Table name ("users", "db.users", "users as u"), prefixed. */
    public function wrapTable(string|Expression $table): string
    {
        if ($table instanceof Expression) {
            return (string) $table;
        }
        if (preg_match('/^(.+?)\s+as\s+(.+)$/i', $table, $m)) {
            return $this->wrapTable($m[1]) . ' as ' . $this->wrapValue($this->tablePrefix . trim($m[2]));
        }
        $segments = explode('.', $table);
        $name = array_pop($segments);

        return implode('.', [...array_map($this->wrapValue(...), $segments), $this->wrapValue($this->tablePrefix . $name)]);
    }

    /** @param list<string|Expression> $columns */
    public function columnize(array $columns): string
    {
        return implode(', ', array_map($this->wrap(...), $columns));
    }

    public function parameter(mixed $value): string
    {
        return $value instanceof Expression ? (string) $value : '?';
    }

    /** @param list<mixed> $values */
    public function parameterize(array $values): string
    {
        return implode(', ', array_map($this->parameter(...), $values));
    }

    public function compileSelect(Builder $q): string
    {
        if ($q->from === null) {
            throw new \LogicException('Query has no table (call from() / table()).');
        }
        $sql = [];
        if ($q->aggregate !== null) {
            $col = $q->aggregate['columns'] === ['*'] ? '*' : $this->columnize($q->aggregate['columns']);
            if ($q->distinct && $col !== '*') {
                $col = 'distinct ' . $col;
            }
            $sql[] = sprintf('select %s(%s) as %s', $q->aggregate['function'], $col, $this->wrapValue('aggregate'));
        } else {
            $sql[] = ($q->distinct ? 'select distinct ' : 'select ') . $this->columnize($q->columns ?? ['*']);
        }
        $sql[] = 'from ' . $this->wrapTable($q->from);
        foreach ($q->joins as $join) {
            $sql[] = $this->compileJoin($join);
        }
        if ($q->wheres !== []) {
            $sql[] = 'where ' . $this->compileWheres($q);
        }
        if ($q->groups !== []) {
            $sql[] = 'group by ' . $this->columnize($q->groups);
        }
        if ($q->havings !== []) {
            $sql[] = 'having ' . $this->compileHavings($q);
        }
        if ($q->orders !== [] && $q->aggregate === null) {
            $sql[] = 'order by ' . $this->compileOrders($q);
        }
        if ($q->limit !== null) {
            $sql[] = 'limit ' . $q->limit;
        }
        if ($q->offset !== null) {
            $sql[] = ($q->limit === null ? $this->compileOffsetWithoutLimit() . ' ' : '') . 'offset ' . $q->offset;
        }
        if ($q->lock !== null) {
            $sql[] = $this->compileLock($q->lock);
        }

        return implode(' ', $sql);
    }

    public function compileOffsetWithoutLimit(): string
    {
        return 'limit -1';
    }

    public function compileJoin(JoinClause $join): string
    {
        $sql = $join->type . ' join ' . $this->wrapTable($join->table);
        if ($join->wheres !== []) {
            $sql .= ' on ' . $this->compileWheres($join);
        }

        return $sql;
    }

    public function compileWheres(Builder $q): string
    {
        $parts = [];
        foreach ($q->wheres as $i => $w) {
            $sql = match ($w['type']) {
                'Basic' => $this->wrap($w['column']) . ' ' . $w['operator'] . ' ' . $this->parameter($w['value']),
                'Column' => $this->wrap($w['first']) . ' ' . $w['operator'] . ' ' . $this->wrap($w['second']),
                'Raw' => $w['sql'],
                'In' => $w['values'] === [] ? '0 = 1' : $this->wrap($w['column']) . ' in (' . $this->parameterize($w['values']) . ')',
                'NotIn' => $w['values'] === [] ? '1 = 1' : $this->wrap($w['column']) . ' not in (' . $this->parameterize($w['values']) . ')',
                'InSub' => $this->wrap($w['column']) . ' in (' . $this->compileSelect($w['query']) . ')',
                'NotInSub' => $this->wrap($w['column']) . ' not in (' . $this->compileSelect($w['query']) . ')',
                'Sub' => $this->wrap($w['column']) . ' ' . $w['operator'] . ' (' . $this->compileSelect($w['query']) . ')',
                'Null' => $this->wrap($w['column']) . ' is null',
                'NotNull' => $this->wrap($w['column']) . ' is not null',
                'Between' => $this->wrap($w['column']) . ($w['not'] ? ' not between ' : ' between ') . $this->parameter($w['values'][0]) . ' and ' . $this->parameter($w['values'][1]),
                'Nested' => '(' . $this->compileWheres($w['query']) . ')',
                'Exists' => 'exists (' . $this->compileSelect($w['query']) . ')',
                'NotExists' => 'not exists (' . $this->compileSelect($w['query']) . ')',
                default => throw new \LogicException('Unknown where type ' . $w['type']),
            };
            $boolean = $w['boolean'];
            if ($i === 0) {
                // leading boolean dropped; keep "not" of whereNot
                $parts[] = str_ends_with($boolean, ' not') ? 'not ' . $sql : $sql;
            } else {
                $parts[] = $boolean . ' ' . $sql;
            }
        }

        return implode(' ', $parts);
    }

    public function compileHavings(Builder $q): string
    {
        $parts = [];
        foreach ($q->havings as $i => $h) {
            $sql = $h['type'] === 'Raw' ? $h['sql'] : $this->wrap($h['column']) . ' ' . $h['operator'] . ' ' . $this->parameter($h['value']);
            $parts[] = ($i === 0 ? '' : $h['boolean'] . ' ') . $sql;
        }

        return implode(' ', $parts);
    }

    public function compileOrders(Builder $q): string
    {
        return implode(', ', array_map(fn (array $o): string => isset($o['type']) ? $o['sql'] : $this->wrap($o['column']) . ' ' . $o['direction'], $q->orders));
    }

    public function compileLock(string|bool $lock): string
    {
        return is_string($lock) ? $lock : ($lock ? 'for update' : 'for share');
    }

    public function compileExists(Builder $q): string
    {
        $inner = clone $q;
        $inner->columns ??= [new Expression('1')];

        return 'select exists(' . $this->compileSelect($inner) . ') as ' . $this->wrapValue('exists');
    }

    /** @param list<array<string, mixed>> $rows */
    public function compileInsert(Builder $q, array $rows): string
    {
        $table = $this->wrapTable($q->from ?? throw new \LogicException('insert() needs a table'));
        if ($rows === [[]] || $rows === []) {
            return "insert into {$table} default values";
        }
        $columns = $this->columnize(array_keys($rows[0]));
        $values = implode(', ', array_map(fn (array $r): string => '(' . $this->parameterize(array_values($r)) . ')', $rows));

        return "insert into {$table} ({$columns}) values {$values}";
    }

    /** @param list<array<string, mixed>> $rows */
    public function compileInsertOrIgnore(Builder $q, array $rows): string
    {
        return 'insert or ignore' . substr($this->compileInsert($q, $rows), 6);
    }

    /** @param array<string, mixed> $values */
    public function compileUpdate(Builder $q, array $values): string
    {
        $table = $this->wrapTable($q->from ?? throw new \LogicException('update() needs a table'));
        $set = implode(', ', array_map(fn (string $col, mixed $v): string => $this->wrap($col) . ' = ' . $this->parameter($v), array_keys($values), $values));
        $joins = implode(' ', array_map($this->compileJoin(...), $q->joins));
        $where = $q->wheres !== [] ? ' where ' . $this->compileWheres($q) : '';

        return trim("update {$table} " . ($joins !== '' ? $joins . ' ' : '') . "set {$set}{$where}");
    }

    public function compileDelete(Builder $q): string
    {
        $table = $this->wrapTable($q->from ?? throw new \LogicException('delete() needs a table'));
        $where = $q->wheres !== [] ? ' where ' . $this->compileWheres($q) : '';

        return "delete from {$table}{$where}";
    }

    public function compileTruncate(Builder $q): string
    {
        return 'delete from ' . $this->wrapTable($q->from ?? '');
    }
}

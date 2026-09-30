<?php

declare(strict_types=1);

namespace Loongs\Orm\Query\Grammar;

use Loongs\Orm\Query\Builder;

final class MySqlGrammar extends Grammar
{
    public function wrapValue(string $value): string
    {
        return '`' . str_replace('`', '``', $value) . '`';
    }

    public function compileRandom(): string
    {
        return 'RAND()';
    }

    public function compileOffsetWithoutLimit(): string
    {
        return 'limit 18446744073709551615';
    }

    public function compileLock(string|bool $lock): string
    {
        return is_string($lock) ? $lock : ($lock ? 'for update' : 'lock in share mode');
    }

    public function compileInsert(Builder $q, array $rows): string
    {
        if ($rows === [[]] || $rows === []) {
            return 'insert into ' . $this->wrapTable($q->from ?? '') . ' () values ()';
        }

        return parent::compileInsert($q, $rows);
    }

    public function compileInsertOrIgnore(Builder $q, array $rows): string
    {
        return 'insert ignore' . substr($this->compileInsert($q, $rows), 6);
    }

    public function compileUpdate(Builder $q, array $values): string
    {
        $sql = parent::compileUpdate($q, $values);
        if ($q->joins === []) {
            if ($q->orders !== []) {
                $sql .= ' order by ' . $this->compileOrders($q);
            }
            if ($q->limit !== null) {
                $sql .= ' limit ' . $q->limit;
            }
        }

        return $sql;
    }

    public function compileDelete(Builder $q): string
    {
        $table = $this->wrapTable($q->from ?? '');
        $where = $q->wheres !== [] ? ' where ' . $this->compileWheres($q) : '';
        if ($q->joins !== []) {
            $alias = preg_match('/\s+as\s+(\S+)$/i', (string) $q->from, $m) ? $this->wrapValue($m[1]) : $table;
            $joins = implode(' ', array_map($this->compileJoin(...), $q->joins));

            return "delete {$alias} from {$table} {$joins}{$where}";
        }
        $sql = "delete from {$table}{$where}";
        if ($q->orders !== []) {
            $sql .= ' order by ' . $this->compileOrders($q);
        }
        if ($q->limit !== null) {
            $sql .= ' limit ' . $q->limit;
        }

        return $sql;
    }

    public function compileTruncate(Builder $q): string
    {
        return 'truncate table ' . $this->wrapTable($q->from ?? '');
    }
}

<?php

declare(strict_types=1);

namespace Loongs\Orm\Support;

use ArrayAccess;
use ArrayIterator;
use Closure;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * @template TKey of array-key
 * @template TValue
 * @implements ArrayAccess<TKey, TValue>
 * @implements IteratorAggregate<TKey, TValue>
 */
class Collection implements ArrayAccess, IteratorAggregate, Countable, JsonSerializable
{
    /** @param array<TKey, TValue> $items */
    public function __construct(protected array $items = [])
    {
    }

    /** @param iterable<mixed> $items */
    public static function make(iterable $items = []): static
    {
        return new static(is_array($items) ? $items : iterator_to_array($items));
    }

    /** @return array<TKey, TValue> */
    public function all(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function isNotEmpty(): bool
    {
        return $this->items !== [];
    }

    public function first(?callable $callback = null, mixed $default = null): mixed
    {
        foreach ($this->items as $k => $v) {
            if ($callback === null || $callback($v, $k)) {
                return $v;
            }
        }

        return $default instanceof Closure ? $default() : $default;
    }

    public function last(?callable $callback = null, mixed $default = null): mixed
    {
        foreach (array_reverse($this->items, true) as $k => $v) {
            if ($callback === null || $callback($v, $k)) {
                return $v;
            }
        }

        return $default instanceof Closure ? $default() : $default;
    }

    public function get(int|string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->items) ? $this->items[$key] : $default;
    }

    public function has(int|string $key): bool
    {
        return array_key_exists($key, $this->items);
    }

    public function map(callable $callback): static
    {
        $out = [];
        foreach ($this->items as $k => $v) {
            $out[$k] = $callback($v, $k);
        }

        return new static($out);
    }

    public function mapWithKeys(callable $callback): static
    {
        $out = [];
        foreach ($this->items as $k => $v) {
            foreach ($callback($v, $k) as $nk => $nv) {
                $out[$nk] = $nv;
            }
        }

        return new static($out);
    }

    public function each(callable $callback): static
    {
        foreach ($this->items as $k => $v) {
            if ($callback($v, $k) === false) {
                break;
            }
        }

        return $this;
    }

    public function filter(?callable $callback = null): static
    {
        return new static($callback === null ? array_filter($this->items) : array_filter($this->items, $callback, ARRAY_FILTER_USE_BOTH));
    }

    public function reject(callable $callback): static
    {
        return $this->filter(static fn ($v, $k): bool => !$callback($v, $k));
    }

    public function where(string $key, mixed $operator = null, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            [$value, $operator] = [$operator, '='];
        }

        return $this->filter(static function ($item) use ($key, $operator, $value): bool {
            $v = self::dataGet($item, $key);

            return match ($operator) {
                '=', '==' => $v == $value,
                '===' => $v === $value,
                '!=', '<>' => $v != $value,
                '!==' => $v !== $value,
                '<' => $v < $value,
                '>' => $v > $value,
                '<=' => $v <= $value,
                '>=' => $v >= $value,
                default => false,
            };
        });
    }

    /** @param iterable<mixed> $values */
    public function whereIn(string $key, iterable $values, bool $strict = false): static
    {
        $values = is_array($values) ? $values : iterator_to_array($values);

        return $this->filter(static fn ($item): bool => in_array(self::dataGet($item, $key), $values, $strict));
    }

    public function firstWhere(string $key, mixed $operator = null, mixed $value = null): mixed
    {
        return (func_num_args() === 2 ? $this->where($key, $operator) : $this->where($key, $operator, $value))->first();
    }

    public function pluck(string $value, ?string $key = null): static
    {
        $out = [];
        foreach ($this->items as $item) {
            $v = self::dataGet($item, $value);
            if ($key === null) {
                $out[] = $v;
            } else {
                $k = self::dataGet($item, $key);
                $out[$k instanceof \BackedEnum ? $k->value : (string) $k] = $v;
            }
        }

        return new static($out);
    }

    public function keyBy(string|callable $key): static
    {
        $out = [];
        foreach ($this->items as $item) {
            $k = is_callable($key) && !is_string($key) ? $key($item) : self::dataGet($item, $key);
            $out[$k instanceof \BackedEnum ? $k->value : (string) $k] = $item;
        }

        return new static($out);
    }

    public function groupBy(string|callable $key): static
    {
        $out = [];
        foreach ($this->items as $item) {
            $k = is_callable($key) && !is_string($key) ? $key($item) : self::dataGet($item, $key);
            $out[$k instanceof \BackedEnum ? $k->value : (string) $k][] = $item;
        }

        return new static(array_map(static fn (array $g): static => new static($g), $out));
    }

    public function values(): static
    {
        return new static(array_values($this->items));
    }

    public function keys(): static
    {
        return new static(array_keys($this->items));
    }

    public function unique(?string $key = null): static
    {
        $seen = [];
        $out = [];
        foreach ($this->items as $k => $item) {
            $v = $key === null ? $item : self::dataGet($item, $key);
            $id = is_scalar($v) || $v === null ? var_export($v, true) : spl_object_id((object) $v);
            if (!isset($seen[$id])) {
                $seen[$id] = true;
                $out[$k] = $item;
            }
        }

        return new static($out);
    }

    public function sortBy(string|callable $key, bool $descending = false): static
    {
        $items = $this->items;
        uasort($items, static function ($a, $b) use ($key, $descending): int {
            $va = is_callable($key) && !is_string($key) ? $key($a) : self::dataGet($a, $key);
            $vb = is_callable($key) && !is_string($key) ? $key($b) : self::dataGet($b, $key);

            return $descending ? $vb <=> $va : $va <=> $vb;
        });

        return new static($items);
    }

    public function sortByDesc(string|callable $key): static
    {
        return $this->sortBy($key, true);
    }

    public function reverse(): static
    {
        return new static(array_reverse($this->items, true));
    }

    public function take(int $limit): static
    {
        return new static($limit < 0 ? array_slice($this->items, $limit, null, true) : array_slice($this->items, 0, $limit, true));
    }

    public function slice(int $offset, ?int $length = null): static
    {
        return new static(array_slice($this->items, $offset, $length, true));
    }

    public function chunk(int $size): static
    {
        return new static(array_map(static fn (array $c): static => new static($c), array_chunk($this->items, max(1, $size), true)));
    }

    /** @param iterable<mixed> $items */
    public function merge(iterable $items): static
    {
        $items = $items instanceof self ? $items->all() : (is_array($items) ? $items : iterator_to_array($items));

        return new static(array_merge($this->items, $items));
    }

    public function push(mixed ...$values): static
    {
        foreach ($values as $v) {
            $this->items[] = $v;
        }

        return $this;
    }

    public function put(int|string $key, mixed $value): static
    {
        $this->items[$key] = $value;

        return $this;
    }

    public function contains(mixed $key, mixed $operator = null, mixed $value = null): bool
    {
        if (func_num_args() === 1) {
            if (is_callable($key) && !is_string($key)) {
                return $this->first($key) !== null;
            }

            return in_array($key, $this->items, false);
        }

        return (func_num_args() === 2 ? $this->where($key, $operator) : $this->where($key, $operator, $value))->isNotEmpty();
    }

    public function sum(string|callable|null $key = null): int|float
    {
        $total = 0;
        foreach ($this->items as $item) {
            $total += $key === null ? $item : (is_callable($key) && !is_string($key) ? $key($item) : self::dataGet($item, $key));
        }

        return $total;
    }

    public function avg(string|callable|null $key = null): int|float|null
    {
        return $this->items === [] ? null : $this->sum($key) / count($this->items);
    }

    public function max(?string $key = null): mixed
    {
        $vals = $key === null ? $this->items : $this->pluck($key)->all();

        return $vals === [] ? null : max($vals);
    }

    public function min(?string $key = null): mixed
    {
        $vals = $key === null ? $this->items : $this->pluck($key)->all();

        return $vals === [] ? null : min($vals);
    }

    public function implode(string $glue, ?string $key = null): string
    {
        return implode($glue, $key === null ? $this->items : $this->pluck($key)->all());
    }

    public function reduce(callable $callback, mixed $initial = null): mixed
    {
        return array_reduce($this->items, $callback, $initial);
    }

    /** @return array<array-key, mixed> */
    public function toArray(): array
    {
        return array_map(static fn ($v) => match (true) {
            is_object($v) && method_exists($v, 'toArray') => $v->toArray(),
            $v instanceof \BackedEnum => $v->value,
            default => $v,
        }, $this->items);
    }

    public function toJson(int $flags = 0): string
    {
        return json_encode($this->jsonSerialize(), $flags | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->items[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $offset === null ? $this->items[] = $value : $this->items[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
    }

    public static function dataGet(mixed $target, string $key): mixed
    {
        foreach (explode('.', $key) as $seg) {
            if (is_array($target)) {
                $target = $target[$seg] ?? null;
            } elseif ($target instanceof ArrayAccess) {
                $target = $target[$seg] ?? null;
            } elseif (is_object($target)) {
                $target = $target->{$seg} ?? null;
            } else {
                return null;
            }
        }

        return $target;
    }
}

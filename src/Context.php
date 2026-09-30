<?php

declare(strict_types=1);

namespace Loongs\Orm;

use Swoole\Coroutine;

/**
 * Execution-context-local storage for the ORM (open transactions, tenant scope,
 * "no constraints" flag while building eager relations).
 *
 * Inside a Swoole coroutine the values live in Coroutine::getContext(): they are
 * private to that coroutine, invisible to every other coroutine (child coroutines
 * included) and destroyed by Swoole when the coroutine ends. Outside a coroutine
 * (plain CLI / FPM) there is exactly one flow of execution, so a single array is
 * used. Every writer in the ORM removes its entry in a finally block, so nothing
 * outlives the scope that created it.
 */
final class Context
{
    /** @var array<string, mixed> used only when not running inside a coroutine */
    private static array $main = [];

    public static function inCoroutine(): bool
    {
        return \extension_loaded('swoole') && Coroutine::getCid() > 0;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::inCoroutine()) {
            $ctx = Coroutine::getContext();
            return $ctx !== null && isset($ctx[$key]) ? $ctx[$key] : $default;
        }

        return self::$main[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        if (self::inCoroutine()) {
            $ctx = Coroutine::getContext();
            if ($ctx !== null) {
                $ctx[$key] = $value;
            }
            return;
        }
        self::$main[$key] = $value;
    }

    public static function forget(string $key): void
    {
        if (self::inCoroutine()) {
            $ctx = Coroutine::getContext();
            if ($ctx !== null && isset($ctx[$key])) {
                unset($ctx[$key]);
            }
            return;
        }
        unset(self::$main[$key]);
    }

    /**
     * Run $fn with $key set to $value; the previous value (or absence) is restored afterwards.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function with(string $key, mixed $value, callable $fn): mixed
    {
        $had = self::has($key);
        $prev = self::get($key);
        self::set($key, $value);
        try {
            return $fn();
        } finally {
            $had ? self::set($key, $prev) : self::forget($key);
        }
    }

    public static function has(string $key): bool
    {
        if (self::inCoroutine()) {
            $ctx = Coroutine::getContext();
            return $ctx !== null && isset($ctx[$key]);
        }

        return array_key_exists($key, self::$main);
    }

    /** Keys currently set in this context (diagnostics / tests). @return list<string> */
    public static function keys(): array
    {
        if (self::inCoroutine()) {
            $ctx = Coroutine::getContext();
            return $ctx === null ? [] : array_values(array_filter(array_map('strval', array_keys($ctx->getArrayCopy())), static fn (string $k): bool => str_starts_with($k, 'loongs.orm.')));
        }

        return array_values(array_filter(array_keys(self::$main), static fn (string $k): bool => str_starts_with($k, 'loongs.orm.')));
    }
}

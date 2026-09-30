<?php

declare(strict_types=1);

namespace Loongs\Orm\Model\Concerns;

use Closure;
use Loongs\Orm\Context;

/**
 * Model events (Eloquent-compatible names and order).
 *
 *   retrieved · saving → creating → created → saved · saving → updating → updated → saved
 *   deleting → deleted · restoring → restored · forceDeleting → forceDeleted · replicating
 *
 * Returning false from a "…ing" listener (saving, creating, updating, deleting, restoring,
 * forceDeleting) cancels the operation: save()/delete()/restore()/forceDelete() return false and
 * no SQL is sent. Later listeners of that event are not called.
 *
 * Listeners are class metadata (per concrete class, static, registered once in booted()), never
 * tenant state: they receive the model instance, which carries its own connection, so anything a
 * listener does through $model (relations, $model->newQuery(), $model->getConnection()) runs on
 * the same tenant database.
 *
 * Mass updates / deletes on a query (User::on($t)->where(…)->update([…]) / ->delete()) do NOT
 * fire events — same as Eloquent. Load the models and save/delete them one by one when you need events.
 */
trait HasEvents
{
    /** Context key: events disabled in THIS coroutine (withoutEvents / *Quietly). */
    public const string EVENTS_DISABLED = 'loongs.orm.eventsDisabled';

    /** @var list<string> */
    public const array MODEL_EVENTS = [
        'retrieved', 'creating', 'created', 'updating', 'updated', 'saving', 'saved',
        'deleting', 'deleted', 'restoring', 'restored', 'forceDeleting', 'forceDeleted', 'replicating',
    ];

    /** @var array<class-string, array<string, list<callable>>> listeners per concrete model class */
    private static array $modelListeners = [];

    /**
     * Register a listener for $event on the calling class (static::class).
     *
     * @param callable(static): (bool|void) $callback
     */
    public static function registerModelEvent(string $event, callable $callback): void
    {
        if (!in_array($event, self::MODEL_EVENTS, true)) {
            throw new \InvalidArgumentException("Unknown model event [{$event}].");
        }
        self::$modelListeners[static::class][$event][] = $callback;
    }

    /** @param callable(static): void $callback */
    public static function retrieved(callable $callback): void
    {
        static::registerModelEvent('retrieved', $callback);
    }

    /** @param callable(static): (bool|void) $callback */
    public static function saving(callable $callback): void
    {
        static::registerModelEvent('saving', $callback);
    }

    /** @param callable(static): void $callback */
    public static function saved(callable $callback): void
    {
        static::registerModelEvent('saved', $callback);
    }

    /** @param callable(static): (bool|void) $callback */
    public static function creating(callable $callback): void
    {
        static::registerModelEvent('creating', $callback);
    }

    /** @param callable(static): void $callback */
    public static function created(callable $callback): void
    {
        static::registerModelEvent('created', $callback);
    }

    /** @param callable(static): (bool|void) $callback */
    public static function updating(callable $callback): void
    {
        static::registerModelEvent('updating', $callback);
    }

    /** @param callable(static): void $callback */
    public static function updated(callable $callback): void
    {
        static::registerModelEvent('updated', $callback);
    }

    /** @param callable(static): (bool|void) $callback */
    public static function deleting(callable $callback): void
    {
        static::registerModelEvent('deleting', $callback);
    }

    /** @param callable(static): void $callback */
    public static function deleted(callable $callback): void
    {
        static::registerModelEvent('deleted', $callback);
    }

    /** SoftDeletes only. @param callable(static): (bool|void) $callback */
    public static function restoring(callable $callback): void
    {
        static::registerModelEvent('restoring', $callback);
    }

    /** SoftDeletes only. @param callable(static): void $callback */
    public static function restored(callable $callback): void
    {
        static::registerModelEvent('restored', $callback);
    }

    /** SoftDeletes only. @param callable(static): (bool|void) $callback */
    public static function forceDeleting(callable $callback): void
    {
        static::registerModelEvent('forceDeleting', $callback);
    }

    /** SoftDeletes only. @param callable(static): void $callback */
    public static function forceDeleted(callable $callback): void
    {
        static::registerModelEvent('forceDeleted', $callback);
    }

    /** Fired on the NEW instance returned by replicate(). @param callable(static): void $callback */
    public static function replicating(callable $callback): void
    {
        static::registerModelEvent('replicating', $callback);
    }

    /**
     * Register an observer: every public method named like an event (creating(), saved(), …)
     * becomes a listener. A class name is instantiated once; observers are shared by all tenants
     * and coroutines of the worker, so keep them stateless.
     *
     * @param object|class-string|list<object|class-string> $observers
     */
    public static function observe(object|string|array $observers): void
    {
        foreach (is_array($observers) ? $observers : [$observers] as $observer) {
            $instance = is_string($observer) ? new $observer() : $observer;
            $found = false;
            foreach (self::MODEL_EVENTS as $event) {
                if (method_exists($instance, $event)) {
                    static::registerModelEvent($event, [$instance, $event]);
                    $found = true;
                }
            }
            if (!$found) {
                throw new \InvalidArgumentException(sprintf('Observer [%s] has no model event methods.', $instance::class));
            }
        }
    }

    /** Remove every listener / observer of the calling class (tests). */
    public static function flushEventListeners(): void
    {
        unset(self::$modelListeners[static::class]);
    }

    /** @return array<string, list<callable>> */
    public static function getEventListeners(): array
    {
        return self::$modelListeners[static::class] ?? [];
    }

    /**
     * Run $callback with model events disabled for ALL models — in the current coroutine only
     * (Context), so concurrent requests keep their events. Not inherited by coroutines started
     * inside. Restored afterwards, also on exceptions.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function withoutEvents(callable $callback): mixed
    {
        return Context::with(self::EVENTS_DISABLED, true, $callback);
    }

    public static function eventsDisabled(): bool
    {
        return Context::get(self::EVENTS_DISABLED, false) === true;
    }

    /** @return bool false when a halting ("…ing") listener returned false */
    protected function fireModelEvent(string $event, bool $halt = true): bool
    {
        if (!isset(self::$modelListeners[static::class][$event]) || self::eventsDisabled()) {
            return true;
        }
        foreach (self::$modelListeners[static::class][$event] as $listener) {
            $result = $listener instanceof Closure ? $listener($this) : \call_user_func($listener, $this);
            if ($halt && $result === false) {
                return false;
            }
        }

        return true;
    }
}

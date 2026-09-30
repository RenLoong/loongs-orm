<?php

declare(strict_types=1);

namespace Loongs\Orm\Connection;

use Loongs\Orm\Exceptions\PoolExhaustedException;

/**
 * Glue to loongs/framework's Loongs\Database\DatabaseManager (Swoole PDOPool per worker,
 * booted in WorkerStart). Optional: the ORM does not require the framework.
 *
 * Without an explicit manager it resolves db() lazily on every call (no stored reference),
 * so each worker / fresh Application is picked up automatically.
 */
final class FrameworkPoolProvider implements PoolProvider
{
    public function __construct(private readonly ?object $manager = null)
    {
    }

    public static function available(): bool
    {
        return class_exists(\Loongs\Database\DatabaseManager::class) && function_exists('db');
    }

    public function has(string $name): bool
    {
        $db = $this->manager();
        if ($db === null || !$db->isBooted()) {
            return false;
        }

        return in_array($name, $db->connectionNames(), true);
    }

    public function get(string $name): object
    {
        try {
            $pdo = $this->manager()->connection($name);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'exhausted') || $e instanceof \Loongs\Database\PoolExhaustedException) {
                throw new PoolExhaustedException($e->getMessage(), 0, $e);
            }
            throw $e;
        }
        if (!is_object($pdo)) { // older framework: Channel::pop() timeout returns false
            throw new PoolExhaustedException("Database pool [{$name}] exhausted: no connection freed within its wait_timeout.");
        }

        return $pdo;
    }

    public function put(string $name, object $pdo): void
    {
        $this->manager()->put($pdo, $name);
    }

    public function discard(string $name, object $pdo): void
    {
        $db = $this->manager();
        if (method_exists($db, 'discard')) {
            $db->discard($pdo, $name);
            return;
        }
        // framework without discard(): best effort — roll back and return it (PDOProxy reconnects on use)
        try {
            $pdo->inTransaction() && $pdo->rollBack();
        } catch (\Throwable) {
        }
        $db->put($pdo, $name);
    }

    private function manager(): ?object
    {
        if ($this->manager !== null) {
            return $this->manager;
        }
        if (!self::available() || !(($GLOBALS['__loongs_app'] ?? null) instanceof \Loongs\Http\Application)) {
            return null;
        }
        try {
            return db();
        } catch (\Throwable) {
            return null;
        }
    }
}

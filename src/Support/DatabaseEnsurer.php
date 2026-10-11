<?php

declare(strict_types=1);

namespace Loongs\Orm\Support;

use Loongs\Orm\Connection\ConnectionConfig;
use Loongs\Orm\Connection\PdoFactory;
use Loongs\Orm\Orm;
use PDO;
use PDOException;
use Throwable;

/**
 * Ensure a connection's database exists before install / migrate commands run.
 *
 * Resolves the connection via Orm::config (after Orm::configure). For MySQL/MariaDB,
 * on "Unknown database" (SQLSTATE HY000 / 1049) connects without selecting a database
 * and issues CREATE DATABASE IF NOT EXISTS. Does not require a root account — any
 * account with CREATE privilege is enough; when CREATE is denied the caller gets a
 * clear "create manually" hint.
 *
 * @phpstan-type EnsureResult array{ok: bool, created: bool, message: string, database: string}
 */
final class DatabaseEnsurer
{
    /**
     * @param string|array<string, mixed>|ConnectionConfig|null $spec Connection name, URL, config array, or null (default)
     * @return array{ok: bool, created: bool, message: string, database: string}
     */
    public static function ensureExists(string|array|ConnectionConfig|null $spec = null): array
    {
        $config = Orm::config($spec);
        $database = $config->database();

        if ($database === '') {
            return self::fail(
                '',
                'Database name is empty. Set DB_DATABASE in .env (or config/database.php connections.*.database) before running install. '
                . '数据库名为空：请先在 .env 配置 DB_DATABASE（或 config/database.php 的 database）。'
            );
        }

        $driver = $config->driver();
        if ($driver !== 'mysql') {
            // SQLite creates the file on connect; other drivers: only verify connectivity.
            try {
                PdoFactory::make($config)->query('SELECT 1');
            } catch (Throwable $e) {
                return self::fail($database, self::formatConnectError($e, $config));
            }

            return [
                'ok' => true,
                'created' => false,
                'message' => "Database [{$database}] is reachable (driver={$driver}; auto-create only supported for mysql).",
                'database' => $database,
            ];
        }

        try {
            PdoFactory::make($config)->query('SELECT 1');

            return [
                'ok' => true,
                'created' => false,
                'message' => "Database [{$database}] already exists.",
                'database' => $database,
            ];
        } catch (Throwable $e) {
            if (!self::isUnknownDatabase($e)) {
                return self::fail($database, self::formatConnectError($e, $config));
            }
        }

        return self::createMysqlDatabase($config, $database);
    }

    /**
     * @return array{ok: bool, created: bool, message: string, database: string}
     */
    private static function createMysqlDatabase(ConnectionConfig $config, string $database): array
    {
        $charset = $config->charset();
        $collation = $config->collation() ?? ($charset . '_bin');
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $collation) !== 1) {
            return self::fail($database, "Invalid collation [{$collation}] for CREATE DATABASE.");
        }
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $charset) !== 1) {
            return self::fail($database, "Invalid charset [{$charset}] for CREATE DATABASE.");
        }

        try {
            $pdo = self::serverPdo($config);
        } catch (Throwable $e) {
            return self::fail(
                $database,
                'Cannot connect to MySQL server (without selecting a database) to auto-create '
                . "[{$database}]: " . self::shortError($e)
                . ' — check DB_HOST / DB_PORT / DB_SOCKET / DB_USERNAME / DB_PASSWORD. '
                . '无法连接 MySQL 服务器以自动建库，请检查连接账号与主机配置。'
            );
        }

        $quoted = '`' . str_replace('`', '``', $database) . '`';
        $sql = "CREATE DATABASE IF NOT EXISTS {$quoted} CHARACTER SET {$charset} COLLATE {$collation}";

        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            if (self::isAccessDenied($e)) {
                $user = (string) ($config->config['username'] ?? '');

                return self::fail(
                    $database,
                    "Database [{$database}] does not exist, and user [{$user}] has no CREATE DATABASE privilege. "
                    . 'Create it manually with a privileged account, then re-run install, e.g.: '
                    . "CREATE DATABASE {$quoted} CHARACTER SET {$charset} COLLATE {$collation}; "
                    . "GRANT ALL ON {$quoted}.* TO '{$user}'@'%'; FLUSH PRIVILEGES; "
                    . "数据库 [{$database}] 不存在，且当前账号 [{$user}] 无建库权限：请用有权限的账号手动创建后再执行安装（不必是 root，有 CREATE 权限即可）。"
                );
            }

            return self::fail($database, 'CREATE DATABASE failed: ' . self::shortError($e));
        }

        // Verify the new database is selectable with the app credentials.
        try {
            PdoFactory::make($config)->query('SELECT 1');
        } catch (Throwable $e) {
            return self::fail(
                $database,
                "Database [{$database}] was created, but reconnecting failed: " . self::shortError($e)
                . ' — check GRANT for the app user. 库已创建但应用账号无法连接，请检查授权。'
            );
        }

        return [
            'ok' => true,
            'created' => true,
            'message' => "Database [{$database}] did not exist and was created (charset={$charset}, collation={$collation}). "
                . "数据库 [{$database}] 不存在，已自动创建。",
            'database' => $database,
        ];
    }

    /** PDO connected to the server without selecting a database. */
    private static function serverPdo(ConnectionConfig $c): PDO
    {
        $cfg = $c->config;
        $dsn = $cfg['unix_socket'] !== ''
            ? sprintf('mysql:unix_socket=%s;charset=%s', $cfg['unix_socket'], $cfg['charset'])
            : sprintf('mysql:host=%s;port=%d;charset=%s', $cfg['host'], $cfg['port'], $cfg['charset']);

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
        foreach ($cfg['options'] as $k => $v) {
            $options[$k] = $v;
        }

        return PDO::connect($dsn, (string) $cfg['username'], (string) $cfg['password'], $options);
    }

    private static function isUnknownDatabase(Throwable $e): bool
    {
        for ($t = $e; $t !== null; $t = $t->getPrevious()) {
            $msg = $t->getMessage();
            if (str_contains($msg, 'Unknown database') || str_contains($msg, '1049')) {
                return true;
            }
            if ($t instanceof PDOException) {
                $code = (string) $t->getCode();
                $driver = $t->errorInfo[1] ?? null;
                if ($code === '1049' || $driver === 1049 || ($code === 'HY000' && str_contains($msg, 'Unknown database'))) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function isAccessDenied(Throwable $e): bool
    {
        for ($t = $e; $t !== null; $t = $t->getPrevious()) {
            $msg = $t->getMessage();
            if (str_contains($msg, 'Access denied') || str_contains($msg, '1044') || str_contains($msg, '1045')
                || str_contains($msg, '1227') || stripos($msg, 'privilege') !== false) {
                return true;
            }
            if ($t instanceof PDOException) {
                $driver = $t->errorInfo[1] ?? null;
                if (in_array($driver, [1044, 1045, 1227], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function formatConnectError(Throwable $e, ConnectionConfig $config): string
    {
        $where = $config->describe();

        return "Cannot connect to [{$where}]: " . self::shortError($e)
            . ' — check DB_HOST / DB_PORT / DB_SOCKET / DB_DATABASE / DB_USERNAME / DB_PASSWORD. '
            . '无法连接数据库，请检查 .env 中的数据库配置。';
    }

    private static function shortError(Throwable $e): string
    {
        $msg = trim($e->getMessage());
        if (strlen($msg) > 400) {
            $msg = substr($msg, 0, 400) . '…';
        }

        return $msg;
    }

    /**
     * @return array{ok: bool, created: bool, message: string, database: string}
     */
    private static function fail(string $database, string $message): array
    {
        return [
            'ok' => false,
            'created' => false,
            'message' => $message,
            'database' => $database,
        ];
    }
}

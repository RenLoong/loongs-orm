<?php

declare(strict_types=1);

namespace Loongs\Orm\Connection;

use InvalidArgumentException;

/**
 * Immutable description of "which database" a query runs against.
 *
 * - named:  a connection from config/database.php ("mysql", "central" …). Framework PDOPool when
 *           booted, else the ORM pool.
 * - ad-hoc: a raw config array or DSN (e.g. one database per customer). ORM pool (or a registered
 *           LeaseProvider), bucket per fingerprint().
 *
 * $key identifies the target: "name:<name>" or "adhoc:<sha1 of the normalised config incl. credentials>".
 * Two configs share a key only if every connection parameter is identical.
 */
final readonly class ConnectionConfig
{
    /** @param array<string, mixed> $config */
    private function __construct(
        public ?string $name,
        public array $config,
        public string $key,
    ) {
    }

    /** @param array<string, mixed> $config */
    public static function named(string $name, array $config): self
    {
        if ($name === '') {
            throw new InvalidArgumentException('Connection name must not be empty.');
        }

        return new self($name, self::normalise($config), 'name:' . $name);
    }

    /** @param array<string, mixed> $config */
    public static function adhoc(array $config): self
    {
        if (isset($config['url']) && is_string($config['url'])) {
            $config = array_replace(self::parseUrl($config['url']), array_diff_key($config, ['url' => 1]));
        }
        $norm = self::normalise($config);
        $fp = $norm;
        ksort($fp);
        if (isset($fp['options']) && is_array($fp['options'])) {
            ksort($fp['options']);
        }

        return new self(null, $norm, 'adhoc:' . sha1(json_encode($fp, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
    }

    /**
     * "mysql://user:pass@host:3306/db?charset=utf8mb4&unix_socket=/tmp/mysql.sock"
     * or a PDO DSN "mysql:host=…;dbname=…" (credentials via $username / $password).
     */
    public static function fromDsn(string $dsn, ?string $username = null, ?string $password = null): self
    {
        if (str_contains($dsn, '://')) {
            $cfg = self::parseUrl($dsn);
            if ($username !== null) {
                $cfg['username'] = $username;
            }
            if ($password !== null) {
                $cfg['password'] = $password;
            }
            return self::adhoc($cfg);
        }
        if (!preg_match('/^([a-z0-9_]+):(.*)$/i', $dsn, $m)) {
            throw new InvalidArgumentException('Unrecognised DSN (expected driver://… or driver:key=value;…).');
        }
        $cfg = ['driver' => strtolower($m[1]), 'dsn' => $dsn];
        foreach (explode(';', $m[2]) as $pair) {
            if (!str_contains($pair, '=')) {
                continue;
            }
            [$k, $v] = array_map('trim', explode('=', $pair, 2));
            match (strtolower($k)) {
                'dbname' => $cfg['database'] = $v,
                'host' => $cfg['host'] = $v,
                'port' => $cfg['port'] = (int) $v,
                'unix_socket' => $cfg['unix_socket'] = $v,
                'charset' => $cfg['charset'] = $v,
                default => null,
            };
        }
        $cfg['username'] = $username ?? 'root';
        $cfg['password'] = $password ?? '';

        return self::adhoc($cfg);
    }

    /** Pool bucket identity: the full normalised config incl. credentials (named configs too). */
    public function fingerprint(): string
    {
        if ($this->name === null) {
            return $this->key;
        }
        $fp = $this->config;
        ksort($fp);

        return $this->key . ':' . sha1(json_encode($fp, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public function isNamed(): bool
    {
        return $this->name !== null;
    }

    public function driver(): string
    {
        return (string) $this->config['driver'];
    }

    public function database(): string
    {
        return (string) ($this->config['database'] ?? '');
    }

    /** Table prefix ("" = none): prepended to every table name the query builder / models compile. */
    public function prefix(): string
    {
        return (string) ($this->config['prefix'] ?? '');
    }

    /** Prefixed table name (unquoted), e.g. table('users') → "app_users". */
    public function table(string $table): string
    {
        return $this->prefix() . $table;
    }

    /** Storage engine from the config ("engine" key), or null when not configured (DDL helpers pick a default). */
    public function engine(): ?string
    {
        return isset($this->config['engine']) ? (string) $this->config['engine'] : null;
    }

    public function charset(): string
    {
        return (string) ($this->config['charset'] ?? 'utf8mb4');
    }

    /** Collation from the config, or null when not configured. */
    public function collation(): ?string
    {
        return isset($this->config['collation']) ? (string) $this->config['collation'] : null;
    }

    /**
     * Table options for DDL: prefix, engine, charset, collation (null = not configured).
     *
     * @return array{prefix: string, engine: ?string, charset: string, collation: ?string}
     */
    public function tableOptions(): array
    {
        return ['prefix' => $this->prefix(), 'engine' => $this->engine(), 'charset' => $this->charset(), 'collation' => $this->collation()];
    }

    /** Safe description (never includes the password). */
    public function describe(): string
    {
        $c = $this->config;
        $where = ($c['unix_socket'] ?? '') !== '' ? 'unix:' . $c['unix_socket'] : ($c['host'] ?? '?') . ':' . ($c['port'] ?? '');
        $target = sprintf('%s://%s@%s/%s', $c['driver'], $c['username'] ?? '', $where, $c['database'] ?? '');

        return $this->name !== null ? $this->name . ' (' . $target . ')' : $target;
    }

    /** @return array<string, mixed> */
    private static function parseUrl(string $url): array
    {
        $p = parse_url($url);
        if ($p === false || !isset($p['scheme'])) {
            throw new InvalidArgumentException('Invalid connection URL.');
        }
        $cfg = [
            'driver' => strtolower($p['scheme']),
            'host' => isset($p['host']) ? rawurldecode($p['host']) : '127.0.0.1',
            'port' => isset($p['port']) ? (int) $p['port'] : 3306,
            'database' => isset($p['path']) ? rawurldecode(ltrim($p['path'], '/')) : '',
            'username' => isset($p['user']) ? rawurldecode($p['user']) : 'root',
            'password' => isset($p['pass']) ? rawurldecode($p['pass']) : '',
        ];
        if (isset($p['query'])) {
            parse_str($p['query'], $q);
            foreach (['charset', 'unix_socket', 'collation', 'prefix', 'engine'] as $k) {
                if (isset($q[$k]) && is_string($q[$k])) {
                    $cfg[$k] = $q[$k];
                }
            }
        }

        return $cfg;
    }

    private static function identifier(string $key, string $v): string
    {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $v) !== 1) {
            throw new InvalidArgumentException("Connection \"{$key}\" may only contain [A-Za-z0-9_].");
        }

        return $v;
    }

    /**
     * @param array<string, mixed> $c
     * @return array<string, mixed>
     */
    private static function normalise(array $c): array
    {
        $driver = strtolower((string) ($c['driver'] ?? 'mysql'));
        $out = [
            'driver' => $driver === 'mariadb' ? 'mysql' : $driver,
            'host' => (string) ($c['host'] ?? '127.0.0.1'),
            'port' => (int) ($c['port'] ?? 3306),
            'database' => (string) ($c['database'] ?? ''),
            'username' => (string) ($c['username'] ?? 'root'),
            'password' => (string) ($c['password'] ?? ''),
            'charset' => (string) ($c['charset'] ?? 'utf8mb4'),
            'unix_socket' => trim((string) ($c['unix_socket'] ?? '')),
            'options' => is_array($c['options'] ?? null) ? $c['options'] : [],
        ];
        if (isset($c['dsn']) && is_string($c['dsn']) && $c['dsn'] !== '') {
            $out['dsn'] = $c['dsn'];
        }
        if (isset($c['collation']) && is_string($c['collation']) && $c['collation'] !== '') {
            $out['collation'] = self::identifier('collation', $c['collation']);
        }
        $out['charset'] = self::identifier('charset', $out['charset']);
        // table prefix / engine: kept (normalised connection configs used to drop them)
        $prefix = (string) ($c['prefix'] ?? '');
        if (preg_match('/^[A-Za-z0-9_]{0,64}$/', $prefix) !== 1) {
            throw new InvalidArgumentException('Connection "prefix" may only contain [A-Za-z0-9_] (max 64).');
        }
        $out['prefix'] = $prefix;
        if (isset($c['engine']) && is_string($c['engine']) && $c['engine'] !== '') {
            $out['engine'] = self::identifier('engine', $c['engine']);
        }

        return $out;
    }
}

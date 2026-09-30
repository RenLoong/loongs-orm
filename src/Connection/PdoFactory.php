<?php

declare(strict_types=1);

namespace Loongs\Orm\Connection;

use InvalidArgumentException;
use PDO;

final class PdoFactory
{
    public static function dsn(ConnectionConfig $c): string
    {
        $cfg = $c->config;
        if (isset($cfg['dsn'])) {
            return (string) $cfg['dsn'];
        }

        return match ($cfg['driver']) {
            'mysql' => $cfg['unix_socket'] !== ''
                ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $cfg['unix_socket'], $cfg['database'], $cfg['charset'])
                : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $cfg['host'], $cfg['port'], $cfg['database'], $cfg['charset']),
            'sqlite' => 'sqlite:' . ($cfg['database'] !== '' ? $cfg['database'] : ':memory:'),
            'pgsql' => sprintf('pgsql:host=%s;port=%d;dbname=%s', $cfg['host'], $cfg['port'], $cfg['database']),
            default => throw new InvalidArgumentException('Unsupported driver [' . $cfg['driver'] . '].'),
        };
    }

    public static function make(ConnectionConfig $c): PDO
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
        foreach ($c->config['options'] as $k => $v) {
            $options[$k] = $v;
        }

        // PHP 8.4: PDO::connect() returns the driver subclass (Pdo\Mysql …).
        return PDO::connect(self::dsn($c), (string) $c->config['username'], (string) $c->config['password'], $options);
    }
}

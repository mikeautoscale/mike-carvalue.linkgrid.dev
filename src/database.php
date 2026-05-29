<?php

declare(strict_types=1);

namespace CarValue;

use PDO;

/** PDO connection factory. */
final class Database
{
    public static function connect(array $cfg): PDO
    {
        $charset = 'utf8mb4';
        $name = $cfg['name'] ?? '';

        if (!empty($cfg['host'])) {
            $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$name};charset={$charset}";
        } else {
            $dsn = "mysql:unix_socket={$cfg['socket']};dbname={$name};charset={$charset}";
        }

        return new PDO($dsn, $cfg['user'] ?? 'root', $cfg['pass'] ?? '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
}

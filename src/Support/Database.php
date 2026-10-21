<?php
declare(strict_types=1);

namespace App\Support;

use PDO;

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $c = $GLOBALS['config']['db'];
            self::$pdo = new PDO($c['dsn'], $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            self::$pdo->exec("SET time_zone = '-06:00'");
        }
        return self::$pdo;
    }
}

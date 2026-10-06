<?php
declare(strict_types=1);

namespace Hub;

use PDO;

/**
 * PDO access for MySQL/MariaDB (production) and SQLite (tests, dev). SPEC s2.1:
 * SQL must run on both - no ON DUPLICATE KEY, INSERT OR REPLACE or RETURNING.
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::connect();
        }
        return self::$pdo;
    }

    /** 'mysql' or 'sqlite'. */
    public static function driver(): string
    {
        $driver = strtolower(trim(Config::get('DB_DRIVER')));
        if ($driver !== 'mysql' && $driver !== 'sqlite') {
            throw new \RuntimeException('DB_DRIVER must be mysql or sqlite');
        }
        return $driver;
    }

    /** All rows as associative arrays. */
    public static function all(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** First row or null. */
    public static function one(string $sql, array $params = []): ?array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** First column of the first row, or null. */
    public static function value(string $sql, array $params = []): mixed
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value === false ? null : $value;
    }

    /** Runs a statement; returns affected rows. */
    public static function exec(string $sql, array $params = []): int
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /** Runs an INSERT; returns lastInsertId() as int. */
    public static function insert(string $sql, array $params = []): int
    {
        self::exec($sql, $params);
        return (int) self::pdo()->lastInsertId();
    }

    /** Runs $fn inside one transaction (joins an open one); rolls back on any throwable. */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Executes a multi-statement SQL script (schema, seeds). Statements end with ';' at end of line;
     * whole-line '--' comments are ignored. Returns the number of statements run.
     */
    public static function execScript(string $sql): int
    {
        $lines = preg_split('/\R/', $sql) ?: [];
        $kept = array_filter($lines, static fn (string $l): bool => preg_match('/^\s*--/', $l) !== 1);
        $count = 0;
        foreach (preg_split('/;\s*(?:\n|$)/', implode("\n", $kept)) ?: [] as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }
            self::pdo()->exec($statement);
            $count++;
        }
        return $count;
    }

    /** Drops the cached connection (white-box tests that switch DB_PATH with putenv). */
    public static function reset(): void
    {
        self::$pdo = null;
    }

    private static function connect(): PDO
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
        if (self::driver() === 'sqlite') {
            $path = Config::path('DB_PATH');
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $pdo = new PDO('sqlite:' . $path, null, null, $options + [PDO::ATTR_TIMEOUT => 10]);
            $pdo->exec('PRAGMA foreign_keys = ON');
            return $pdo;
        }
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            Config::get('DB_HOST'),
            Config::int('DB_PORT'),
            Config::get('DB_NAME')
        );
        $pdo = new PDO($dsn, Config::get('DB_USER'), Config::get('DB_PASS'), $options + [PDO::ATTR_EMULATE_PREPARES => false]);
        $pdo->exec("SET time_zone = '+02:00'");
        return $pdo;
    }
}

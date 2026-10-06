<?php
/**
 * Database.php — thin, centralised PDO wrapper.
 *
 * One shared PDO connection per request. Every query in the application goes
 * through these helpers with bound parameters, so SQL injection needs an
 * explicit mistake (string-concatenated SQL) to happen.
 */

declare(strict_types=1);

final class Database
{
    private static ?PDO $pdo = null;

    /** Connect without a database selected (used by install.php). */
    public static function serverConn(): PDO
    {
        return self::connect(false);
    }

    public static function conn(): PDO
    {
        return self::connect(true);
    }

    private static function connect(bool $withDatabase): PDO
    {
        if ($withDatabase && self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        $dsn = $withDatabase
            ? sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME)
            : sprintf('mysql:host=%s;port=%s;charset=utf8mb4', DB_HOST, DB_PORT);

        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        if ($withDatabase) {
            self::$pdo = $pdo;
        }
        return $pdo;
    }

    /** @param array<int|string,mixed> $params */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::conn()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** @param array<int|string,mixed> $params */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<int|string,mixed> $params @return array<int,array<string,mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** @param array<int|string,mixed> $params */
    public static function scalar(string $sql, array $params = []): mixed
    {
        $value = self::run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /** @param array<int|string,mixed> $params */
    public static function value(string $sql, array $params = [], mixed $default = null): mixed
    {
        $value = self::scalar($sql, $params);
        return $value === null ? $default : $value;
    }

    /** @param array<string,mixed> $row */
    public static function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        $sql  = 'INSERT INTO `' . $table . '` (' . implode(', ', $cols) . ') VALUES ('
              . implode(', ', array_map(static fn ($c) => ':' . $c, $cols)) . ')';
        self::run($sql, $row);
        return (int) self::conn()->lastInsertId();
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $params */
    public static function update(string $table, array $row, string $where, array $params = []): int
    {
        if ($row === []) {
            return 0;
        }
        $sets = [];
        $bind = [];
        foreach ($row as $col => $value) {
            $sets[] = '`' . $col . '` = :set_' . $col;
            $bind['set_' . $col] = $value;
        }
        foreach ($params as $key => $value) {
            $bind[$key] = $value;
        }
        return self::run('UPDATE `' . $table . '` SET ' . implode(', ', $sets) . ' WHERE ' . $where, $bind)->rowCount();
    }

    /** @param array<int|string,mixed> $params */
    public static function delete(string $table, string $where, array $params = []): int
    {
        return self::run('DELETE FROM `' . $table . '` WHERE ' . $where, $params)->rowCount();
    }

    /** @param array<int|string,mixed> $params */
    public static function count(string $table, string $where = '1', array $params = []): int
    {
        return (int) self::scalar('SELECT COUNT(*) FROM `' . $table . '` WHERE ' . $where, $params);
    }

    /**
     * Inserts a row or updates the row that already holds the same unique key.
     * Used by install.php / the seeder so both can run twice without error.
     *
     * @param array<string,mixed> $row
     * @param array<int,string>   $uniqueColumns
     */
    public static function upsert(string $table, array $row, array $uniqueColumns): int
    {
        $where  = [];
        $params = [];
        foreach ($uniqueColumns as $col) {
            $where[] = '`' . $col . '` = :u_' . $col;
            $params['u_' . $col] = $row[$col] ?? null;
        }
        $existing = self::one('SELECT id FROM `' . $table . '` WHERE ' . implode(' AND ', $where), $params);
        if ($existing !== null) {
            self::update($table, $row, 'id = :id', ['id' => (int) $existing['id']]);
            return (int) $existing['id'];
        }
        return self::insert($table, $row);
    }

    /** Runs $callback inside a transaction, rolling back on any exception. */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::conn();
        $pdo->beginTransaction();
        try {
            $result = $callback();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function tableExists(string $table): bool
    {
        try {
            return (bool) self::scalar(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = :db AND table_name = :t',
                ['db' => DB_NAME, 't' => $table]
            );
        } catch (Throwable $e) {
            return false;
        }
    }

    /** True when the driver error means "duplicate key". */
    public static function isDuplicate(Throwable $e): bool
    {
        if (!$e instanceof PDOException) {
            return false;
        }
        $sqlState = (string) $e->getCode();
        $driver   = (string) ($e->errorInfo[1] ?? '');
        return $sqlState === '23000' || $driver === '1062';
    }

    /** Turns '' into NULL for nullable date/datetime columns. */
    public static function nullableDateTime(?string $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}

<?php
/**
 * Schema.php — database installation, light forward migrations and demo seeding.
 *
 * The SQL lives in database/{schema,seed}.sql so the same files can be imported
 * by hand through phpMyAdmin. install.php drives the methods below.
 */

declare(strict_types=1);

final class Schema
{
    /* ---------------------------------------------------------------------
     * Status
     * ------------------------------------------------------------------ */

    public static function databaseExists(): bool
    {
        try {
            $stmt = Database::serverConn()->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = :n');
            $stmt->execute(['n' => DB_NAME]);
            return (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function isInstalled(): bool
    {
        try {
            return Database::tableExists('users') && Database::count('users') > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function hasTables(): bool
    {
        try {
            return Database::tableExists('users');
        } catch (Throwable $e) {
            return false;
        }
    }

    /* ---------------------------------------------------------------------
     * Install steps
     * ------------------------------------------------------------------ */

    /** Creates the database itself when the MySQL user is allowed to. */
    public static function createDatabase(): void
    {
        Database::serverConn()->exec(
            'CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', DB_NAME) . '` '
            . 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
    }

    /** @return array{statements:int,tables:array<int,string>} */
    public static function migrate(): array
    {
        $sql = self::readSqlFile('schema.sql');
        $pdo = Database::conn();
        $statements = self::splitStatements($sql);
        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
        return ['statements' => count($statements), 'tables' => self::tableList()];
    }

    /** @return array{statements:int,users:int,organizations:int,events:int} */
    public static function seed(): array
    {
        $sql = self::readSqlFile('seed.sql');
        $pdo = Database::conn();
        $statements = self::splitStatements($sql);
        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
        return [
            'statements'    => count($statements),
            'users'         => (int) Database::scalar('SELECT COUNT(*) FROM users'),
            'organizations' => (int) Database::scalar('SELECT COUNT(*) FROM organizations'),
            'events'        => (int) Database::scalar('SELECT COUNT(*) FROM events'),
        ];
    }

    /** Drops every application table (foreign keys disabled) then rebuilds them. */
    public static function dropAll(): void
    {
        $pdo = Database::conn();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::tableList() as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', $table) . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** Full wipe + fresh schema + demo data. */
    public static function reinstall(bool $withDemoData = true): array
    {
        self::dropAll();
        $result = self::migrate();
        if ($withDemoData) {
            $result['seed'] = self::seed();
        }
        $result['tables'] = self::tableList();
        return $result;
    }

    /** @return array<int,string> */
    public static function tableList(): array
    {
        $rows = Database::all(
            'SELECT table_name AS t FROM information_schema.tables WHERE table_schema = :db ORDER BY table_name',
            ['db' => DB_NAME]
        );
        return array_map(static fn ($r) => (string) $r['t'], $rows);
    }

    /** Row counts for the installer screen and the admin settings page. */
    public static function counts(): array
    {
        $counts = [];
        foreach (['users', 'students', 'organizations', 'organization_members', 'events', 'attendance', 'documents', 'announcements'] as $table) {
            try {
                $counts[$table] = (int) Database::count($table);
            } catch (Throwable $e) {
                $counts[$table] = 0;
            }
        }
        return $counts;
    }

    /* ---------------------------------------------------------------------
     * Forward migration for databases created by an earlier build
     * ------------------------------------------------------------------ */

    /**
     * Columns added after the first release. CREATE TABLE IF NOT EXISTS never
     * alters an existing table, so every new column is listed once here.
     *
     * @var array<string,array<string,string>> table => column => definition
     */
    private const ADDED_COLUMNS = [
        'organizations' => ['notes' => 'TEXT NULL'],
        'events'        => ['banner' => "VARCHAR(160) NOT NULL DEFAULT ''"],
        'students'      => ['qr_nonce' => "VARCHAR(32) NOT NULL DEFAULT ''"],
    ];

    public static function ensureColumns(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        foreach (self::ADDED_COLUMNS as $table => $columns) {
            try {
                if (!Database::tableExists($table)) {
                    continue;
                }
                $rows = Database::all(
                    'SELECT column_name AS c FROM information_schema.columns WHERE table_schema = :db AND table_name = :t',
                    ['db' => DB_NAME, 't' => $table]
                );
                $have = array_map(static fn ($r) => (string) $r['c'], $rows);
                foreach ($columns as $column => $definition) {
                    if (!in_array($column, $have, true)) {
                        Database::conn()->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
                    }
                }
            } catch (Throwable $e) {
                // Never break a page because of a migration attempt.
            }
        }
    }

    /* ---------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    public static function sqlPath(string $file): string
    {
        return APP_ROOT . '/database/' . $file;
    }

    private static function readSqlFile(string $file): string
    {
        $path = self::sqlPath($file);
        $sql  = @file_get_contents($path);
        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException('SQL file missing or empty: database/' . $file);
        }
        return $sql;
    }

    /**
     * Splits a .sql file into single statements. This project's SQL keeps every
     * terminator at the end of a line and contains no stored routines, so a
     * line-based split is safe and needs no external parser.
     *
     * @return array<int,string>
     */
    public static function splitStatements(string $sql): array
    {
        $lines = preg_split('/\R/', $sql) ?: [];
        $kept  = [];
        foreach ($lines as $line) {
            $trim = ltrim($line);
            if ($trim === '' || str_starts_with($trim, '--')) {
                continue;
            }
            $kept[] = $line;
        }
        $parts = explode(';', implode("\n", $kept));
        return array_values(array_filter(array_map('trim', $parts), static fn ($s) => $s !== ''));
    }
}

<?php
declare(strict_types=1);

$targets = [
    ['127.0.0.1', 3306, 'root', ''],
    ['127.0.0.1', 3307, 'root', ''],
];

foreach ($targets as [$host, $port, $user, $pass]) {
    echo "== {$host}:{$port} user={$user} ==\n";
    try {
        $pdo = new PDO("mysql:host={$host};port={$port}", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 3,
        ]);
        $dbs = $pdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
        echo "databases: " . implode(', ', $dbs) . "\n";
        foreach ($dbs as $db) {
            if (in_array($db, ['information_schema', 'mysql', 'performance_schema', 'sys'], true)) {
                continue;
            }
            $tables = $pdo->query("SHOW TABLES FROM `$db`")->fetchAll(PDO::FETCH_COLUMN);
            $total = 0;
            $lines = [];
            foreach ($tables as $t) {
                $n = (int) $pdo->query("SELECT COUNT(*) FROM `$db`.`$t`")->fetchColumn();
                $total += $n;
                if ($n > 0) { $lines[] = "$t=$n"; }
            }
            echo "  [$db] tables=" . count($tables) . " total_rows=$total"
                . ($lines !== [] ? " | " . implode(', ', $lines) : ' | (all empty)') . "\n";
        }
    } catch (Throwable $e) {
        echo "FAIL: " . $e->getMessage() . "\n";
    }
}

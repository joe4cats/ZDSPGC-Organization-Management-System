<?php
/**
 * tools/backup.php — dumps the application database to database/backups/*.sql.
 *
 *   php tools/backup.php              create a dump, keep the newest 14
 *   php tools/backup.php --keep=30    keep the newest 30 dumps instead
 *
 * Restore with phpMyAdmin import, or:
 *   mysql -h 127.0.0.1 -P 3307 -u root zdspgc_orgsys < database/backups/<file>.sql
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run this script from the command line.\n");
}

define('ZDSPGC_SKIP_INSTALL_CHECK', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$keep = 14;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--keep=(\d+)$/', (string) $arg, $m) === 1) {
        $keep = max(1, (int) $m[1]);
    }
}

$dir = dirname(__DIR__) . '/database/backups';
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    exit("Cannot create backup directory: {$dir}\n");
}

$mysqldump = 'C:\xampp\mysql\bin\mysqldump.exe';
if (!is_file($mysqldump)) {
    $mysqldump = 'mysqldump'; // fall back to PATH
}

$file = $dir . '\\' . DB_NAME . '-' . date('Ymd-His') . '.sql';
$cmd = '"' . $mysqldump . '" --host=' . DB_HOST . ' --port=' . DB_PORT
     . ' --user=' . DB_USER . ' --single-transaction --routines --triggers'
     . ' --result-file="' . $file . '" "' . DB_NAME . '"';

$env = [];
foreach ($_SERVER as $k => $v) {
    if (is_string($v) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $k) === 1) {
        $env[$k] = $v;
    }
}
$env['MYSQL_PWD'] = DB_PASS;

$pipes = [];
$proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $env);
if (!is_resource($proc)) {
    exit("Failed to start mysqldump.\n");
}
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$code = proc_close($proc);

if ($code !== 0) {
    @unlink($file);
    exit("mysqldump failed (exit {$code}): {$stderr}\n");
}

$size = is_file($file) ? (int) filesize($file) : 0;
if ($size < 1000) {
    @unlink($file);
    exit("Backup file suspiciously small — aborted.\n");
}

// Prune old dumps, keep the newest $keep.
$files = glob($dir . '\\' . DB_NAME . '-*.sql') ?: [];
rsort($files, SORT_STRING);
foreach (array_slice($files, $keep) as $old) {
    @unlink($old);
}

echo 'Backup written: ' . $file . ' (' . number_format($size) . " bytes)\n";
echo 'Dumps kept: ' . $keep . ' (' . count($files) . ' before pruning)' . PHP_EOL;

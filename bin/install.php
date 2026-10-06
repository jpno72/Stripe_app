<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/database.php';
require dirname(__DIR__) . '/src/Migrations.php';
try {
    $args = array_slice($argv, 1);
    if ($args === [] || $args === ['--help']) {
        echo "Usage: php bin/install.php --check|--apply [--config=/absolute/private/config.php]\n"; exit(0);
    }
    $mode = array_shift($args);
    if (!in_array($mode, ['--check','--apply'], true) || count($args) > 1 || (isset($args[0]) && !str_starts_with($args[0], '--config='))) {
        throw new RuntimeException('Invalid arguments.');
    }
    $db = bmaDatabase(bmaConfig(isset($args[0]) ? substr($args[0], 9) : null));
    $pending = Migrations::run($db, dirname(__DIR__) . '/sql/migrations', $mode === '--apply');
    echo ($mode === '--check' ? 'Pending migrations: ' : 'Applied migrations: ') . count($pending) . "\n";
    foreach ($pending as $migration) { echo $migration . "\n"; }
} catch (Throwable $e) {
    fwrite(STDERR, $e instanceof PDOException ? "Database migration failed. Inspect privileges and schema state; no driver values logged.\n" : $e->getMessage() . "\n");
    fwrite(STDERR, "DDL commits automatically. Partial migrations require manual review; never force-retry or drop production tables.\n");
    exit(1);
}

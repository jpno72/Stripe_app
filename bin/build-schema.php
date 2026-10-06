<?php
declare(strict_types=1);
// Development utility: generate the consolidated schema from a fresh migrated disposable database.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/database.php';
require dirname(__DIR__) . '/src/Migrations.php';
$config = bmaConfig();
if (!str_starts_with($config['DB_DATABASE'] ?? '', 'bma_schema_test_')) { throw new RuntimeException('Disposable schema database required.'); }
$db = bmaDatabase($config); $root = dirname(__DIR__);
$files = glob($root . '/sql/migrations/*.sql'); sort($files); $names = [];
foreach ($files as $file) {
    preg_match_all('/CREATE TABLE ([a-z_]+)/', file_get_contents($file), $matches);
    $names = [...$names, ...$matches[1]];
}
$sql = "-- Consolidated current schema, generated after applying all versioned migrations.\n-- Install with bin/install.php. Never reapply to an existing database.\nSET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;\nSET time_zone = '+00:00';\nSET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';\n\n";
foreach ($names as $name) {
    if (!in_array($name, ['schema_migrations','stripe_source_identity','dashboards','role_dashboard_permissions'], true) && (int)$db->query('SELECT COUNT(*) FROM `' . $name . '`')->fetchColumn() !== 0) { throw new RuntimeException('Schema generation requires zero business rows.'); }
    $ddl = $db->query('SHOW CREATE TABLE `' . $name . '`')->fetch(PDO::FETCH_NUM)[1];
    $ddl = preg_replace('/ AUTO_INCREMENT=\d+/', '', $ddl);
    $sql .= $ddl . ";\n\n";
}
foreach ($db->query('SHOW TRIGGERS')->fetchAll() as $trigger) {
    $definition = $db->query('SHOW CREATE TRIGGER `' . $trigger['Trigger'] . '`')->fetch()['SQL Original Statement'];
    $definition = preg_replace('/CREATE DEFINER=`[^`]+`@`[^`]+` TRIGGER/', 'CREATE TRIGGER', $definition);
    $sql .= "DELIMITER $$\n" . $definition . "$$\nDELIMITER ;\n\n";
}
$sql .= "INSERT INTO stripe_source_identity (id) VALUES (1);\n";
$sql .= "\n" . file_get_contents($root . '/sql/views.sql') . "\n";
foreach (Migrations::statements(file_get_contents($root . '/sql/migrations/007_dashboard_access.sql')) as $statement) {
    if (preg_match('/^INSERT INTO (dashboards|role_dashboard_permissions)\b/', $statement)) { $sql .= $statement . ";\n\n"; }
}
foreach ($files as $file) {
    $sql .= "INSERT INTO schema_migrations (version, checksum) VALUES ('" . pathinfo($file, PATHINFO_FILENAME) . "', '" . hash_file('sha256', $file) . "');\n";
}
file_put_contents($root . '/sql/schema.sql', $sql);
echo "Consolidated schema generated without account-specific definers or source data.\n";

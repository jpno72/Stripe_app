<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/database.php';
require dirname(__DIR__) . '/src/Migrations.php';
$config = bmaConfig();
if (!str_starts_with($config['DB_DATABASE'] ?? '', 'bma_schema_test_')) { exit(1); }
$db = bmaDatabase($config); $root = dirname(__DIR__); $suffix = bin2hex(random_bytes(6)); $checks = 0;
function assertMigration(bool $pass, string $label): void { global $checks; if (!$pass) { throw new RuntimeException($label); } $checks++; }
function newDatabase(PDO $db, string $name): void {
    if (!preg_match('/^bma_schema_test_[a-z0-9_]+$/D',$name)) { throw new RuntimeException('Unsafe test name'); }
    $db->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $db->exec('USE `' . $name . '`');
}
function definitions(PDO $db): array {
    $out = []; $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN); sort($tables);
    foreach ($tables as $t) { $out[$t] = preg_replace('/ AUTO_INCREMENT=\d+/','',$db->query('SHOW CREATE TABLE `' . $t . '`')->fetch(PDO::FETCH_NUM)[1]); }
    foreach ($db->query('SHOW TRIGGERS')->fetchAll() as $t) { $out['trigger:' . $t['Trigger']] = $db->query('SHOW CREATE TRIGGER `' . $t['Trigger'] . '`')->fetch()['SQL Original Statement']; }
    ksort($out); return $out;
}
function applyInitial(PDO $db, string $root): void {
    $file = $root . '/sql/migrations/001_initial_schema.sql';
    foreach (Migrations::statements(file_get_contents($file)) as $statement) { $db->exec($statement); }
    $q = $db->prepare('INSERT INTO schema_migrations (version,checksum) VALUES (?,?)'); $q->execute(['001_initial_schema',hash_file('sha256',$file)]);
}
try {
    assertMigration(hash_file('sha256',$root . '/sql/migrations/001_initial_schema.sql') === '75d740ab762c93a022de436c31199b6601ae0e505dbab424bfb40b698008fb50','001 unchanged');
    newDatabase($db,'bma_schema_test_chain_' . $suffix);
    assertMigration(count(Migrations::run($db,$root . '/sql/migrations',false)) === 5,'Empty preflight');
    assertMigration(count($db->query('SHOW TABLES')->fetchAll()) === 0,'Preflight creates nothing');
    assertMigration(count(Migrations::run($db,$root . '/sql/migrations',true)) === 5,'All five migrations applied');
    assertMigration(Migrations::run($db,$root . '/sql/migrations',true) === [],'Idempotent migration runner');
    $chain = definitions($db);
    newDatabase($db,'bma_schema_test_snapshot_' . $suffix);
    foreach (Migrations::statements(file_get_contents($root . '/sql/schema.sql')) as $statement) { $db->exec($statement); }
    assertMigration($chain === definitions($db),'Consolidated schema equals full migration chain including triggers');
    assertMigration(Migrations::run($db,$root . '/sql/migrations',true) === [],'Snapshot has valid migration ledger');

    newDatabase($db,'bma_schema_test_upgrade_' . $suffix); applyInitial($db,$root);
    $db->exec("INSERT INTO people (bma_person_id,first_name) VALUES ('BMA-LEGACY','Legacy')");
    $db->exec("INSERT INTO stripe_customers (stripe_customer_id) VALUES ('cus_UPGRADE')");
    $customer = $db->lastInsertId();
    $db->exec("INSERT INTO stripe_prices (stripe_price_id) VALUES ('price_UPGRADE')"); $price = $db->lastInsertId();
    $q = $db->prepare('INSERT INTO stripe_subscriptions (stripe_subscription_id,customer_id,price_id,product_name_snapshot,recurring_interval,amount_minor,currency,status) VALUES (?,?,?,?,?,?,?,?)');
    $q->execute(['sub_UPGRADE',$customer,$price,'Approved program','month',12500,'usd','active']);
    assertMigration(Migrations::run($db,$root . '/sql/migrations',true) === ['002_step1_contract','003_minimize_stripe_fields','004_identity_match_candidates','005_reporting_views'],'Upgrade applies 002 through 005');
    $item = $db->query('SELECT * FROM stripe_subscription_items')->fetch();
    assertMigration($item['source_locator'] === 'csv-single-item' && (int)$item['amount_minor'] === 12500 && $item['billing_interval'] === 'month' && (string)$item['price_id'] === $price,'Legacy item data preserved');
    assertMigration($db->query('SELECT bma_person_id FROM people')->fetchColumn() === 'BMA-LEGACY','Existing immutable identity preserved');
    assertMigration((int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME IN ('card_or_payment_method_id','customer_description_snapshot') AND TABLE_NAME IN ('stripe_customers','stripe_subscriptions','stripe_transactions')")->fetchColumn() === 0,'Removed sensitive columns absent after upgrade');

    newDatabase($db,'bma_schema_test_minimize_guard_' . $suffix);
    applyInitial($db,$root);
    // Apply only 002, then prove 003 cannot silently discard already-staged Stripe data.
    foreach (Migrations::statements(file_get_contents($root . '/sql/migrations/002_step1_contract.sql')) as $statement) { $db->exec($statement); }
    $q = $db->prepare('INSERT INTO schema_migrations (version,checksum) VALUES (?,?)');
    $q->execute(['002_step1_contract',hash_file('sha256',$root . '/sql/migrations/002_step1_contract.sql')]);
    $db->exec("INSERT INTO import_batches (source_type,filename,file_hash,source_headers) VALUES ('stripe_customers','synthetic.csv',REPEAT('a',64),'[\"id\",\"Card ID\"]')");
    $db->exec("INSERT INTO raw_stripe_customers (import_batch_id,source_row_number,raw_values,row_hash) VALUES (1,1,'{\"id\":\"cus_LEGACY\",\"Card ID\":\"card_legacy\"}',REPEAT('b',64))");
    try { Migrations::run($db,$root . '/sql/migrations',true); throw new RuntimeException('003 raw-data guard did not reject'); }
    catch (RuntimeException $e) { assertMigration(str_contains($e->getMessage(),'Historical Stripe staging'),'003 refuses legacy raw values'); }
    assertMigration(str_contains($db->query('SELECT raw_values FROM raw_stripe_customers')->fetchColumn(),'card_legacy'),'003 guard does not erase existing data');

    newDatabase($db,'bma_schema_test_guard_' . $suffix); applyInitial($db,$root);
    $db->exec("INSERT INTO import_batches (source_type,filename,file_hash,source_headers) VALUES ('cognito_waivers','synthetic.csv',REPEAT('0',64),'[]')");
    $db->exec("INSERT INTO raw_cognito_waivers (import_batch_id,source_row_number,raw_values,raw_record,row_hash) VALUES (1,1,'{}','synthetic legacy bytes',REPEAT('1',64))");
    try { Migrations::run($db,$root . '/sql/migrations',true); throw new RuntimeException('Guard did not reject'); }
    catch (RuntimeException $e) { assertMigration(str_contains($e->getMessage(),'Legacy source/waiver/error'),'Legacy data requires reviewed migration'); }
    assertMigration($db->query('SELECT raw_record FROM raw_cognito_waivers')->fetchColumn() === 'synthetic legacy bytes','Guard never deletes legacy data');
    $q = $db->prepare('INSERT INTO schema_migrations (version,checksum) VALUES (?,?)'); $q->execute(['in_progress:002_step1_contract',str_repeat('0',64)]);
    try { Migrations::run($db,$root . '/sql/migrations',true); throw new RuntimeException('Marker did not reject'); }
    catch (RuntimeException $e) { assertMigration(str_contains($e->getMessage(),'interrupted migration'),'Interrupted migration blocks retry'); }
    echo "PASS: $checks migration assertions. Schema, snapshot, upgrade, and guarded recovery verified.\n";
} catch (Throwable $e) { fwrite(STDERR,'FAIL: ' . $e->getMessage() . "\n"); exit(1); }

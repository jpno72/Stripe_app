<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
require_once dirname(__DIR__) . '/app/Import/bootstrap.php';

function check(bool $condition, string $label): void
{
    if (!$condition) { fwrite(STDERR, "FAIL $label\n"); exit(1); }
    echo "PASS $label\n";
}

function csv(array $headers, array $rows)
{
    $stream = fopen('php://temp', 'w+b');
    fputcsv($stream, $headers, ',', '"', '');
    foreach ($rows as $row) { fputcsv($stream, $row, ',', '"', ''); }
    rewind($stream);
    return $stream;
}

$config = bmaConfig(); $db = bmaDatabase($config);
check(($config['APP_ENV'] ?? '') === 'test' && str_starts_with((string)($config['DB_DATABASE'] ?? ''), 'bma_schema_test_'), 'disposable test environment required');
$service = new ImportService($db, $config);
$uploads = new TemporaryUploads((string)$config['IMPORT_TEMP_DIR'], (string)$config['PUBLIC_WEB_ROOT']);
$tag = bin2hex(random_bytes(8));
$headers = ['id','Email','Name','Card ID','Description','mystery'];
$source = csv($headers, [
    ['cus_' . $tag,' PERSON@EXAMPLE.COM ',"First,\nName",'pm_SHOULD_NOT_SURVIVE','ignored free text','unknown private text'],
    ['cus_' . $tag,' PERSON@EXAMPLE.COM ','Second Name','pm_OTHER','more ignored text','another unknown value'],
]);
$result = $uploads->processStream($source, fn($managed) => $service->run($managed, 'stripe_customers', 'test.csv'));
rewind($source);
$duplicate = $uploads->processStream($source, fn($managed) => $service->run($managed, 'stripe_customers', 'test.csv'));
fclose($source);
check($result['inserted_count'] === 1 && $result['skipped_count'] === 1 && $result['error_count'] === 0, 'quoted CSV and duplicate row');
check($duplicate['duplicate_of'] === $result['batch_id'] && $duplicate['inserted_count'] === 0, 'file hash duplicate');
check($result['unknown_headers'] === ['mystery'], 'unknown heading metadata only');
$q = $db->prepare('SELECT raw_values FROM raw_stripe_customers WHERE import_batch_id = ? ORDER BY id LIMIT 1');
$q->execute([$result['batch_id']]); $raw = (string)$q->fetchColumn();
check(!str_contains($raw, 'SHOULD_NOT_SURVIVE') && !str_contains($raw, 'ignored free text') && !str_contains($raw, 'unknown private text'), 'ignored fields excluded from JSON');
$q = $db->prepare('SELECT email_normalized, name FROM stripe_customers WHERE stripe_customer_id = ?'); $q->execute(['cus_' . $tag]); $customer = $q->fetch();
check($customer['email_normalized'] === 'person@example.com' && str_contains($customer['name'], 'First,'), 'normalization separate from raw values');
$q = $db->prepare('SELECT COUNT(*) FROM import_errors WHERE import_batch_id = ? AND error_code = ?'); $q->execute([$result['batch_id'], 'DUPLICATE_SOURCE_RECORD']);
check((int)$q->fetchColumn() === 1, 'fixed duplicate diagnostic');

$transactions = csv(['id','Amount','Currency','Status','Card ID'], [
    ['ch_' . $tag . 'bad','not-a-number','USD','succeeded','pm_IGNORE_THIS'],
    ['ch_' . $tag . 'good','12.34','USD','succeeded','pm_IGNORE_THAT'],
]);
$transactionResult = $uploads->processStream($transactions, fn($managed) => $service->run($managed, 'stripe_transactions', 'transactions.csv'));
fclose($transactions);
check($transactionResult['error_count'] === 1 && $transactionResult['inserted_count'] === 1, 'row error does not abort file');
$q = $db->prepare('SELECT amount_minor, currency FROM stripe_transactions WHERE stripe_charge_id = ?'); $q->execute(['ch_' . $tag . 'good']);
$money = $q->fetch();
check((int)$money['amount_minor'] === 1234 && $money['currency'] === 'usd', 'financial normalization uses source currency');
$q = $db->prepare('SELECT raw_values FROM raw_stripe_transactions WHERE import_batch_id = ?'); $q->execute([$transactionResult['batch_id']]);
foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $json) { check(!str_contains($json, 'pm_IGNORE_'), 'ignored card excluded from transaction staging'); }

$waiverHeaders = ['Date Submitted','Name','Print Name','Phone','considerations'];
$first = csv($waiverHeaders, [['1/3/2021 16:03','Participant ' . $tag,'Signer ' . $tag,'5705551212','medical text to ignore']]);
$waiver1 = $uploads->processStream($first, fn($managed) => $service->run($managed, 'cognito_waivers', 'waiver.csv'));
fclose($first);
$changed = csv($waiverHeaders, [['1/3/2021 16:03','Participant ' . $tag,'Signer ' . $tag,'5705553434','different medical text']]);
$waiver2 = $uploads->processStream($changed, fn($managed) => $service->run($managed, 'cognito_waivers', 'waiver.csv'));
fclose($changed);
check($waiver1['inserted_count'] === 1 && $waiver2['skipped_count'] === 1, 'changed fallback locator held for review');
$q = $db->prepare('SELECT COUNT(*) FROM import_errors WHERE import_batch_id = ? AND error_code = ?'); $q->execute([$waiver2['batch_id'], 'IDENTITY_AMBIGUOUS']);
check((int)$q->fetchColumn() === 1, 'Cognito ambiguity warning');
check(glob(rtrim((string)$config['IMPORT_TEMP_DIR'], '/\\') . DIRECTORY_SEPARATOR . '*.upload') === [], 'temporary copies deleted');

check(Normalizers::amount('1,234.56', 'usd') === 123456 && Normalizers::amount('123', 'jpy') === 123, 'exact minor-unit parsing');
echo "Import integration checks passed.\n";

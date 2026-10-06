<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/Import/bootstrap.php';

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$args = getopt('', ['source:','file:','dry-run','stripe-account:','stripe-live:']);
$source = $args['source'] ?? '';
$file = $args['file'] ?? '';
if (!is_string($source) || !is_string($file) || $file === '') {
    fwrite(STDERR, "Usage: php bin/import.php --source=TYPE --file=FILE [--dry-run] [--stripe-account=acct_ID --stripe-live=0|1]\n"); exit(2);
}
try {
    $config = bmaConfig(); $db = bmaDatabase($config);
    $uploads = new TemporaryUploads((string)$config['IMPORT_TEMP_DIR'], (string)$config['PUBLIC_WEB_ROOT']);
    $uploads->cleanup((int)($config['IMPORT_ABANDONED_SECONDS'] ?? 86400));
    $input = fopen($file, 'rb');
    if ($input === false) { throw new RuntimeException('CSV_OPEN_FAILED'); }
    try {
        $result = $uploads->processStream($input, fn($managed) => (new ImportService($db, $config))->run($managed, $source, basename($file), [
            'dry_run'=>isset($args['dry-run']),
            'stripe_account_id'=>$args['stripe-account'] ?? '',
            'stripe_livemode'=>isset($args['stripe-live']) && in_array($args['stripe-live'], ['0','1'], true) ? (int)$args['stripe-live'] : null,
        ]));
    } finally { fclose($input); }
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
} catch (Throwable) {
    fwrite(STDERR, "Import failed. Check the batch diagnostics and private operational log.\n"); exit(1);
}

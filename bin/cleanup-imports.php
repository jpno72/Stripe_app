<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/database.php';
require dirname(__DIR__) . '/src/TemporaryUploads.php';
try {
    $config = bmaConfig();
    $age = $config['IMPORT_ABANDONED_SECONDS'] ?? '';
    if (!is_string($age) || !ctype_digit($age) || (int)$age < 1) { throw new RuntimeException('INVALID_CLEANUP_CONFIGURATION'); }
    $uploads = new TemporaryUploads($config['IMPORT_TEMP_DIR'] ?? '', $config['PUBLIC_WEB_ROOT'] ?? '');
    echo 'Deleted abandoned uploads: ' . $uploads->cleanup((int)$age) . "\n";
} catch (Throwable) { fwrite(STDERR, "Upload cleanup failed; no source data logged.\n"); exit(1); }

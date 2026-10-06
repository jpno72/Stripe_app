<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/database.php';
require dirname(__DIR__) . '/src/People.php';
$config = bmaConfig();
if (!str_starts_with($config['DB_DATABASE'] ?? '', 'bma_schema_test_')) { exit(1); }
try { echo json_encode((new People(bmaDatabase($config)))->create('Concurrent', 'Test'), JSON_THROW_ON_ERROR); }
catch (Throwable) { fwrite(STDERR, "Person allocation failed.\n"); exit(1); }

<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/app/Matching/bootstrap.php';

try {
    $options = getopt('', ['dry-run']);
    $config = bmaConfig(); $db = bmaDatabase($config);
    $result = (new IdentityMatcher($db, $config))->run(isset($options['dry-run']));
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, "Identity matching failed. Source values and exception details omitted.\n"); exit(1);
}

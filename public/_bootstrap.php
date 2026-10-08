<?php
declare(strict_types=1);
ini_set('display_errors', '0');

// This is an include bridge, never an HTTP endpoint. Apache also denies access.
if (PHP_SAPI !== 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit('Not found.');
}
try {
    $root = getenv('BMA_APP_ROOT');
    if ($root === false) $root = $_SERVER['BMA_APP_ROOT'] ?? '/home/bbmmacom/repositories/Stripe_app';
    if (!is_string($root) || $root === '' || str_contains($root, "\0")
        || !(str_starts_with($root, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $root) === 1 || str_starts_with($root, '\\\\'))) {
        throw new RuntimeException();
    }
    $root = realpath($root);
    if ($root === false || !is_dir($root) || !is_readable($root)) throw new RuntimeException();
    foreach (['app','src','config','bin','sql','vendor'] as $directory) {
        if (!is_dir($root . '/' . $directory) || !is_readable($root . '/' . $directory)) throw new RuntimeException();
    }
    foreach (['src/Paths.php','src/database.php','app/Import/bootstrap.php','app/Views/layout.php','app/Security/Authenticator.php','app/Security/CurrentUserProvider.php','app/Security/DashboardPermissionService.php','vendor/autoload.php'] as $file) {
        if (!is_file($root . '/' . $file) || !is_readable($root . '/' . $file)) throw new RuntimeException();
    }
    require_once $root . '/src/Paths.php';
    if (defined('APP_ROOT') || defined('BMA_PUBLIC_ROOT') || defined('BMA_CONFIG_PATH')) throw new RuntimeException();
    define('BMA_PUBLIC_ROOT', __DIR__);
    Paths::assertPrivate($root, Paths::servedRoots([], false));
    // Controlled deployment fallback: runtime values always take precedence.
    $configFile = Paths::deploymentValue('BMA_CONFIG_FILE') ?? '/home/bbmmacom/bma-private/config.php';
    if (!Paths::isAbsolute($configFile)) throw new RuntimeException();
    $configFile = realpath($configFile);
    if ($configFile === false || !is_file($configFile) || !is_readable($configFile)) throw new RuntimeException();
    Paths::assertPrivate($configFile, Paths::servedRoots([], false));
    define('BMA_CONFIG_PATH', $configFile);
    define('APP_ROOT', $root);
} catch (Throwable) {
    http_response_code(503);
    exit('Application access is unavailable.');
}

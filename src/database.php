<?php
declare(strict_types=1);
require_once __DIR__ . '/Paths.php';
require_once __DIR__ . '/AppTime.php';

/** Environment wins, including intentional empty values. Never read a web-root config. */
function bmaConfig(?string $configPath = null): array
{
    $config = [];
    $path = $configPath ?? Paths::deploymentValue('BMA_CONFIG_FILE')
        ?? (defined('BMA_CONFIG_PATH') ? BMA_CONFIG_PATH : null);
    if ($path !== null) {
        $real = realpath($path);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            throw new RuntimeException('The external configuration file is not readable.');
        }
        // Reject known public locations before executing the configuration file.
        Paths::assertPrivate($real, Paths::servedRoots([], false));
        $config = require $real;
        if (!is_array($config)) {
            throw new RuntimeException('The external configuration must return an array.');
        }
    }
    foreach (['DB_HOST','DB_PORT','DB_DATABASE','DB_USERNAME','DB_PASSWORD',
        'STRIPE_API_KEY','STRIPE_WEBHOOK_SECRET','APP_ENV','APP_URL','APP_KEY','SESSION_COOKIE_NAME',
        'APP_DISPLAY_TIMEZONE','APP_ERROR_LOG','SESSION_SAVE_PATH','SESSION_IDLE_SECONDS','SESSION_ABSOLUTE_SECONDS','LOGIN_WINDOW_SECONDS','LOGIN_MAX_ATTEMPTS','LOGIN_IP_MAX_ATTEMPTS',
        'COGNITO_SOURCE_TIMEZONE','COGNITO_FORM_NAMESPACE','IMPORT_TEMP_DIR','PUBLIC_WEB_ROOT','IMPORT_ABANDONED_SECONDS',
        'IMPORT_MAX_BYTES','AUTO_MATCH_THRESHOLD','REVIEW_THRESHOLD'] as $key) {
        $value = $key === 'PUBLIC_WEB_ROOT' ? (Paths::deploymentValue($key) ?? false) : getenv($key);
        if ($value !== false) {
            $config[$key] = $value;
        }
    }
    // PUBLIC_WEB_ROOT may be defined inside the external config, especially for CLI.
    $roots = Paths::servedRoots($config);
    if (isset($real)) Paths::assertPrivate($real, $roots);
    AppTime::configure($config);
    return $config;
}

function bmaDatabase(array $config): PDO
{
    foreach (['DB_HOST','DB_PORT','DB_DATABASE','DB_USERNAME','DB_PASSWORD'] as $key) {
        if (!array_key_exists($key, $config) || !is_string($config[$key])) {
            throw new RuntimeException('Database configuration is incomplete.');
        }
    }
    if (!preg_match('/\A[a-zA-Z0-9_.:\-]+\z/', $config['DB_HOST'])
        || !preg_match('/\A[a-zA-Z0-9_\-]+\z/', $config['DB_DATABASE'])
        || !ctype_digit($config['DB_PORT']) || (int) $config['DB_PORT'] < 1 || (int) $config['DB_PORT'] > 65535
        || $config['DB_USERNAME'] === '') {
        throw new RuntimeException('Invalid database host, port, name, or username.');
    }
    $pdo = new PDO(
        'mysql:host=' . $config['DB_HOST'] . ';port=' . $config['DB_PORT']
        . ';dbname=' . $config['DB_DATABASE'] . ';charset=utf8mb4',
        $config['DB_USERNAME'], $config['DB_PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES => false,
         PDO::MYSQL_ATTR_MULTI_STATEMENTS => false]
    );
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("SET time_zone = '+00:00'");
    $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION'");
    return $pdo;
}

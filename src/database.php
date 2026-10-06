<?php
declare(strict_types=1);

/** Environment wins, including intentional empty values. Never read a web-root config. */
function bmaConfig(?string $configPath = null): array
{
    $config = [];
    $path = $configPath ?? (getenv('BMA_CONFIG_FILE') ?: null);
    if ($path !== null) {
        $real = realpath($path);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            throw new RuntimeException('The external configuration file is not readable.');
        }
        $roots = [dirname(__DIR__) . '/public'];
        if (!empty($_SERVER['DOCUMENT_ROOT'])) {
            $roots[] = $_SERVER['DOCUMENT_ROOT'];
        }
        foreach ($roots as $root) {
            $resolved = realpath($root);
            if ($resolved !== false) {
                $prefix = str_replace('\\', '/', $resolved) . '/';
                $normalized = str_replace('\\', '/', $real);
                if (strncasecmp($normalized, $prefix, strlen($prefix)) === 0) {
                    throw new RuntimeException('Configuration must be outside the public web root.');
                }
            }
        }
        $config = require $real;
        if (!is_array($config)) {
            throw new RuntimeException('The external configuration must return an array.');
        }
    }
    foreach (['DB_HOST','DB_PORT','DB_DATABASE','DB_USERNAME','DB_PASSWORD',
        'STRIPE_API_KEY','STRIPE_WEBHOOK_SECRET','APP_ENV','APP_URL','APP_KEY','SESSION_COOKIE_NAME',
        'APP_ERROR_LOG','SESSION_SAVE_PATH','SESSION_IDLE_SECONDS','SESSION_ABSOLUTE_SECONDS','LOGIN_WINDOW_SECONDS','LOGIN_MAX_ATTEMPTS','LOGIN_IP_MAX_ATTEMPTS',
        'COGNITO_SOURCE_TIMEZONE','COGNITO_FORM_NAMESPACE','IMPORT_TEMP_DIR','PUBLIC_WEB_ROOT','IMPORT_ABANDONED_SECONDS',
        'IMPORT_MAX_BYTES','AUTO_MATCH_THRESHOLD','REVIEW_THRESHOLD'] as $key) {
        $value = getenv($key);
        if ($value !== false) {
            $config[$key] = $value;
        }
    }
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

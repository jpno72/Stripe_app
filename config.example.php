<?php
declare(strict_types=1);

// Copy OUTSIDE public_html, for example /home/CPANEL_USER/bma-private/config.php.
// Point BMA_CONFIG_FILE at that absolute path. Environment variables take precedence.
return [
    'DB_HOST' => 'localhost',
    'DB_PORT' => '3306',
    'DB_DATABASE' => 'CPANEL_USER_bma',
    'DB_USERNAME' => 'CPANEL_USER_bma_user',
    'DB_PASSWORD' => 'REPLACE_WITH_DATABASE_PASSWORD',
    'STRIPE_API_KEY' => 'rk_test_REPLACE_WITH_RESTRICTED_READ_KEY',
    'STRIPE_WEBHOOK_SECRET' => 'whsec_REPLACE_IF_WEBHOOKS_ARE_ENABLED',
    'APP_ENV' => 'production',
    'APP_URL' => 'https://analytics.example.com',
    'APP_KEY' => 'REPLACE_WITH_RANDOM_APPLICATION_KEY',
    'SESSION_COOKIE_NAME' => 'bma_session',
    'APP_ERROR_LOG' => '/home/CPANEL_USER/bma-private/application-errors.log',
    'SESSION_SAVE_PATH' => '/home/CPANEL_USER/bma-private/sessions',
    'SESSION_IDLE_SECONDS' => '1800',
    'SESSION_ABSOLUTE_SECONDS' => '28800',
    'LOGIN_WINDOW_SECONDS' => '900',
    'LOGIN_MAX_ATTEMPTS' => '5',
    'LOGIN_IP_MAX_ATTEMPTS' => '30',
    // Intentionally unresolved. Set explicitly after confirming the export's timezone.
    'COGNITO_SOURCE_TIMEZONE' => '',
    'COGNITO_FORM_NAMESPACE' => 'bma-waiver',
    'IMPORT_TEMP_DIR' => '/home/CPANEL_USER/bma-private/import-tmp',
    'PUBLIC_WEB_ROOT' => '/home/CPANEL_USER/public_html',
    // Operational abandoned-job timeout only; not a waiver-data retention period.
    'IMPORT_ABANDONED_SECONDS' => '86400',
    'IMPORT_MAX_BYTES' => '52428800',
    'AUTO_MATCH_THRESHOLD' => '0.90',
    'REVIEW_THRESHOLD' => '0.45',
];

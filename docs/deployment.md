# Deploy BMA Analytics on shared cPanel hosting

## Layout and requirements

Use a maintained PHP 8.2+ release with PDO MySQL, mbstring, fileinfo, JSON, cURL and OpenSSL, Composer dependencies, Apache and MariaDB 10.4+ (prefer a maintained host-provided release). No Node/Python server, root access, daemon or VPS is required. The current migration runner validates MariaDB; MySQL has not been validated.

Example layout:

```
/home/ACCOUNT/bma-analytics/                 application, vendor, sql, bin, docs
/home/ACCOUNT/bma-analytics/public/         ONLY this directory is the document root
/home/ACCOUNT/private/bma-analytics-config.php
/home/ACCOUNT/private/bma-import-tmp/
/home/ACCOUNT/private/bma-sessions/
/home/ACCOUNT/private/bma-errors.log
```

Set the analytics subdomain document root to `bma-analytics/public`. If the host only supports public_html, deploy public files there and place the rest privately, preserving/adjusting the relative application paths consistently. Never serve the complete repository. Keep Apache `.htaccess` and PHP `.user.ini` with public files. Do not upload `.local`, tests, CSV exports, caches, backups, credentials or development routers into public directories. Keep private directories mode 0700 and private configuration/logs/files 0600 under the hosting account. Check permissions with the actual PHP handler.

## Configure and install

1. Create a separate production MariaDB database and restricted database user in cPanel. Development/test data and Stripe environments use separate databases.
2. Install dependencies using `composer install --no-dev --prefer-dist --optimize-autoloader` in the private application directory, or upload a locally built vendor directory if Composer is unavailable on hosting. Keep composer.lock; run `composer audit --locked` when updating/releasing.
3. Copy `config.example.php` to `/home/ACCOUNT/private/bma-analytics-config.php` and set real values there, never in source. Set BMA_CONFIG_FILE via the hosting environment or Apache `SetEnv BMA_CONFIG_FILE /home/ACCOUNT/private/bma-analytics-config.php` where supported. Environment variables override config. Do not place secrets in .htaccess or .user.ini. Ensure Apache/PHP-FPM exposes the variable; ask hosting support if needed.
4. Set APP_ENV=production, canonical HTTPS APP_URL, a unique random APP_KEY (`php -r 'echo bin2hex(random_bytes(32)),PHP_EOL;'`), DB values, SESSION_COOKIE_NAME, private SESSION_SAVE_PATH, private APP_ERROR_LOG, IMPORT_TEMP_DIR and actual PUBLIC_WEB_ROOT. Confirm Cognito timezone instead of guessing. APP_KEY replacement resets throttle bucket identity; treat it as a private deployment secret.
5. Enable HTTPS with cPanel AutoSSL. Ensure PHP sees HTTPS=on or server port 443 through any trusted proxy configuration. Client forwarding headers are ignored. Production HTTP is rejected, not redirected using an untrusted Host header. Use the host's canonical HTTPS redirect for convenience. HSTS takes effect on HTTPS responses.
6. Run `php bin/install.php --check --config=/home/ACCOUNT/private/bma-analytics-config.php`, then `--apply` with the same config. Existing installations apply only pending migration 006; never rewrite/checksum-bypass historical migrations. Empty installations use the full migration chain. DDL auto-commits: investigate partial migrations before retrying. Back up before applying a production migration.
7. Installer privileges: SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, TRIGGER, CREATE VIEW and normal FK support on this database only. After installation, reduce runtime privileges to SELECT/INSERT/UPDATE/DELETE on required tables and SELECT on invoker views/base tables. Do not grant global administration, FILE, CREATE USER or CREATE DATABASE to the production runtime. MariaDB GET_LOCK is used for coordinated imports/migrations.

## Create first administrator

Use cPanel Terminal/private SSH with no password arguments or plaintext password files. The tool reads one password line from STDIN and prints only a result:

```bash
read -r -s -p 'New admin password: ' bma_password; printf '
'
printf '%s
' "$bma_password" | php bin/create-user.php --email=admin@example.com --name='BMA Administrator' --role=admin --password-stdin --config=/home/ACCOUNT/private/bma-analytics-config.php
unset bma_password
```

The shell variable is only transient local input. Choose a unique 12–72 byte password. Create viewers with `--role=viewer`. Reset an account using the same private input flow with `--email=... --reset --password-stdin`; this revokes active sessions. Do not use `echo 'actual-password'` commands, commit credentials, or ship a default account. If Terminal is unavailable, use the host's permitted private CLI/one-time cron mechanism with securely piped input; never publish a browser installer. User Management changes roles/active state and audits each change; passwords are never displayed. Keep at least one active administrator.

Application sessions now determine access. IMPORT_ADMIN_USERS and MATCH_REVIEW_ADMIN_MAP are obsolete and ignored. Optional cPanel Directory Privacy can remain as an additional boundary; configure it through cPanel rather than changing application roles. Viewers must receive HTTP 403 from administrative pages even when they know the URL.

## Operational checks

- Confirm unauthenticated reports redirect to login and JSON APIs return 401; confirm viewer admin access is 403, valid admin imports/matches render, and invalid CSRF is rejected.
- Check cookies have Secure/HttpOnly/SameSite=Strict, session rotation, logout, idle/absolute expiry and session revocation after role/password changes.
- Confirm no stack traces are displayed and APP_ERROR_LOG is private/writable. Set cPanel PHP display_errors=Off, log_errors=Off and zend.exception_ignore_args=On, matching public/.user.ini. Application logging uses fixed safe codes; do not enable debug/body/SQL logging in production.
- Confirm CSP/other headers do not block charts, tables or Matches confirmations. The current UI uses pinned Bootstrap, Chart.js and DataTables assets from documented CDNs.
- Set PHP upload_max_filesize/post_max_size and IMPORT_MAX_BYTES consistently; allow multipart overhead. Configure upload_tmp_dir privately outside the document root; verify generated processing files and failed originals are deleted. Native PHP upload temp cleanup must also work.
- Schedule `php /home/ACCOUNT/bma-analytics/bin/cleanup-imports.php` periodically and `php /home/ACCOUNT/bma-analytics/bin/cleanup-security.php` daily via cPanel cron, with private configuration in the environment. Protect cron output and logs.
- Back up the database/private config using restricted access and encryption offered by the host; never store archives in public_html or commit them. No fixed waiver retention period has been invented.
- Configure a dedicated restricted Stripe read key only when needed; never expose it to JavaScript. Leave production webhooks unregistered until Step 12 implements verified processing, retries and idempotency.

Local tests use disposable databases and a development-only HTTPS simulation. They do not establish real hosting TLS, grants, Apache behavior or filesystem permissions. Perform these deployment checks with the actual host before exposing production data.

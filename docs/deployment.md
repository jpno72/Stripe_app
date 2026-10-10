# Deploy BMA Analytics on shared cPanel hosting

## Layout and requirements

Use a maintained PHP 8.2+ release with PDO MySQL, mbstring, fileinfo, JSON, cURL and OpenSSL, Composer dependencies, Apache and MariaDB 10.4+ (prefer a maintained host-provided release). No Node/Python server, root access, daemon or VPS is required. The current migration runner validates MariaDB; MySQL has not been validated.

## Split cPanel layout

Production uses three separate locations:

```text
/home/bbmmacom/repositories/Stripe_app/                         private project
    app/ bin/ config/ src/ sql/ vendor/ docs/ composer.json composer.lock
/home/bbmmacom/bma-private/                                    private configuration/storage
    config.php import-tmp/ sessions/ application-errors.log
/home/bbmmacom/public_html/customers.bloommma.com/public/        Apache document root
    _bootstrap.php admin/ api/ assets/ index.php login.php ...
```

Deploy/copy **only the contents** of the repository's `public/` directory to that Apache document root, including `.htaccess`, `.user.ini` and `admin/.htaccess`. Do not copy the entire project or nest an additional `public/` directory inside the document root. The private project does not need its own `public/` directory at runtime. A Git checkout may retain a private source copy, but it is not served.

Configure the subdomain's document root to the exact public directory above. The browser URL is **https://customers.bloommma.com/**; `/public` is a filesystem directory name and must not be added to browser URLs. Existing `/assets/`, `/api/`, `/login.php`, dashboard links and rewrite rules remain unchanged.

Never serve `app`, `src`, `config`, `bin`, `sql`, `vendor`, `.git`, private configuration, sessions, logs or secrets. Do not upload `.local`, tests, CSV exports, caches, backups, Word documents or development routers into public directories. Keep private directories mode 0700 and private configuration/logs/files 0600 under the hosting account. Check permissions with the actual PHP handler, including any `open_basedir` restrictions on access to the private project and storage.

### Two distinct bootstrap settings

Runtime environment settings remain preferred when the PHP handler supports them:

```text
BMA_APP_ROOT=/home/bbmmacom/repositories/Stripe_app
BMA_CONFIG_FILE=/home/bbmmacom/bma-private/config.php
```

- **BMA_APP_ROOT** locates private application code. `public/_bootstrap.php` canonicalizes and checks the directory, expected project directories and bootstrap files before defining `APP_ROOT`. If neither getenv nor the exact trusted server variable supplies a value, the central bootstrap uses the one approved fallback `/home/bbmmacom/repositories/Stripe_app`. False, null, empty and whitespace-only runtime path values are missing: try getenv first, then the exact trusted server variable, then the controlled fallback. A nonblank invalid path still fails closed. Invalid resolution returns a generic HTTP 503 without filesystem details.
- **BMA_CONFIG_FILE** selects the separate private PHP configuration file. It does not locate application classes. Environment variables continue to override values returned by that file. If neither runtime source supplies the path, the same central bootstrap uses `/home/bbmmacom/bma-private/config.php`. It resolves a readable regular file, rejects known public locations and exposes its canonical path through BMA_CONFIG_PATH to the existing configuration loader. The loader still rechecks privacy after loading PUBLIC_WEB_ROOT. Both account-specific fallback literals exist only in public/_bootstrap.php; they contain no credentials.

Apache `SetEnv` may expose these names through `getenv()` or the exact trusted PHP server variable. The bootstrap/loader support these representations; nonblank environment path values take precedence. Blank path values fall through to the trusted server value and then the controlled fallback. Other configuration values retain their existing environment precedence. Browser `HTTP_BMA_APP_ROOT` / `HTTP_BMA_CONFIG_FILE` headers are never used. Configure the variables through supported cPanel/Apache/PHP-FPM settings and verify their availability on the host. For example, where the handler supports Apache SetEnv:

```apache
SetEnv BMA_APP_ROOT /home/CPANEL_USER/repositories/Stripe_app
SetEnv BMA_CONFIG_FILE /home/CPANEL_USER/bma-private/config.php
```

These paths are deployment metadata; do not put credentials or secret values in `.htaccess` or `.user.ini`. The deployed cPanel PHP handler was verified not to propagate Apache SetEnv values to either getenv or the exact server variables. Some PHP-FPM/shared-host configurations behave this way. On this deployment the approved centralized fallbacks therefore supply both paths without Apache environment propagation. SetEnv directives may remain optional. Other deployments should supply trusted runtime values or deliberately change only these two central fallback literals; there is no directory search. Do not use request headers or public copies of private code as substitutes. `.env.example` is documentation; no automatic `.env` loader exists.

Set these non-secret path/URL values in the private `config.php` (or equivalent environment variables):

```php
'PUBLIC_WEB_ROOT' => '/home/bbmmacom/public_html/customers.bloommma.com/public',
'IMPORT_TEMP_DIR' => '/home/bbmmacom/bma-private/import-tmp',
'APP_ERROR_LOG' => '/home/bbmmacom/bma-private/application-errors.log',
'SESSION_SAVE_PATH' => '/home/bbmmacom/bma-private/sessions',
'APP_URL' => 'https://customers.bloommma.com',
```

Create the public document root and private import/session/log-parent directories before using the application or CLI. All directory settings must resolve to existing readable absolute directories; private writable storage must be writable by PHP. Sensitive locations must remain outside every served root, including any other account website.

The shared Paths service checks configured PUBLIC_WEB_ROOT, applicable server DOCUMENT_ROOT and the public bootstrap's actual directory. It does not require `<private-project>/public`. Known public config locations are rejected before inclusion; configuration is rechecked after loading and applying environment precedence so that a PUBLIC_WEB_ROOT declared inside the private config also governs CLI checks. CLI/cron must have the actual PUBLIC_WEB_ROOT available through config/environment because DOCUMENT_ROOT is normally absent.

Private `bin/` entry points locate their code relative to the private project and do not load the public bootstrap, so they do not inherit its fallback constants. They still use explicit --config where supported or BMA_CONFIG_FILE in the CLI/cron environment; BMA_APP_ROOT is not required for them. For cron, use an explicit private config environment and absolute command paths, for example:

```bash
BMA_CONFIG_FILE=/home/bbmmacom/bma-private/config.php php /home/bbmmacom/repositories/Stripe_app/bin/cleanup-imports.php
BMA_CONFIG_FILE=/home/bbmmacom/bma-private/config.php php /home/bbmmacom/repositories/Stripe_app/bin/cleanup-security.php
```

### Public bootstrap and optional outer protection

`/_bootstrap.php` is an include bridge, not an endpoint. Public `.htaccess` denies direct HTTP access while PHP can include it internally; the script also returns 404 on direct web execution when identified by SCRIPT_FILENAME. Existing rewrites and security headers remain in place.

Application sessions, canonical app_users and DashboardPermissionService still determine identity and dashboard permissions. `public/admin/.htaccess` is intentionally unchanged: its existing `Require valid-user` must be evaluated against actual cPanel/Apache Basic Auth configuration on the deployed host. Do not remove or assume that outer barrier as part of path deployment. cPanel Directory Privacy, when configured, does not become the application identity provider.

## Configure and install

1. Create a separate production MariaDB database and restricted database user in cPanel. Development/test data and Stripe environments use separate databases.
2. Install dependencies using `composer install --no-dev --prefer-dist --optimize-autoloader` in the private application directory, or upload a locally built vendor directory if Composer is unavailable on hosting. Keep composer.lock; run `composer audit --locked` when updating/releasing.
3. Confirm the central fallback paths match this deployment, or supply the preferred trusted runtime values described above. Copy `config.example.php` to `/home/bbmmacom/bma-private/config.php` and set real values there, never in source. Set BMA_CONFIG_FILE via the hosting environment or Apache `SetEnv BMA_CONFIG_FILE /home/bbmmacom/bma-private/config.php` where supported. Environment variables override config. Do not place secrets in .htaccess or .user.ini. For web requests the central fallback handles absent Apache/PHP-FPM variables; for CLI/cron supply the config path explicitly.
4. Set APP_ENV=production, canonical HTTPS APP_URL, a unique random APP_KEY (`php -r 'echo bin2hex(random_bytes(32)),PHP_EOL;'`), DB values, SESSION_COOKIE_NAME, private SESSION_SAVE_PATH, private APP_ERROR_LOG, IMPORT_TEMP_DIR and actual PUBLIC_WEB_ROOT. Confirm Cognito timezone instead of guessing. APP_KEY replacement resets throttle bucket identity; treat it as a private deployment secret.
5. Enable HTTPS with cPanel AutoSSL. Ensure PHP sees HTTPS=on or server port 443 through any trusted proxy configuration. Client forwarding headers are ignored. Production HTTP is rejected, not redirected using an untrusted Host header. Use the host's canonical HTTPS redirect for convenience. HSTS takes effect on HTTPS responses.
6. Run `php bin/install.php --check --config=/home/bbmmacom/bma-private/config.php`, then `--apply` with the same config. Existing installations apply only migrations reported as pending; never rewrite/checksum-bypass historical migrations. Empty installations use the full migration chain. DDL auto-commits: investigate partial migrations before retrying. Back up before applying a production migration.
7. Installer privileges: SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, TRIGGER, CREATE VIEW and normal FK support on this database only. After installation, reduce runtime privileges to SELECT/INSERT/UPDATE/DELETE on required tables and SELECT on invoker views/base tables. Do not grant global administration, FILE, CREATE USER or CREATE DATABASE to the production runtime. MariaDB GET_LOCK is used for coordinated imports/migrations.

## Create first administrator

Use cPanel Terminal/private SSH with no password arguments or plaintext password files. The tool reads one password line from STDIN and prints only a result:

```bash
read -r -s -p 'New admin password: ' bma_password; printf '
'
printf '%s
' "$bma_password" | php bin/create-user.php --email=admin@example.com --name='BMA Administrator' --role=admin --password-stdin --config=/home/bbmmacom/bma-private/config.php
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
- Schedule `php /home/bbmmacom/repositories/Stripe_app/bin/cleanup-imports.php` periodically and `php /home/bbmmacom/repositories/Stripe_app/bin/cleanup-security.php` daily via cPanel cron, with private configuration in the environment. Protect cron output and logs.
- Back up the database/private config using restricted access and encryption offered by the host; never store archives in public_html or commit them. No fixed waiver retention period has been invented.
- Configure a dedicated restricted Stripe read key only when needed; never expose it to JavaScript. Leave production webhooks unregistered until Step 12 implements verified processing, retries and idempotency.

Local tests use disposable databases and a development-only HTTPS simulation. They do not establish real hosting TLS, grants, Apache behavior or filesystem permissions. Perform these deployment checks with the actual host before exposing production data.

## Split-path validation

Run `php tests/deployment-paths.php` locally for focused path checks with a disposable split-tree fixture and no database access. This validation does not run migrations or the full regression suite. See [split-deployment-validation.md](split-deployment-validation.md) for recorded results. On the actual host, verify document root, PHP environment propagation, filesystem permissions/open_basedir, TLS, Apache rewrite/header/bootstrap-denial behavior, optional Directory Privacy and the previously pending deployed-session checks. Local path tests do not certify those host settings.

## cPanel fallback validation — 2026-10-08

The approved app/config fallback paths are resolved and validated in the public bootstrap only. Authentication, authorization, data/schema, migrations, imports, matching, reporting and Stripe behavior are unchanged. Focused tests use a temporary split-tree copy with fixture-local substituted fallback literals to exercise successful fallback resolution on Windows; production paths are not created locally and no live deployment is implied. Runtime precedence, invalid path rejection, public-config rejection and loader integration are covered. Actual host readability/open_basedir and final web execution still require deployment verification. No migrations or Step 12 work are part of this change.

Focused results: **64 split-path checks passed** with `php tests/deployment-paths.php`; all three modified PHP files passed lint, and `git diff --check` passed. Runtime production changes are limited to `public/_bootstrap.php` and configuration-path selection in `src/database.php`. Source hashes confirm authentication, authorization, business code, public pages, CLI, Apache rules and SQL/migrations were unchanged during this correction; the database connection/query setup is unchanged. Each approved account-specific fallback literal appears exactly once in production PHP, in the centralized bootstrap.

Deploy the updated `public/_bootstrap.php` to the separate public document root and the updated `src/database.php` into the private project together. Retain the previous split-deployment helpers and public include changes. The private config must still provide the actual PUBLIC_WEB_ROOT and private storage settings. No credentials are included in the bootstrap; environment configuration remains supported and optional SetEnv directives can remain. CLI configuration remains explicit as documented above.

## Blank deployment values — 2026-10-08

The public bridge and shared deployment-path resolver treat false, null, empty and whitespace-only runtime paths as missing. Nonblank paths are preserved verbatim and still undergo canonicalization, readability and privacy validation. This also ensures the configuration loader uses the validated bootstrap fallback when its runtime config path is blank. Production PHP changes for this correction are only `public/_bootstrap.php` and `src/Paths.php`; `src/database.php` is unchanged. Focused tests cover environment/server blanks, runtime precedence, fallback resolution and retained fail-closed checks. These local checks do not establish the deployed host’s web result.

Validation: **99 focused split-path checks passed** (`php tests/deployment-paths.php`), including both path variables with getenv false/empty/whitespace, missing/null/false/blank server values, valid environment precedence and successful fixture-local fallbacks. PHP lint passed for `public/_bootstrap.php`, `src/Paths.php` and `tests/deployment-paths.php`. `git diff --check` passed. Hash comparison confirmed all other runtime files, including authentication, authorization, database logic and migrations, were unchanged. No database or live-host changes were performed.

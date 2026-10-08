# Split cPanel deployment validation

Date: 2026-10-07. Result: **PASS — local split-path support**. Actual hosting settings remain deployment checks.

## Scope

Implemented the public/private path corrections only. No schema or migrations, SQL/reporting calculations, matching/scoring, authentication/provider decisions, dashboard permissions, Stripe integration or import business rules changed. No database connection, migration, import, full regression suite or Step 12 work was performed.

Production layout:

- Private application: `/home/bbmmacom/repositories/Stripe_app`
- Private config/storage: `/home/bbmmacom/bma-private`
- Apache document root: `/home/bbmmacom/public_html/customers.bloommma.com/public`
- Browser origin: `https://customers.bloommma.com/` (no `/public` URL prefix)

## Focused results

`php tests/deployment-paths.php`: **53 checks passed**. The test creates an isolated temporary split tree, copies private app/src/config/bin and public files, provides an inert vendor-autoload placeholder for location checks, and removes the fixture afterward. It deliberately has **no private-project/public directory**. It neither tests Stripe SDK behavior nor contacts a database/API.

| Check | Result |
|---|---|
| Missing, empty, relative, nonexistent and incomplete BMA_APP_ROOT fail with generic status 503 and no path detail | PASS |
| Valid canonical private application root resolves through the separate public bootstrap | PASS |
| Exact trusted server-variable fallback works; explicit environment value retains precedence | PASS |
| Client HTTP_BMA_APP_ROOT cannot supply application identity/path configuration | PASS |
| BMA_CONFIG_FILE works through environment or exact trusted server variable | PASS |
| Existing environment precedence, including intentionally empty DB_PASSWORD, is preserved | PASS |
| CLI reads PUBLIC_WEB_ROOT from external config without DOCUMENT_ROOT | PASS |
| Config under PUBLIC_WEB_ROOT is rejected, including when the root becomes known only after config loading | PASS |
| Known DOCUMENT_ROOT or bootstrap public-directory config is rejected before executing the config | PASS |
| No actual served root for CLI privacy checks fails closed | PASS |
| Public log/session destinations rejected; private writable locations accepted | PASS |
| Additional server document root protects both logs and sessions | PASS |
| Canonical directory-boundary comparison permits an outside directory with a similar name prefix | PASS |
| Production-mode session initialization uses the complete deployment context with private session storage | PASS |
| All 25 original public-to-private includes use APP_ROOT and resolve to fixture private files | PASS |
| Complete referenced private include graph loads with public files physically separated | PASS |
| No production public PHP file retains the adjacent-app dirname assumption | PASS |
| Private CLI create-user --help resolves its original includes without public bootstrap or database access | PASS |
| Apache source includes direct-bootstrap denial | PASS — source inspection; host execution pending |

Additional source/diff checks:

- **19 modified/new PHP files linted successfully**, including the two path helpers and focused test harness.
- All existing public PHP changes match exactly the 25 include-root replacements and the two bootstrap loads. Root-relative URLs, forms, redirects and authorization guards are unchanged.
- Private application classes, CLI scripts, import mappings, SQL/migrations, and `public/admin/.htaccess` match their before-change hashes, except the two authorized validators (`src/database.php`, `app/Security/Security.php`).
- Security::boot differs only in its session-directory validation call; CSRF, session settings, login policy and application database connection/query setup are unchanged.
- `git diff --check` passed.

Prior Step 11 authentication/security and dashboard-access authorization/CSRF/override/audit/navigation/lockout results are reused; they were not rerun. This path validation does not claim that the pending deployed-session validation has passed.

## Files changed

Added `public/_bootstrap.php`, `src/Paths.php`, `tests/deployment-paths.php` and this validation record. Updated the 14 public PHP files identified in the audit, `src/database.php`, `app/Security/Security.php`, `public/.htaccess`, `.env.example` and `docs/deployment.md`. Existing public URLs and admin Apache rules remain unchanged.

## Required deployment configuration

Web PHP must receive:

```text
BMA_APP_ROOT=/home/bbmmacom/repositories/Stripe_app
BMA_CONFIG_FILE=/home/bbmmacom/bma-private/config.php
```

The external private configuration (or equivalent environment variables) must provide:

```text
PUBLIC_WEB_ROOT=/home/bbmmacom/public_html/customers.bloommma.com/public
IMPORT_TEMP_DIR=/home/bbmmacom/bma-private/import-tmp
APP_ERROR_LOG=/home/bbmmacom/bma-private/application-errors.log
SESSION_SAVE_PATH=/home/bbmmacom/bma-private/sessions
APP_URL=https://customers.bloommma.com
APP_ENV=production
```

Existing database credentials, APP_KEY and other settings still belong in private configuration/environment; none are supplied or committed here. CLI/cron uses its own BMA_CONFIG_FILE environment (or an existing supported --config option) and reads the actual PUBLIC_WEB_ROOT from configuration. BMA_APP_ROOT is required by public entry points, not private CLI entry points. No .env loader or guessed application-directory fallback was added.

## Host-level items not certified locally

- Exact subdomain document root and TLS state reported to PHP.
- Trusted variables reaching the actual cPanel PHP-FPM/Apache handler, including rewritten routes, login and APIs.
- PHP read/write permissions and open_basedir restrictions for the private project, configuration, sessions, logs and uploads.
- Apache rewrite/security-header behavior, and HTTP denial of `/_bootstrap.php` while internal inclusion works.
- The intentionally unchanged admin `Require valid-user` rule and its actual cPanel Basic Auth configuration.
- Real deployed session/provider validation, logout/revocation and role-based direct-URL checks previously documented as pending.

See [deployment.md](deployment.md) for installation and host checks. No production deployment was performed.

SPLIT DEPLOYMENT SUPPORT READY

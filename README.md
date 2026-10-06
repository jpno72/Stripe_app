# BMA Analytics

Stages 1–6: database foundation, CSV importer, conservative identity matching, administrator review, reporting SQL views, and a responsive dashboard shell for a PHP 8.2+ application on shared cPanel hosting.

- [Schema and migration decisions](docs/database-schema.md)
- [Initial SQL schema](sql/schema.sql)
- [Initial migration (unchanged)](sql/migrations/001_initial_schema.sql)
- [Step 1 corrections](sql/migrations/002_step1_contract.sql)
- [Stripe field minimization](sql/migrations/003_minimize_stripe_fields.sql)
- [Identity candidate migration](sql/migrations/004_identity_match_candidates.sql)
- [Reporting views migration](sql/migrations/005_reporting_views.sql)
- [Reporting views](sql/views.sql)
- [Reporting definitions and limitations](docs/reporting-definitions.md)
- [Dashboard shell and access](docs/dashboard-shell.md)
- [Final field mapping for review](docs/import-field-mapping.md)
- [Import retention contract](docs/imports.md)
- [Cognito field meanings and deduplication](docs/cognito-field-meanings.md)
- [CLI installer](bin/install.php)
- [Admin import workflow](public/admin/imports.php)
- [CLI import command](bin/import.php)
- [Identity matching rules](docs/identity-matching.md)
- [CLI matcher](bin/match-identities.php)
- [Administrator match review](public/admin/matches.php)
- [Match review setup and decisions](docs/match-review.md)

Use a pre-created empty MariaDB database. Keep the project and private configuration outside the public web root; only `public/` belongs in the document root. Set environment variables or supply an external configuration copied from `config.example.php`.

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
php bin/install.php --check --config=/home/CPANEL_USER/bma-private/config.php
php bin/install.php --apply --config=/home/CPANEL_USER/bma-private/config.php
```

The installer applies all pending migrations without importing CSVs or creating login accounts. schema.sql is the consolidated result of migrations 001–005. Read the schema documentation for mappings, privileges, and recovery from partial installation. MariaDB 10.4+ is the validated database target. Migration 005 creates views, so the installation user also needs CREATE VIEW privileges.

For integrity tests, use a fresh installed database named `bma_schema_test_*`, supply its configuration through the environment, and run `php tests/schema.php`. Tests create committed synthetic fixtures in this disposable database. `php tests/migrations.php` also creates disposable databases to validate migration and snapshot equivalence; its local test account needs CREATE DATABASE. Never use production for tests.

Set COGNITO_SOURCE_TIMEZONE only after confirming the export's timezone. Configure a private IMPORT_TEMP_DIR and PUBLIC_WEB_ROOT, then schedule `php bin/cleanup-imports.php` with cPanel cron. The cleanup age is an operational timeout, not a waiver retention policy.

Step 2 imports are documented in [docs/imports.md](docs/imports.md). Configure cPanel Directory Privacy and `IMPORT_ADMIN_USERS` before opening the admin pages. Step 3 candidate matching is documented in [docs/identity-matching.md](docs/identity-matching.md). For Step 4, map each authenticated cPanel user to an active `app_users` admin in private `MATCH_REVIEW_ADMIN_MAP`; see [docs/match-review.md](docs/match-review.md). Run integration tests only against disposable test databases. Ignored and unknown field values are never approved for persistence. Application authentication belongs to Step 11.

Step 6 adds [the dashboard shell](docs/dashboard-shell.md) at `public/index.php`. Protect the entire `public/` directory with cPanel Directory Privacy, then keep the existing `/admin` protection and application allowlist. Revenue is available at `/revenue` and Membership at `/membership`, and Demographics at `/demographics`; other section pages remain placeholders. See [Revenue](docs/revenue-dashboard.md) and [Membership](docs/membership-dashboard.md) for metric scope and source-data limitations. [Demographics](docs/demographics-dashboard.md) documents approved fields, configurable age brackets and privacy-safe aggregation.

- [Dashboard access control and deployment](docs/dashboard-access-control.md) — incremental authorization following the already completed Step 11; apply migration 007.

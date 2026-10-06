# CSV imports

The admin workflow imports Stripe customers, subscriptions, transactions, and Cognito waivers independently. It uses PHP 8.2, PDO, MariaDB, and no daemon. The field contract is [import-field-mapping.md](import-field-mapping.md) and the executable whitelist is `config/import-mappings.php`. Review that mapping before using live exports.

## Deployment

1. Apply all pending migrations with `php bin/install.php --apply`. Set `BMA_CONFIG_FILE` to a PHP configuration file outside the public web root, or supply the corresponding environment variables. Never commit the private configuration.
2. Set `IMPORT_TEMP_DIR` to a private directory outside every served document root. Give the directory restrictive permissions. Set PHP's `upload_tmp_dir` outside the web root as well. Set `PUBLIC_WEB_ROOT` to the served root. Set `IMPORT_MAX_BYTES` and PHP `upload_max_filesize` / `post_max_size` consistently.
3. Require application login with an active admin role. Step 11 replaced the temporary cPanel guard; provision accounts privately using `bin/create-user.php --password-stdin`. See [deployment.md](deployment.md).
4. Set `COGNITO_FORM_NAMESPACE` to a stable form label. If Cognito's timezone-naive timestamps are known to use the business timezone, set `COGNITO_SOURCE_TIMEZONE=America/New_York`; otherwise leave it empty and timestamps remain unresolved. Never infer the zone from server settings.
5. Schedule `php bin/cleanup-imports.php` with cPanel cron. `IMPORT_ABANDONED_SECONDS` controls only cleanup of crashed temporary uploads.

The admin page is `/admin/imports.php`. It accepts CSV uploads, a source selection, a validation-only option, and Stripe account/environment confirmation. A diagnostic page lists bounded column names and fixed error codes without source values. The upload form uses a session CSRF token and post/redirect/get. Web imports record the signed-in administrator in `import_batches.created_by`.

CLI uses the same service and private upload lifecycle:

```sh
php bin/import.php --source=stripe_customers --file=/private/customers.csv --dry-run
php bin/import.php --source=stripe_customers --file=/private/customers.csv --stripe-account=acct_CONFIRMED --stripe-live=1
```

The CLI caller owns its input file. The service deletes only its generated private `.upload` copy. Web uploads delete both the PHP spool and managed copy after processing. Originals are never archived automatically. A 50 MiB web default can be changed in private configuration.

## Processing and counts

The service streams the file with `fgetcsv` (including quoted commas and newlines), accepts UTF-8 with an optional BOM, validates unique exact-case headers, and calculates a SHA-256 file hash. Unknown columns default to ignored; only their sanitized names appear as batch metadata. All row cells go through `ApprovedRecord::fromRow` before any staging JSON is formed. Ignored and unknown values never enter staging, normalized tables, errors, or logs. Raw approved strings retain their original spelling and whitespace. Normalizers operate on a separate approved value object.

Each attempt creates an `import_batches` row. Reimporting a previously completed file records `duplicate_of` and skips its normalized writes. A completed file with row errors can be retried. For changed or reordered files, Stripe external IDs and Cognito's stable locator select the normalized record, while filtered content hashes identify unchanged data. Repeated IDs within one file cause a fixed diagnostic and later rows are skipped. Empty CSV lines are counted as skipped and do not create raw rows. Counts are per CSV record and sum to `row_count`; warnings do not increment `error_count`.

Every nonblank valid-width row is staged with only approved source values. Normalized writes for one row use a transaction; another row can continue after an error. `processing_status` and `import_errors` show outcomes. Bad-width or invalid UTF-8 rows have a fixed diagnostic only, with no source row dump. The best-effort private operational log contains batch ID, row number, and fixed code only; database diagnostics remain authoritative if that log cannot be written. Dry runs create batch and filtered staging records with skipped outcomes and validation warnings; they do not write normalized reporting rows or establish Stripe account identity. They are excluded from completed-file duplicate detection.

The source file hash covers the uploaded bytes transiently for duplicate detection. It is not a person identity key. The parser does not hold the full CSV in memory; the managed temporary file stays locked while it is processed. The admin upload size limit bounds a single HTTP import. PHP memory and execution-time limits still need to fit the hosting account's CSV size; larger files can use CLI.

## Source semantics

- Customers use `cus_` IDs. A subscription or charge may create an `is_stub=1` customer when its profile CSV has not arrived. A later customer import fills that stub. Customer summary amounts have no reliable currency in this export, so only their approved original strings remain in staging and `CURRENCY_UNRESOLVED` is recorded.
- Subscriptions require `sub_` and `cus_` IDs. The source `Plan` references a `price_` stub and the visible item uses `csv-single-item`. Its quantity and currency remain unresolved. Subscription creation is not a member start date. A price change updates the provisional item; no Stripe item ID is invented.
- Transactions require `ch_`, amount, currency, and status. `Customer ID` links by exact `cus_` only. A blank customer ID remains unlinked, even if an email matches. Invoice IDs are preserved, while the CSV has no payment-intent or subscription identifier to join. CSV fee currency is absent, so fee and fee tax stay NULL; Step 12 may obtain real balance transaction values. Charge and converted values use exact integer minor-unit parsing, never floats.
- Cognito has no stable submission ID. Its locator uses the configured form namespace, source submission wall time, `Name`, and `Print Name`, independently of row order. An unchanged locator/content hash skips; changed content under a fallback locator is staged and flagged for review instead of silently overwriting. The source does not establish who owns the contact email, and no person/member identity link is created in Step 2.

If a Stripe source row's explicit customer/invoice reference disagrees with an existing normalized association, the row errors with `STRIPE_REFERENCE_CONFLICT`; its filtered raw record remains for review. Stripe account ID and live/test mode must be confirmed for non-dry-run **production** imports. The database's one-account/environment guard rejects conflicts. CSV prefixes alone cannot prove environment.

## Observed supplied exports

The supplied shelled files contain many empty rows: customers 47 records (1 populated), subscriptions 1 (1 populated), transactions 36 (2 populated), Cognito 350 (1 populated). The transaction file has one customer ID not present in the customer export and one transaction without a customer ID. Customer, subscription, and transaction fee currency is not established where the respective field is absent. Cognito's submission timestamp is timezone-naive. No source has an unknown header or malformed CSV in these samples. See the import history after a test run for exact batch outcomes.

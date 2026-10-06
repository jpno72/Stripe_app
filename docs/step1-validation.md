# Final Step 1 validation

> Historical stage evidence. Step 11 application login and roles supersede temporary cPanel authorization; see security.md and deployment.md.

Validated locally on September 30, 2026 using PHP 8.2.0 and MariaDB 10.4.27. No real CSV data was imported and no Stripe API synchronization was run.

| Requirement | Evidence |
|---|---|
| All migrations on empty database | 001, 002, and 003 applied successfully; repeated apply reported zero pending migrations |
| Preserve migration 001 | SHA-256 remains 75d740ab762c93a022de436c31199b6601ae0e505dbab424bfb40b698008fb50 |
| Consolidated schema equivalence | tests/migrations.php compared every SHOW CREATE TABLE and trigger definition after full migrations vs schema.sql |
| Foreign keys | All 30 checked for supporting indexes and restrictive delete/update rules |
| Uniqueness | 23 primary keys and 20 additional unique indexes; identity/item rejection scenarios passed |
| Subscription items | Multiple items, repeated price, unique si_ IDs, FK rejection, and legacy single-item upgrade tested |
| BMA allocation | Eight concurrent independent PHP/PDO processes allocated distinct IDs derived from AUTO_INCREMENT |
| Immutable identities | Public/internal changes raise explicit errors; application rejection audit tested |
| Approved-only staging | All four source mappings preserve approved strings and discard ignored/unknown values; all four raw tables reject unknown JSON keys |
| Stripe field minimization | Customer/transaction Card ID and customer descriptions are absent from current normalized tables and rejected by the three Stripe staging tables |
| Existing data guard | 003 refuses historical Stripe raw rows and values in columns being removed; tests confirm it leaves them intact for reviewed migration |
| Error payload exclusion | No import_errors raw-row column; fixed diagnostic codes/messages reject arbitrary text |
| Log/audit exclusion | Sentinel values absent from filtered staging, errors, application logs, and identity audits |
| Temporary files | Managed files deleted after success/failure; failure exceptions sanitized; active lock respected; abandoned file removed; public paths rejected |
| Native upload wrapper | Uses is_uploaded_file before ownership/deletion; rejection of arbitrary files tested. Full HTTP upload integration belongs to Step 2; no endpoint exists yet |
| Unknown headings | Values ignored, only bounded heading metadata returned |
| Retention documentation | Current documents specify filtered serialization; historical migration 001 is explicitly superseded and unchanged |
| Cognito identity | Reordered records/columns, ignored-value changes, edited contact values, distinct submission times, and external-ID precedence tested |
| Timestamp handling | Naive/unconfigured timestamps unresolved; configured zone and explicit offset normalize to UTC; DST ambiguities rejected |
| Unknown tenure | Confirmed member starts with member_since=NULL; no financial/waiver date copied into it |
| Stripe consistency | Exact-ID conflict helper tested; supported and unresolved source relationships documented |
| Authoritative links | Confirm/reject/cache synchronization, manual precedence, relationship/member creation and audit atomicity tested |
| Currency/environment | Unknown currency deferred, lowercase constraint tested, account/livemode conflicts rejected |
| Secrets | No apparent real credentials in current project source; matches are placeholders, configuration names, password_hash, and documentation. No Git repository/history exists to audit |
| Hosting | Conventional PHP/PDO/MariaDB and cPanel cron only; actual hosting permissions/configuration still require deployment setup |

Results: **178 architecture assertions** and **16 migration assertions** passed. PHP lint passed for all project PHP files. Composer validation passed. No FLOAT/DOUBLE database columns exist; all 15 monetary columns use BIGINT minor units.

Tests use disposable bma_schema_test_* databases and generated temporary paths. Concurrency tests commit synthetic people, and migration tests create synthetic legacy records to prove safe upgrade/refusal behavior. These fixtures are not production imports. Local MariaDB validation files/logs are ignored by version control.

## Remaining configuration, not assumptions

- COGNITO_SOURCE_TIMEZONE is deliberately unset until confirmed. Missing timezone produces an unresolved timestamp, not a guessed value.
- CSV currency/account/environment gaps remain unresolved with warnings/review requirements until reliable evidence is available.
- Genuine Cognito entry IDs are absent. Fallback locator collisions and identifying-field edits need administrative review; the design does not promise impossible automatic identity recovery.
- Configure the private upload directory, actual public document root, operational cleanup age, and cPanel cleanup schedule at deployment.
- Show docs/import-field-mapping.md to BMA before any first real import. No CSV importer is implemented or authorized to run in this stage.

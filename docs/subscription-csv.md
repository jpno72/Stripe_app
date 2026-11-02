# Complete, duplicate-safe Stripe subscription CSV imports

Implemented/tested locally against baseline `38d2921` on 2026-11-01. No commit, push, production access, deployment, production migration or billing change was performed. Operator-reported production counts were not independently verified. The actual private `C:/Users/BloomMMA/Downloads/subscriptions(3).csv` was available and tested; it remains outside Git/public files. No names, emails, metadata values or individual monetary source cells are reproduced here.

## Verified source and outcomes

| Measure | Actual supplied file |
|---|---:|
| Columns / nonblank rows | 38 / 37 |
| Unique subscriptions | 29 |
| Single-item / two-item / three-item subscriptions | 22 / 6 / 1 |
| Modern price / legacy plan references | 36 / 1 |
| Distinct subscription + plan/price + product keys | 37 |
| Source currency / interval / status | usd / month / active on all rows |
| Quantity 1 / quantity 2 | 35 / 2 |
| Populated creation, both start fields and both period fields | 37 each |
| Inconsistent repeated subscription-level values | 0 |

`Start Date (UTC)` equals creation in this sample, but `Start (UTC)` differs from it on 21 rows. They are separate fields, not interchangeable aliases. Explicit Start Date maps to the source subscription-start field; Start is separately stored in csv_start_at. Neither establishes membership enrollment or a payment date. API equivalence of the export's older Start label was not established.

| Run | Read | Inserted | Updated | Skipped | Errors |
|---|---:|---:|---:|---:|---:|
| Fresh actual import | 37 | 29 | 8 | 0 | 0 |
| Identical bytes / renamed file | 37 | 0 | 0 | 37 | 0 |
| Reordered rows | 37 | 0 | 0 | 37 | 0 |
| Fresh validation-only dry run | 37 | 0 | 0 | 37 | 0 |

These existing counters describe **source-row outcomes**, not the sum of parent and item INSERT statements: the first unique row per new subscription is inserted; additional newly retained item rows update its item set. The first run creates exactly **29 subscription rows and 37 item rows**. Seven subscriptions retain multiple items. No normalization/reimport creates BMA people, members or identity links. Existing complete customers/stubs are reused by cus_ ID; absent profiles create only financial-account stubs.

All 37 source amounts/currencies/quantities match normalized item observations. Source amounts receive `SUBSCRIPTION_AMOUNT_UNRESOLVED`; MRR remains NULL rather than being fabricated. This warning is intentional, not an import failure. The export supplies no verified account/live-mode metadata; offline tests used an explicitly synthetic account/environment. Production must attest its actual established account/mode.

## Root causes and architecture

Previously, duplicate detection used only sub_ ID, so additional item rows were skipped. The importer wrote one `csv-single-item` row, discarded quantity/currency/product ID/useful dates, required price_ even for a valid plan_, and could overwrite existing amount/currency data with NULL. A single latest raw hash could not represent a multi-row subscription.

Subscriptions now have a separate processing branch: stream/read the complete source into existing **filtered approved staging**, then process one subscription group at a time. No original row serialization, new upload archive or whole-file memory load is introduced. Distinct sub_ groups may commit independently, but every parent/item/catalog/stub write within one group is a transaction. A failed item/customer/catalog check rolls back the complete group; unrelated valid groups can still succeed. Rows retain individual statuses/diagnostics and batch totals/history.

A structurally invalid sibling with a readable valid sub_ ID poisons its group even if it could not enter staging. Valid siblings are not silently committed as a complete subscription. Blank source records remain ordinary skipped blank rows. Groups are bounded at 1,000 staged rows / 8 MiB approved JSON; oversized groups are rejected with row diagnostics. The batch/external-ID staging index supports group lookups. Staging itself remains streaming. No server process, daemon or external service is required.

The existing import advisory lock, parameterized PDO access, private uploads/deletion, admin authorization, CSRF and one-account/environment guard remain. Attested subscription dry runs also reject a conflicting established account/environment without establishing a new context. File hash/duplicate_of metadata remains, but subscription groups always revalidate current normalized state rather than blindly trusting a previously imported file. Consequently a repaired partial import can complete missing groups and a manual correction cannot be overwritten through an old file-hash shortcut.

## Stable CSV item identity and ambiguity

The locator is `csv-item-v1-` plus SHA-256 of JSON `[original valid price_or_plan_identifier, product_identifier_or_null]`. Subscription scope is supplied by the existing unique `(subscription_id, source_locator)` constraint. No row number, file position, amount, quantity, name or email participates. Existing unique external subscription IDs and si_ IDs remain intact; no si_ ID is invented.

- Distinct candidate keys under one subscription are distinct observed items.
- Repeated identical approved content under one key is skipped with `DUPLICATE_SUBSCRIPTION_ITEM`.
- Different approved content under the same key is ambiguous and rejects the whole group, including differences that might be mere formatting. There is no last-row-wins policy.
- All repeated parent values, including cus_ ID, status, supplied lifecycle clocks/cancel flag/coupon/contact snapshots/currency, must agree after normalization. A conflict rejects the whole group.
- Unchanged items are not rewritten; order and filename changes do not change IDs or hashes.
- A new distinct item can be inserted once if every existing item remains represented and other facts are consistent.
- A missing previous key, changed plan/product key or old provisional `csv-single-item` cannot prove deletion/replacement. The whole update is held with `SUBSCRIPTION_ITEM_SET_REVIEW_REQUIRED`; no record is deleted/rekeyed or duplicated beside an unresolved predecessor.

Two genuinely separate items with identical plan/product evidence cannot be distinguished without si_ IDs. The requested identical-row rule treats identical approved rows as duplicates, with a visible diagnostic; obtain API item IDs/reviewed evidence before representing two indistinguishable actual items. This is a source limitation, not something hashing resolves universally.

## Existing facts, freshness and provenance

New CSV-managed parent/items have a digest of their normalized relevant fields (`csv_state_hash`). Before an update, the current row must still match that digest. A manual change, unit-price reclassification or other correction blocks automated replacement, even when incoming raw hash/file is unchanged. Existing non-CSV rows with NULL digest are protected and require reviewed reconciliation. Migration 011 does **not** manufacture digests for historical records or reclassify old amounts/items.

An empty incoming cell never clears a known value. Previously NULL facts may be filled after all group checks. Different populated scalar values, quantity changes, cancellation/status changes or newer-looking period dates require `SUBSCRIPTION_REVIEW_REQUIRED`, because this export has no reliable snapshot-updated timestamp. Creation/start dates, file names/order, upload time and file mtime do not establish freshness. This intentionally includes otherwise plausible later changes within the same billing period. No force flag/review writer is added by this task; resolve changed snapshots through a separately reviewed process or future authoritative synchronization.

Synced parent records cannot receive CSV changes. A completely unchanged previously CSV-managed synced record may remain a no-op; API-linked si_ items and unrecognized/provisional provenance require review. Catalog price/product facts are never rewritten; existing non-NULL price→product/currency contradictions reject the group. Parent/item/catalog rows are locked where needed and database unique constraints prevent duplicate creation. Member assignments, accepted/rejected identities, waivers/emergency data, existing payment transactions, verified fees and audit history are not edited by subscription import.

The old single provisional item is deliberately preserved, not silently converted. The operator-reported production inventory has zero subscriptions/items, so this limitation should not affect its initial import; verify that preflight. An installation with existing provisional rows needs a reviewed reconciliation before replacing their item sets.

## Legacy plans and billing semantics

A valid price_ reference uses the existing stripe_prices FK. A valid plan_ reference is stored **unchanged in stripe_subscription_items.legacy_plan_id**, with price_id NULL. Both modern and legacy items may reference the exact supplied Product ID via a new nullable product FK. There is no fabricated price_ conversion or claim that a legacy plan is a verified modern Price. Unsupported/malformed identifiers are rejected. Legacy Plan IDs outside the explicitly supported plan_ format require a future reviewed contract, not permissive guessing.

Stripe's [Plan object](https://docs.stripe.com/api/plans/object) describes a separate plan identifier and per-unit/tiered billing semantics. [Subscription items](https://docs.stripe.com/api/subscription_items/object) have their own IDs, prices and quantities, and current API versions can carry item-level periods. [Multiple-product/quantity guidance](https://docs.stripe.com/billing/subscriptions/quantities) confirms multiple items per subscription. These API facts do not establish the exact Dashboard CSV Amount contract.

No authoritative evidence was established for whether this CSV Amount is per-unit, item-total, tax/discount-inclusive, or a reliably equivalent charge. Therefore:

- Store the approved source amount as exact integer currency minor units only when its source currency exists; retain original strings in filtered staging.
- Set new item amount_basis to `csv_unresolved`. Do not multiply quantity or infer a unit price/whole-item total.
- Preserve positive integer quantity and supplied currency/frequency. No absent interval_count is invented.
- Explicit zero remains zero; missing values stay NULL; invalid decimal precision/overflow/negative money are errors. No float conversion occurs.
- Do not populate parent amount from a single row or sum subscription items into paid revenue.
- The item-rate view explicitly returns NULL for unresolved-basis monthly equivalent, even if a linked verified price supplies interval/currency. Existing unit/csv_reported calculation rules remain unchanged.
- Real transaction totals remain transaction-grain and independent of subscription observations. New items do not create payments or allocate household/member revenue.

All supplied shared billing periods are consistent, so they can populate existing subscription period fields. Future mixed item-level periods that cannot be represented by the current coherent-parent CSV contract are rejected for review rather than collapsed arbitrarily. Trial timestamps, tax/fee settings, cancellation free text and metadata are not newly retained. Explicit cancel-at-period-end and ended-at are separate from canceled status/date; no lifecycle dates are invented.

## Privacy and field retention

Only nine necessary subscription headings were added: Product ID, Quantity, Currency, both Start fields, both Current Period fields, Cancel At Period End, Ended At. Of the 38 actual columns, 21 are approved and 17 ignored. The complete table is in [import-field-mapping.md](import-field-mapping.md#stripe_subscriptions). Customer Description, cancellation feedback/reason, tax/fee configuration, workflow metadata and all unknown values remain excluded. Existing approved name/email snapshots remain snapshots, never identity matching.

The database raw JSON CHECK and ApprovedRecord enforce the same approved keys. Diagnostics contain fixed codes/messages, batch and row numbers, not identifiers, names, emails, amounts, raw JSON or exception details. Unknown header names are bounded metadata only. No payment, customer or Cognito whitelist was changed. No new public page or asset is part of this task.

## Migration 011

New file `sql/migrations/011_subscription_csv_items.sql`:

- Adds nullable parent state digest, distinct csv_start_at, cancel_at_period_end and ended_at.
- Adds nullable item legacy_plan_id, direct product FK and state digest, with appropriate indexes/checks.
- Adds raw `(import_batch_id,source_record_id)` lookup index and extends only the subscription raw whitelist.
- Adds the explicit csv_unresolved amount basis without reclassifying existing rows.
- Replaces only vw_subscription_item_rates to block unresolved amounts and expose direct item products at unchanged grain.

No PK, existing FK/unique constraint, existing record or migration 001–010 was removed/modified. Existing historical amount bases remain as-is. sql/views.sql and the consolidated snapshot contain the same view/structural changes. The existing migration runner must apply 011; never run schema.sql against an existing database. MariaDB DDL implicitly commits, so partial migration failures require review and must not be hidden by editing history or force retry.

## Tests and actual results

`tests/subscription-csv.php` passed **74 focused checks**, including the actual file and synthetic edge cases. It builds a populated pre-011 fixture from the immutable 38d2921 snapshot, applies only 011, verifies preserved IDs/money/assignments, and compares with a fresh current snapshot. Historical migration files are checksum-checked, not rerun. Disposable local databases and private fixture files are removed afterward.

Existing suites passed in that same disposable current-schema environment:

| Suite | Result |
|---|---|
| tests/imports.php | PASS: streaming/duplicates/retention/private temporary cleanup |
| tests/reporting.php | PASS: 10 checks |
| tests/stripe-transaction-identifiers.php | PASS: 78 checks |
| tests/stripe-guest-customers.php | PASS: 87 checks |
| tests/membership.php | PASS: 19 checks |
| tests/revenue.php | PASS: 21 checks |

The revenue test now resolves its fixture BMA IDs through explicit accepted relationships instead of hard-coded AUTO_INCREMENT IDs. Its runtime repository/calculations are unchanged. An initial run against an older existing database failed that test's unresolved-MRR assumption because its three active fixture subscriptions were fully resolved and it did not contain the new view. The final run used explicit current-schema reporting/unknown-fee fixtures and passed all assertions. The older tests/schema.php and tests/migrations.php hard-code pre-auth object counts/five migrations; the focused populated-011/snapshot checks replace those obsolete whole-project assumptions for this task, rather than changing unrelated historical suites.

Coverage includes first/identical/renamed/reordered import; complete dry-run rollback; per-group and partial-file retry; identical/conflicting item duplicates; conflicting customers and price/product relationships; invalid/legacy identifiers; quantities/zero/precision/overflow; blank fill versus blank preservation; old snapshots; new/omitted/replaced items; statuses/scheduled cancellation; protected synced/manual records; account conflict; ignored/unknown-field suppression; no automatic identities; no financial transaction multiplication. All original source quantities/currencies/amount observations are checked by their per-item raw association.

Final verification: all eight changed/new PHP files passed PHP 8.2 lint; git diff --check passed. Baseline hashes confirm migrations 001–010, all public/auth/configuration/identity/customer/transaction/Cognito/emergency/profile implementations and ApprovedRecord filtering are unchanged. No CSV or credential-shaped key was added. Only the documented subscription runtime, schema/view, tests and documentation files changed.

### Exact local commands (PowerShell)

Use existing local PHP 8.2/PDO MySQL/mbstring and MariaDB. Tests require development-only privileges to create/drop randomly named bma_schema_test_subcsv_* databases and Git history containing baseline 38d2921. No production credentials belong in tests.

```powershell
$files = @('app/Import/ImportService.php','app/Import/StripeSubscriptionImporter.php','app/Import/StripeSubscriptionBatch.php','app/Import/bootstrap.php','config/import-mappings.php','src/Retention.php','tests/subscription-csv.php','tests/revenue.php')
foreach ($file in $files) { php -l $file; if ($LASTEXITCODE -ne 0) { throw 'PHP lint failed' } }
$env:BMA_SUBSCRIPTION_DB_TEST = '1'
$env:BMA_SUBSCRIPTION_EXPORT = 'C:\Users\BloomMMA\Downloads\subscriptions(3).csv'
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '33317' # Replace with the existing local test server port.
$env:DB_USERNAME = '<LOCAL_TEST_USER>'
$env:DB_PASSWORD = '<LOCAL_TEST_PASSWORD>'
php tests/subscription-csv.php
git diff --check
```

The focused command invokes the six relevant existing suites automatically with generated private config, synthetic account/environment, fixtures and a disposable schema. It does not access production or rebuild existing databases. Transactional dry-run may consume AUTO_INCREMENT sequence numbers before rollback; gaps are acceptable and no normalized rows persist. No production billing/API requests occur.

## Exact change inventory

Runtime PHP (private deployment only):

- Modified app/Import/StripeSubscriptionImporter.php.
- Modified app/Import/ImportService.php.
- Modified app/Import/bootstrap.php.
- New app/Import/StripeSubscriptionBatch.php.
- Modified config/import-mappings.php (subscription mapping only).
- Modified src/Retention.php (fixed diagnostics only; ApprovedRecord unchanged).

SQL:

- New sql/migrations/011_subscription_csv_items.sql.
- Modified sql/schema.sql (fresh installations only).
- Modified sql/views.sql (item-rate view only; actual upgrade is through 011).

Tests:

- New tests/subscription-csv.php.
- Modified tests/revenue.php (explicit fixture identity lookup only).

Documentation:

- New docs/subscription-csv.md.
- Modified docs/imports.md.
- Modified docs/import-field-mapping.md.
- Modified docs/reporting-definitions.md.
- Modified docs/database-schema.md.

Public files/assets: **none**. No changes to authentication/authorization, config secrets, .htaccess, deployment bootstrap, Cognito/emergency recovery, identity matching, transaction/customer importer implementations, member profiles or payment calculations.

## Controlled production deployment plan

No following production step has been executed. Live customizations are authoritative; the local baseline is not proof of live equality. Do not git pull/reset or overwrite the public web root. Keep the original CSV private and byte-for-byte intact, and use the established account/environment rather than guessing from prefixes.

1. **Read-only preflight:** review actual server differences for the six runtime paths, new migration and item-rate view; retain server-only edits. Verify reported zero subscriptions/items and established source identity, and inventory existing counts/assignments before assuming an empty import target. Resolve existing provisional/API-managed subscriptions before attempting their CSV updates.

```sql
SELECT COUNT(*) AS subscriptions FROM stripe_subscriptions;
SELECT COUNT(*) AS items FROM stripe_subscription_items;
SELECT COUNT(*) AS assignments FROM subscription_members;
SELECT COUNT(*) AS transactions FROM stripe_transactions;
SELECT COUNT(*) AS waivers FROM cognito_waivers;
SELECT stripe_account_id,stripe_livemode FROM stripe_source_identity WHERE id=1;
SELECT version,checksum FROM schema_migrations ORDER BY version;
```

2. **Private backups; no business-data change:** pause imports/manual billing edits during the coordinated release. Back up the affected private files and a consistent MariaDB dump including triggers/data, outside public_html, and verify restoration. Preserve migration 001–010 bytes/checksums. Example commands require the host's verified executable/user/database names; -p prompts rather than exposing passwords:

```sh
umask 077
cd /home/bbmmacom/repositories/Stripe_app
BMA_PHP82=/opt/cpanel/ea-php82/root/usr/bin/php
"$BMA_PHP82" -v
BMA_BACKUP=/home/bbmmacom/bma-private/subscription-csv-backup-$(date +%Y%m%d-%H%M%S)
mkdir "$BMA_BACKUP"
tar -czf "$BMA_BACKUP/private-files.tar.gz" app/Import/StripeSubscriptionImporter.php app/Import/ImportService.php app/Import/bootstrap.php config/import-mappings.php src/Retention.php sql/views.sql sql/schema.sql sql/migrations
mysqldump --single-transaction --routines --triggers --default-character-set=utf8mb4 \
  -u '<CPANEL_DB_USER>' -p '<CPANEL_DB_NAME>' > "$BMA_BACKUP/database.sql"
```

Check command status/backup usability privately. If usual binary paths are unavailable, use verified host equivalents/cPanel backup; do not alter PHP handlers or unrelated applications.

3. **Private release staging:** manually merge only the six runtime files; add 011 and keep imports paused until it succeeds. Keep app/config/src/sql outside the web root. No public upload or asset change is needed. The expanded mapping/item basis requires 011 first before processing CSVs; do not resume imports midway through release.

```sh
for BMA_FILE in app/Import/StripeSubscriptionImporter.php app/Import/StripeSubscriptionBatch.php app/Import/ImportService.php app/Import/bootstrap.php config/import-mappings.php src/Retention.php; do
  "$BMA_PHP82" -l "$BMA_FILE" || exit 1
done
"$BMA_PHP82" bin/install.php --check --config=/home/bbmmacom/bma-private/config.php
```

Expected exactly 011_subscription_csv_items pending. Stop on checksum mismatch, partial/in-progress history, other pending migrations or unexpected inventory. Do not edit history. SQL files are UTF-8/LF; preserve checksum bytes. Never execute schema.sql against production.

4. **CHANGES PRODUCTION SCHEMA/VIEW, not existing business rows:** after reviewed backup/preflight, apply through the existing runner:

```sh
"$BMA_PHP82" bin/install.php --apply --config=/home/bbmmacom/bma-private/config.php
"$BMA_PHP82" bin/install.php --check --config=/home/bbmmacom/bma-private/config.php
```

Expected only 011 applied, then zero pending. Verify existing row counts/PKs, FKs, unique constraints, manual assignments and monetary facts remain unchanged. Stop for reviewed recovery on partial DDL failure; do not force retry or reapply old migrations.

5. **Validation-only import:** use the existing protected admin Imports page with Stripe Subscriptions, dry-run and the actual established account/mode. Or use the existing CLI with private config via environment (not an unsupported --config flag):

```sh
export BMA_CONFIG_FILE=/home/bbmmacom/bma-private/config.php
BMA_SUBSCRIPTION_CSV='/home/bbmmacom/bma-private/subscriptions(3).csv'
BMA_STRIPE_ACCOUNT='<ESTABLISHED_ACCOUNT_ID>'
BMA_STRIPE_MODE='<0_OR_1_MATCHING_DATABASE>'
"$BMA_PHP82" bin/import.php --source=stripe_subscriptions --file="$BMA_SUBSCRIPTION_CSV" \
  --stripe-account="$BMA_STRIPE_ACCOUNT" --stripe-live="$BMA_STRIPE_MODE" --dry-run
```

Expected if preflight is still empty: 37 read/skipped, zero errors, no normalized writes; 37 amount-semantics warnings. Review diagnostics and protected dashboards before authorizing actual import. No key is required or exposed. Dry-run retains its normal batch/approved staging/diagnostic history; it is not zero database writes globally.

6. **CHANGES PRODUCTION SUBSCRIPTION/CATALOG DATA:** only after operator review, run the same command without --dry-run (or an explicitly approved admin upload). This is not automatic deployment authorization. Expected on an empty target: 29 new parents, 37 items, 29 inserted/8 updated source-row outcomes, zero errors; any absent customers become profile-free stubs by cus_ ID. Existing production inventory/overlap can change counters, so reconcile rather than force expectations. No people/members, payments, member assignments or waivers should change.

7. **Read-only reconciliation:** inspect the new batch's counters and aggregate the normalized scope through its raw foreign keys, not by joining every one-to-many table together:

```sql
-- Use the actual approved import batch ID in your authorized database console.
SET @bma_batch = <ACTUAL_BATCH_ID>;
SELECT row_count,inserted_count,updated_count,skipped_count,error_count,status
FROM import_batches WHERE id=@bma_batch;
SELECT COUNT(*) AS newly_observed_parents FROM stripe_subscriptions s
JOIN raw_stripe_subscriptions r ON r.id=s.latest_raw_id WHERE r.import_batch_id=@bma_batch;
SELECT COUNT(*) AS newly_observed_items FROM stripe_subscription_items i
JOIN raw_stripe_subscriptions r ON r.id=i.latest_raw_id WHERE r.import_batch_id=@bma_batch;
SELECT COUNT(*) AS legacy_items FROM stripe_subscription_items WHERE legacy_plan_id IS NOT NULL;
SELECT COUNT(*) AS unresolved_rates FROM vw_subscription_item_rates
WHERE amount_basis='csv_unresolved' AND monthly_equivalent_minor IS NULL;
```

On the stated empty production target, these parent/item counts should be 29/37, including one legacy item and seven multi-item subscriptions. Unchanged reimports intentionally do not move latest_raw pointers, so these queries describe newly observed rows for the first import, not every subsequent batch's scope. Check quantities/currencies/dates using authorized private records. Compare before/after transaction totals, waiver/emergency data, identities and assignments; subscription import must not change them. Confirm unknown MRR stays unavailable. A deliberate reimport should skip all 37 with no duplicates.

## Rollback and remaining limits

Before any subscription import, restore only backed-up affected runtime files if needed and leave the additive migration/history intact. Once 011 is applied, keep its file available to the migration runner; deleting its file/history would trigger unknown-migration protection. The old importer is **unsafe for full multi-item files**, so pause subscription imports if reverting runtime code.

After an import, code rollback does not reverse data. Use private backups/raw batch provenance to design a separate reviewed compensating operation, preserving subsequent associations, items, payments and audit/import history. Do not blindly delete batch-linked catalogs/stubs, reset IDs, restore a whole database over later activity, drop new columns or edit schema_migrations. The new view safely preserves existing unit/csv_reported behavior; no automatic destructive rollback is provided.

Remaining source limits: no si_ IDs, reliable snapshot update clock, verified CSV Amount semantics, explicit interval_count or universally authoritative older Start meaning. Replacements/removals, conflicting states and pre-existing provisional/API-managed records need review; no CSV force/reconciliation UI is implemented. Identical legitimate items sharing all available evidence cannot be proven distinct. Future mixed per-item billing periods require a richer verified contract. Production file differences and results are still unverified because production was not accessed.

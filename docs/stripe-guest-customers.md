# Unified Customers: regular customers and Dashboard guest groups

## Evidence and semantics

The former `stripeId($id, 'cus_', true)` call rejected every gcus_ export row. The actual `unified_customers(3).csv` contains 51 unique full IDs: 31 cus_, 20 gcus_, and six normalized emails present in both groups.

Stripe's [Guest customer FAQ](https://support.stripe.com/questions/guest-customer-faq?locale=en-GB) describes guest views as Dashboard-only groupings of existing payments based on shared card, email or phone. One shared signal can group payments from different people; grouping can change when the details are reused. The observed gcus_ export records are treated as those Dashboard groups, using the export as primary evidence for its identifier prefix. This is not a normal Customer API identity and cannot establish one individual or payer.

No official guarantee of permanent gcus_ ID stability across regrouping, export regeneration, accounts or environments was established. The full source ID is a safe exact-record import key within the database's verified account/environment; identical IDs update/skip, different IDs remain distinct. No contact-based deduplication or inference of replacement IDs occurs. API retrieval of gcus_ was not tested; no documented Customer API capability for these Dashboard-only groups was established, and the application deliberately excludes them from regular Customer/API paths.

A grouping describes Dashboard payment context; it does not establish a customer_id foreign key in this application. Shared email/name/card data never associates a guest group with a regular customer, member, payment or subscription. Card identifiers remain ignored and are not retained. No source transaction-to-guest mapping is invented.

## Storage decision: separate guest records (Option B)

Migration `008_stripe_guest_customers` adds one InnoDB/utf8mb4 table. Keeping guests out of stripe_customers protects existing matching, review, ownership and future API consumers without adding guest filters throughout the application.

- `stripe_guest_customer_id`: ASCII-binary VARCHAR(255), unique full gcus_ export key, with a guest-prefix CHECK.
- `record_type`: persisted `dashboard_guest` classification, with a CHECK preventing another classification.
- Approved name/email/address snapshot fields: group descriptions/contact context, not a verified person's demographics.
- `export_created_at`: approved Created (UTC) normalization; not the creation date of a regular Customer API object. Original source value remains in staging.
- `payment_count`: exported group count, not a new financial ledger.
- Summary minor-unit/currency columns: remain NULL while source currency is unknown. Original approved summary strings remain in staging; warnings are preserved.
- `latest_raw_id`: FK to raw_stripe_customers, plus its normal import batch/source metadata.
- Internal primary key and UTC created/updated timestamps.

There is no person_id, member_id, regular customer_id, subscription or payment ownership field in this table. Transaction/subscription customer_id foreign keys continue targeting stripe_customers. Matching, profiles, authoritative associations and reporting continue reading regular customers only. Guest groups are not offered for manual confirmation in the existing review queue.

`stripeCustomerExportId()` accepts only cus_/gcus_ and the established ASCII identifier character set, rejects missing/malformed/NUL/unsupported IDs, and retains the 255-character limit. Customer importer chooses storage by the verified prefix. AbstractImporter gains only the fixed guest lookup target. Generic stripeId, transaction ch_/py_ validation and cus_-only transaction/subscription references are unchanged.

The CSV allowlist and its hash are unchanged. Its destination annotations describe regular customers; guest rows route the same approved fields to the corresponding isolated columns at runtime. Ignored and unknown source values remain excluded. Both classes use the same safe raw customer staging, content hashes, row transactions, diagnostics and import history.

## Migration

`sql/migrations/008_stripe_guest_customers.sql` is additive: it creates the isolated table and does not alter existing tables, identities, IDs, source rows, monetary values or reporting views. Migrations 001–007 and their checksums are unchanged. `sql/schema.sql` includes the same new table and migration checksum for current complete-schema initialization. Do not execute that complete snapshot over an existing database.

The existing migration mechanism was used on an existing seven-migration disposable database. Applying 008 succeeded; a subsequent check had no pending migration. Existing customer/member/payment/subscription/link/candidate/audit row hashes were unchanged. The canonical snapshot and migration contain the same guest CREATE TABLE definition.

## Focused validation — 2026-10-12

**140 focused checks passed** (`php tests/stripe-guest-customers.php`) using a guarded disposable database; four affected PHP files passed lint, and git diff --check passed.

Coverage: accepted/rejected IDs and length/NUL checks; distinct same-suffix prefixes; strict unrelated/reference validators; dry-run versus actual imports; partially successful import retry; duplicate rows and renamed/reordered files; updated/invalid guest content; same-contact regular/guest and multiple guests; missing contacts; persisted classification; unchanged regular associations/manual decisions; guest exclusion from matching and authoritative review; rejected guest transaction/subscription references; unchanged revenue rows/completeness; source attestation and account/environment conflicts; sensitive/unknown exclusion and safe logs. Temporary fixtures and synthetic audit records were removed, source context restored and temporary diagnostics deleted. Existing protected records matched their initial hashes after cleanup.

Actual export dry-run (independently validated, no production import):

| Result | Count |
|---|---:|
| Source rows | 51 |
| Regular cus_ | 31 |
| Guest gcus_ | 20 |
| Valid/skipped | 51 |
| Identifier errors | 0 |
| Total errors | 0 |
| Currency warnings | 51 |
| Normalized inserts/updates | 0 |
| Unexpected identity links | 0 |

The customer summary fields do not establish currency. They remain unresolved; their values are never summed into revenue. Guest spending summaries cannot duplicate existing transaction-grain revenue. Dry-run counts intentionally show skipped rows because normalized rows are not written.

## Exact changed files

Production PHP:

1. `app/Import/Normalizers.php` — new customer-export-specific validator only.
2. `app/Import/StripeCustomerImporter.php` — route regular/guest rows to their isolated tables.
3. `app/Import/AbstractImporter.php` — fixed guest-table lookup allowlist entry.

Database:

4. `sql/migrations/008_stripe_guest_customers.sql` — new additive migration.
5. `sql/schema.sql` — current complete-schema snapshot includes that table/checksum.

Support:

6. `tests/stripe-guest-customers.php`
7. `docs/stripe-guest-customers.md`
8. `docs/database-schema.md`
9. `docs/imports.md`

No transaction/subscription importers, source field mapping, bootstrap, .htaccess, authentication, authorization, CSRF, upload/security mechanisms, identity services, timezone logic, financial reports/views or historical migrations changed.

## Minimal split-cPanel deployment

Do not git pull/reset production or overwrite manually maintained server files. Review/merge the three narrowly scoped private PHP changes into the live versions beneath `/home/bbmmacom/repositories/Stripe_app/`, preserving server-specific fixes and the ch_/py_ transaction validator. Add only the new 008 migration file to that project's sql/migrations directory. No public files or private credentials change.

Using the existing private configuration, check pending migrations first:

```sh
php /home/bbmmacom/repositories/Stripe_app/bin/install.php --check --config=/home/bbmmacom/bma-private/config.php
```

For an existing current installation, the only pending version should be `008_stripe_guest_customers`. If history/checksum/inventory checks fail or older migrations are pending, resolve that specific condition before applying; do not bypass the checks. Apply using the existing mechanism:

```sh
php /home/bbmmacom/repositories/Stripe_app/bin/install.php --apply --config=/home/bbmmacom/bma-private/config.php
```

Apply 008 before enabling the new customer importer. The updated importer requires the guest table even for guest dry runs. Do not execute schema.sql over production. Refresh private PHP code through the host's normal deployment/opcache procedure, then dry-run the 51-row CSV. For an operator-authorized actual retry, provide the verified acct_ account and explicit Live/Test setting. Exact-ID/content checks preserve existing successful regular rows and insert only missing guests; code deployment itself performs no retry or import. No automatic deployment, production migration or production data changes were performed.

## Remaining limitations

Guest grouping stability and Customer API retrieval are not promised. Newly generated IDs remain separate groups; no cross-ID/email reconciliation is attempted. Guest groups have no automatic BMA identity, transaction attribution, API synchronization or admin match-review support. Adding any such capability requires verified mapping evidence and an explicit future design. Currency summaries remain unresolved; new currency assumptions are not introduced. This change imports approved group snapshots, not a proof of 51 distinct people.

## Reproducing tests

Pure identifier checks require PHP only. Database checks require BMA_GUEST_TEST_DATABASE with the bma_schema_test_ prefix and DB_HOST/DB_PORT/DB_USERNAME/DB_PASSWORD from runtime settings. They apply only 008 to an existing seven-migration disposable database, require existing member/admin fixtures, and clean up their unique test records. Optional BMA_GUEST_TEST_EXPORT enables the actual export dry-run. Never use a production database for these tests.

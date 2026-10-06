# BMA Analytics database — current schema

## Scope and status

The current schema has 25 InnoDB tables, 33 foreign keys, 21 additional unique indexes, six triggers, and 14 reporting views. Production runtime is PHP 8.2 with PDO and MariaDB 10.4+ on conventional shared cPanel hosting. Choose a maintained MariaDB release offered by the host. MySQL is not a validated target for the new migrations; the runner explicitly requires MariaDB.

Stages 2–4 add CSV import, identity matching, and administrator review. Stage 5 adds reporting views. Stages 6–10 add protected dashboards and member profiles. Stage 11 adds application authentication. Stripe API synchronization and production imports are not enabled.

## Authoritative artifacts

- sql/migrations/001_initial_schema.sql is unchanged, including its original checksum.
- sql/migrations/002_step1_contract.sql makes the reviewed retention and identity corrections.
- sql/migrations/003_minimize_stripe_fields.sql removes payment-method references and unneeded customer descriptions.
- sql/migrations/004_identity_match_candidates.sql adds explainable cross-source candidates and evidence columns.
- sql/migrations/005_reporting_views.sql creates reporting views with explicit financial attribution rules.
- sql/schema.sql is the consolidated current schema, generated from the migration result. It is no longer a duplicate of 001.
- sql/views.sql is a standalone copy of the reporting view definitions; docs/reporting-definitions.md defines their metrics and limits.
- config/import-mappings.php is the approved source-field policy.
- docs/import-field-mapping.md contains all 64 actual source headings and their dispositions.
- docs/imports.md defines processing, consistency, errors, identity authority, and temporary-file rules.
- docs/cognito-field-meanings.md defines field ambiguity, timezone handling, and the exact deduplication algorithm.

Historical migrations retain their original definitions because applied migrations must remain immutable. Fresh installations apply all five migrations. Do not deploy an earlier migration alone as the current schema.

## Source evidence

Reinspected four supplied exports: customers has 14 headings and one populated record; subscriptions 13 and one; transactions 23 and two; Cognito 14 and one. Empty record counts are 46, 0, 34, and 349 respectively. No source files or personal example values are copied into the repository.

Stripe provides cus_, sub_, price_, ch_, in_, pm_, card_, and po_ identifiers in the observed fields. Card/payment-method identifiers are ignored under the approved mapping. Product is a display name. No actual product ID, subscription-item ID, payment-intent ID, account ID, or livemode is supplied. Subscription/customer summary currency and fee currency are not reliably established.

Cognito has no entry ID or timezone. Name's role and contact ownership are ambiguous. These facts require unresolved values and review, not invented associations.

## ER-style explanation

```text
people 1 ── 0..1 members
people N ── N people through person_relationships
people 1 ── N stripe_customers, assigned through authoritative identity_links
stripe_customers 1 ── N stripe_subscriptions
stripe_subscriptions 1 ── N stripe_subscription_items
stripe_subscription_items N ── 0..1 stripe_prices
stripe_prices N ── 0..1 stripe_products
stripe_subscriptions N ── N members through subscription_members
stripe_customers 1 ── N stripe_transactions (customer nullable on a charge)
stripe_subscriptions 1 ── N stripe_transactions (optional enrichment)
people 1 ── N cognito_waivers in independent signer and student roles
people 1 ── N identity_links for external-record role decisions
stripe_customers N ── N cognito_waivers through identity_match_candidates (review suggestions only)
import_batches 1 ── N raw_<source> and import_errors
normalized source records N ── 0..1 corresponding raw record via latest_raw_id
app_users 1 ── N reviews, audits, batch creators, and resolutions
stripe_source_identity: one account/environment pair per database
```

A payer is a person in a payer relationship, not a duplicate identity record. Jane may own the billing customer while Johnny has the member record. Their email and phone indexes are deliberately nonunique.

All 30 FKs use ON DELETE RESTRICT and ON UPDATE RESTRICT. Referenced internal IDs consistently use BIGINT UNSIGNED. No cascading deletion removes import provenance, people relationships, or audits.

## Table responsibilities

| Table | Responsibility |
|---|---|
| schema_migrations | Checksummed successful migrations and detectable in-progress markers |
| people | Immutable internal/public identity and optional personal details |
| members | Actual participants; factual status and dates |
| person_relationships | Directed parent/guardian/payer/child/spouse/self relationships |
| stripe_customers | Billing-party snapshots, stub support, accepted-person cache |
| stripe_subscriptions | Customer-owned subscription lifecycle; optional aggregate monetary snapshot |
| stripe_subscription_items | Multiple item/price references, quantities, billing intervals and item amounts |
| stripe_prices | Unique Stripe price; optional product reference and catalog amount |
| stripe_products | True product IDs and catalog snapshots |
| stripe_transactions | Unique charges, nullable source associations, actual source money |
| stripe_source_identity | Singleton stripe_account_id/stripe_livemode, initially unresolved |
| cognito_waivers | Approved source snapshots, distinct person roles, stable locator/content hash |
| identity_links | Authoritative accepted/candidate/rejected external-record role assignments |
| identity_match_candidates | Scored Stripe-customer/waiver suggestions with code-only evidence and review status |
| import_batches | File/batch metadata, source identity evidence, lifecycle and counters |
| raw_stripe_customers | Approved customer-field JSON only |
| raw_stripe_subscriptions | Approved subscription-field JSON only |
| raw_stripe_transactions | Approved charge-field JSON only |
| raw_cognito_waivers | Approved waiver-field JSON only, optional locator/content hash |
| import_errors | Structured diagnostics without a source-row payload |
| app_users | Password hashes, authorization roles, active status |
| audit_log | Application append-only operational decisions with minimal metadata |
| settings | Nonsecret application settings |

## Filtered raw serialization

Preserve the original source value of approved fields. Do not preserve ignored or unknown fields.

raw_values stores an object keyed by whitelisted source headings. There is no current raw_record blob. Ignored keys and unknown keys fail database CHECK constraints in all four staging tables. Original strings of approved fields remain intact; absent approved fields are NULL. Each row hash derives from normalized approved content, not the unfiltered CSV row.

import_errors has no raw_values field. The diagnostics service accepts fixed codes plus batch/row identifiers and never accepts an arbitrary row, exception, or free-form message. Audit metadata from the identity service is limited to internal IDs, role/relationship enums, and operation flags.

Unknown column names may be retained as header metadata; unknown cell values may not. Source file checksums are batch integrity metadata, not person/waiver identity. Uploaded originals are transient private files, explicitly deleted on success/failure with abandoned-file cleanup. They are not archived.

Customer Description and Card ID from the customer export, Customer Description from subscription and transaction exports, and Card ID from transactions are IGNORE. The corresponding normalized columns are absent. Transaction Description remains approved because its observed "Subscription update" value can help classify and reconcile a payment; it must still be treated as sensitive free text.

Cognito considerations, goals, Emergency Contact, and Emergency contact Phone are IGNORE. The corresponding normalized columns are absent. Retaining them later requires an explicit BMA decision and a mapping/schema/access review. No fixed waiver-data retention period has been selected.

## BMA identity allocation and immutability

People::create uses one transaction:

1. INSERT a person with no public ID.
2. Read that connection's database-allocated AUTO_INCREMENT ID.
3. Set the public ID to BMA- plus that number padded to at least six digits.
4. Commit only after the public ID has been assigned.

127 becomes BMA-000127. Values above six digits are never truncated. There is no MAX(id)+1, row counting, random-ID fallback, or custom sequence table. Gaps are expected. The unique public-ID index remains in place.

The temporary NULL public ID exists only inside the service transaction; application code must never insert people through another path or commit provisional rows. MariaDB has no deferred commit-time CHECK for this two-statement allocation. Other connections do not see the service's provisional row. Direct database-owner writes remain administrative responsibility.

The insert trigger refuses explicit internal/public identities for new rows. The update trigger permits only initial assignment derived from the allocated PK and raises SQLSTATE 45000 for later changes to either immutable identifier. Existing public IDs from 001 remain unchanged on upgrade, even if an old format differed.

People::rejectIdentifierChange writes a minimal audit event and returns an explicit application error. Direct SQL trigger failures cannot persist an audit row in the same rolled-back transaction; callers must use the service boundary. No person-deletion/recycling API is provided.

## Authoritative identity associations

IdentityAssociations is the sole application writer for link decisions and cached person FKs. Manual operations require an active admin. The service locks the external record, updates accepted/candidate state, synchronizes cached FKs, applies explicitly confirmed member/relationship changes, and writes minimal audits in one transaction.

Manual confirmations and rejections override future automated assignments for that source role. Unique generated-key indexes permit multiple possible candidates but at most one accepted person per external record/role. An unmatched row has no person. Review statuses require reviewer identity and time.

Four customer/waiver triggers reject independent cache changes that disagree with accepted identity_links. Ordinary application code must never edit identity_links outside the service. Administrative SQL access can bypass application write policy and is not granted to application end users.

The same waiver can have Jane as signer and Johnny as student. Its Name is source_name until the original form meaning is verified. interpretation_status begins unresolved. It is not automatically verified by matching one role.

## Membership and dates

member_since and member_until are nullable factual dates. Unknown start dates stay NULL. stripe_created_at, stripe_subscription_started_at, submitted_at, and the derived first successful payment date are separate facts. A later estimate cannot overwrite a factual date.

subscription_members.valid_from is also nullable. A generated date key prevents duplicate unknown-start associations for the same subscription/member. Overlap rules for dated episodes remain application validation. Dashboards will show Unknown, not a fabricated start date.

## Subscription items

The subscription holds customer/lifecycle information. Item price/product label/interval fields moved to stripe_subscription_items. Migration 002 preserves a legacy subscription snapshot in a provisional csv-single-item row before dropping the moved parent columns.

Each item has a nullable unique stripe_subscription_item_id, a unique (subscription_id, source_locator), price FK, optional quantity, amount/currency, amount_basis, interval/count, and provenance. Product ID is reached through the price/product FKs, avoiding redundant inconsistent product references.

CSV supplies no si_ ID or quantity. Use one provisional slot for the single visible item, keep quantity NULL, and set amount_basis=csv_reported. The exported amount is not assumed to be a catalog unit price. A genuine API item uses its si_ ID and unit amount semantics. Multiple items can share the same price; there is no unsafe uniqueness constraint on subscription/price.

Step 12 must reconcile a provisional slot before creating equivalent API items. Multiple distinct CSV item rows without stable item IDs require review. Step 2 implements CSV normalization; API reconciliation remains future work.

## Money and Stripe identity

All 15 monetary columns use signed BIGINT minor units. No FLOAT/DOUBLE fields exist. Currency fields use lowercase three-letter ASCII codes with CHECK constraints. Amount fields that require an established currency cannot hold a normalized amount while currency is NULL. Unresolved source amounts remain as approved source strings in staging, with a warning.

The balance transaction additions store actual Stripe amount, fee, net, and currency separately from CSV financial snapshots. Fees are never calculated from assumed rates. Invoice/payment-intent/balance-transaction IDs have nonunique lookup indexes where multiple related records may legitimately occur.

External Stripe IDs are strings with binary ASCII collation. Customer/subscription/charge/product/price/item IDs have appropriate unique indexes. A missing known customer can be an is_stub row. A missing customer ID remains unlinked. Email never overrides a conflicting Stripe ID.

stripe_source_identity begins with one unresolved row. Establishment is serialized and conflicting account/livemode is rejected by StripeContext. Actual CSVs do not identify environment/account. Batch metadata records reliable operator/API evidence only; unknown remains NULL and requires review before production inclusion.

## Indexes and constraints

The current schema has 24 primary keys, 21 additional unique indexes, and 33 foreign keys. Supporting indexes cover both directions of relationships, external identifiers, identity review queues, source locators/hashes, batch/row positions, status/date queries, and audit lookup.

People contact indexes are nonunique. Charges have unique charge IDs, but invoice/payment-intent/balance references are nonunique. Raw hashes and file hashes are nonunique. Staging batch/row positions are unique only within a batch and never serve as durable entity identity.

Current schema.sql is the executable inventory of columns, indexes, CHECK constraints, triggers, and views. Tests inspect database metadata and compare consolidated vs migrated definitions, rather than relying only on source text.

## Migration and cPanel deployment

Use MariaDB 10.4+ and PHP 8.2+, PDO MySQL, mbstring, and the Composer dependencies. The installed Stripe SDK is reserved for Step 12 and needs no key for schema installation.

Keep the project outside the public web root. Serve only public/. Copy config.example.php outside every served root and set BMA_CONFIG_FILE. Environment variables take precedence. No automatic dotenv loader is installed. Use a restricted Stripe read key when syncing is later enabled; keep all credentials out of settings and source control.

Create the empty database and database user in cPanel. Schema installation requires SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, TRIGGER, and CREATE VIEW privileges for this database, plus the host's normal FK support. The views use SQL SECURITY INVOKER, so the runtime account also needs SELECT on their underlying tables. CREATE DATABASE is needed only by local disposable-database tests, not by the installer. No root or server daemon is needed in production.

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
php bin/install.php --check --config=/home/CPANEL_USER/bma-private/config.php
php bin/install.php --apply --config=/home/CPANEL_USER/bma-private/config.php
```

The runner applies numbered migrations, verifies historical checksums/order/table inventory, and serializes migrations with a per-database advisory lock. The check mode reports pending migrations without creating tables; it does not prove DDL privileges or perform the legacy-data preflight. apply runs the data guards before 002 and 003.

DDL implicitly commits. An in_progress migration marker makes interrupted runs refuse blind retry. Inspect and recover manually; there is no force/reset option. Never modify an already-applied migration or drop a production schema to bypass a failure.

002 refuses existing legacy raw records, waiver records, or errors because silently discarding/rekeying them would violate the new contract. It also refuses customer-person caches without authoritative links. 003 refuses existing Stripe raw rows or nonempty values in columns it removes. Those cases need a reviewed data migration; they are not silently discarded.

For hosts without Terminal, import consolidated sql/schema.sql once into an empty database through phpMyAdmin. It includes the current migration ledger and no business records. Never import it over an existing installation. Existing installations use the migration runner. bin/build-schema.php is a development utility restricted to empty business tables in a disposable bma_schema_test_* database.

Configure IMPORT_TEMP_DIR, PUBLIC_WEB_ROOT, and IMPORT_ABANDONED_SECONDS, create the private directory, and schedule bin/cleanup-imports.php through cPanel cron. COGNITO_SOURCE_TIMEZONE remains explicitly unresolved until BMA confirms its source timezone. No fixed long-term data-retention policy is implied.

## Validation

The original [Step 1 validation record](step1-validation.md) documents its scope. With migration 004, the current schema checks pass 182 assertions and the migration/snapshot checks pass 16 assertions on the local MariaDB test environment.

The test suite covers storage/index/FK integrity, concurrency-safe IDs through eight independent PHP processes, explicit immutable-ID failures, family contact sharing, authoritative link transactions, manual-decision precedence, failed-operation rollback, multiple subscription items, missing currency, account/environment conflicts, approved-only staging, JSON-key rejection, diagnostic/log exclusion, naive/offset/DST timestamps, content/locator hashing, and upload cleanup.

Migration tests apply 001–005 to empty MariaDB, compare table, trigger, and view definitions with consolidated schema.sql, validate repeat installs, preserve a legacy subscription item and public ID, refuse legacy raw data without deleting it, and block interrupted migrations. Synthetic reporting tests check multi-item MRR, transaction grain, shared-payer attribution, age, and retention.

Run tests only against disposable bma_schema_test_* databases. They deliberately create synthetic records; concurrent identity services commit their allocations. Do not run them against production. The migration tests require a local account able to create disposable databases.

No production CSV was imported. Step 11 adds application login and administrator role checks; cPanel mappings are obsolete.

## References

The future item and balance model follows the [Stripe subscription item object](https://docs.stripe.com/api/subscription_items/object) and [balance transaction object](https://docs.stripe.com/api/balance_transactions/object). These references describe API enrichment, not authorization to call Stripe or import data now.


## Step 11 authentication migration

Migration 006 adds `app_users.auth_version` for session revocation and extends the role constraint with reserved `staff` (legacy `analyst` remains read-only). `auth_throttle` stores HMAC bucket keys, UTC window start and attempt count; its primary key is `bucket_hash` and normal index is `ix_auth_throttle_window(window_started_at)`. It has no person links or raw credentials. Account role/activity/password changes are audited in existing audit_log. No identity or reporting structures changed.

## Dashboard authorization migration

Migration 007 adds dashboards, role_dashboard_permissions and user_dashboard_overrides, with unique stable registry keys and composite permission primary keys. Overrides reference existing app_users; both permission tables reference dashboards. See [dashboard-access-control.md](dashboard-access-control.md) for defaults, safeguards and validation. Existing migrations and normalized business tables remain unchanged.

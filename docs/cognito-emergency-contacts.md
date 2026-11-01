# Native Cognito emergency contact restoration

Implemented locally on 2026-11-01 for PHP 8.2 / MariaDB 10.4+ and shared cPanel. No production access, deployment, production migration/backfill, GitHub push or server overwrite was performed. Production has migrations 001–009 according to the supplied operator report; this was not independently checked live.

## Source findings and local results

The actual private `BloomsburgMartialArtsAcademy2(7).csv` was available and tested. It remains outside the repository/web root. No personal cell values are reproduced here or committed.

| Aggregate | Observed |
|---|---:|
| CSV rows / unique valid native IDs | 1,074 / 1,074 |
| Source columns | 23 |
| Blank contact names | 65 |
| Blank contact phones | 58 |
| At least one missing emergency field | 74 |
| Both fields blank | 49 |
| Maximum populated name / phone length | 39 / 17 characters |
| Matched historical fixtures by exact native ID and namespace | 1,074 |
| Proposed/applied contact updates in disposable database | 1,025 |
| Unchanged all-blank waivers | 49 |
| Unmatched / failures | 0 / 0 |
| Repeated apply updates | 0 |

The historical test database was seeded from the original approved pre-010 raw contract and native IDs; it is not a copy or inspection of the production database. One fixture was explicitly associated/verified with a confirmed student link and rejected signer link before recovery. Those associations and all unrelated values remained unchanged. Live dry-run is still required because production may contain manual changes.

## Migration and field contract

`010_cognito_emergency_contacts.sql` adds nullable `emergency_contact_name VARCHAR(255)` and `emergency_contact_phone VARCHAR(100)` to `cognito_waivers`. It permits only `EmergencyContact` and `Phone2` in the native alternative of the raw JSON key CHECK. The legacy alternative is unchanged, including rejection of mixed legacy/emergency-native JSON. Considerations, Goals, workflow and unknown keys remain excluded. Existing raw objects still satisfy the expanded constraint.

No migration 001–009 was edited; their bytes/checksums remain unchanged. Existing indexes, foreign keys and identity triggers are unchanged. `sql/schema.sql` contains only the same constraint change, two columns and 010 history entry for future fresh installations. It is not a production upgrade script. MariaDB DDL implicitly commits; an interrupted migration must be reviewed, not force-retried or repaired by editing schema_migrations.

Only the native BMA2 mapping approves these optional fields. Both are sensitive, `identity_score=false`, `log_value=false`. They stay outside derived canonical student/signer profiles and are never used to create people, members, identity links or relationships. Legacy emergency headings remain ignored. Names/phones are not exposed by any new page/API, ordinary member profile projection, demographic/reporting view or search. Existing protected import administration and database access remain the only existing raw-data boundaries; no new general contact display is added. Access to private CSVs, backups and database exports must remain restricted to authorized operators. No retention period is invented.

New approved raw snapshots retain original strings after the existing lossless conversion. Normalized emergency values trim outer whitespace, preserve internal phone formatting/extensions and map blank/missing values to NULL. Overlength/control-character values fail rather than truncate. Windows-1252 conversion rejects undefined bytes/NUL and must round-trip exactly. Ignored cells remain ignored without decoding/serialization. Current UTF-8 validation finds five invalid approved rows, compared with four before emergency fields were approved; Windows-1252 validates all 1,074. The existing source timezone remains unresolved; no clock conversion is introduced.

## Hash compatibility and future imports

Approving two fields changes the native mapping hash and full approved content hash, including when those optional fields are blank. The legacy map and hashes remain unchanged. ImportService already scopes duplicate-file checks to mapping hash, namespace, encoding and source timezone, so the first new-contract upload is not mistaken for a fully processed old-contract upload.

`CognitoSource::preEmergencyContentHash` projects the current approved native record onto exactly the original 16 fields and applies the unchanged canonical hash algorithm. It is used only to recognize unchanged historical content, never as identity evidence or a replacement stored hash. The locator still derives only from exact effective namespace and native ID.

If that projection equals an existing waiver hash, the importer records `EMERGENCY_CONTACT_BACKFILL_REQUIRED` and skips normalization. A new filtered raw snapshot belongs to the new import attempt, but the historical raw row, normalized content hash, latest raw pointer, identity interpretation and associations are not rewritten. The first original-file reimport produced 1,074 such warnings and zero updates/errors; the next identical renamed upload used the normal duplicate batch guard and skipped all rows. Historical diagnostic history is not rewritten.

New waivers use the full 18-field hash and save contacts normally. Future repeated imports are idempotent. Existing non-NULL contact values cannot be silently replaced or cleared by import. Changed linked/reviewed/candidate-associated waivers retain the existing `WAIVER_UPDATE_REVIEW_REQUIRED` protection. Changes that need review are not resolved by this targeted recovery command.

## One-time recovery command

```text
php bin/backfill-cognito-emergency-contacts.php
  --config=/absolute/private/config.php
  --file=/absolute/private/BloomsburgMartialArtsAcademy2(7).csv
  --namespace=bma-waiver.BloomsburgMartialArtsAcademy2
  --batch=ORIGINAL_SUCCESSFUL_BATCH_ID
  --actor-user=ACTIVE_ADMIN_ID
  [--expected-rows=1074]
  [--dry-run | --apply]
```

The command defaults to dry-run and rejects simultaneous dry-run/apply. It is CLI-only; web execution returns 404. The service verifies an active canonical admin user, existing migration checksums/no pending migration, expected nullable column types and a private, readable absolute CSV path. The effective namespace must exactly equal the configured base plus `.BloomsburgMartialArtsAcademy2`; no alternative namespace is guessed.

The batch must be a successful non-dry-run native Windows-1252 import under the same base namespace, with exactly the expected rows/inserts and no updates/skips/errors. The original file SHA-256 must match that batch. This additional batch/hash pin prevents a different export being supplied merely because it reuses entry IDs. The supplied production import meets those reported criteria; live preflight must confirm them. Missing original batch metadata or incompatible schema stops recovery for investigation rather than changing history.

Each CSV row is streamed and filtered through the approved contract. Native IDs must be valid and unique. Matching uses **only exact native source_record_id plus exact form_namespace**; the stored native source kind/format and derived locator are checked. No email, name, phone, DOB, row-order or fuzzy matching occurs. Unmatched IDs, ambiguous/conflicting identities, unexpected counts, source/hash/namespace mismatches, invalid encoding or conflicting existing emergency values abort the entire operation. No waiver is ever created.

The two fields can be filled independently from NULL. Existing identical non-NULL values are unchanged. Any different non-NULL value, including a requested blank that would clear it, aborts the whole backfill; no force flag exists. Previously verified/associated waivers may receive these two missing contacts because the command does not normalize or alter their reviewed identity/source evidence. It does not overwrite unrelated waiver fields, raw snapshots/hashes, member/signer IDs, locators, relationships, decisions or links. Normal database updated_at advances on changed rows.

The import and migration advisory locks exclude concurrent imports/migrations during validation and writes. Apply locks selected rows and commits every update/audit atomically after full-file validation; failures roll back. Dry-run rolls back its read transaction and writes no audit. File locking plus before/after SHA-256 checks detect concurrent source modification. Limits are 100 MiB private CSV, 256 headings and at most 10,000 expected records. CSV parsing uses the same fgetcsv length setting as the existing importer. Only a bounded plan of emergency values/IDs is held; no whole source rows are retained by the command.

Audit contains admin/time, internal waiver ID, reason `native_emergency_recovery_v1`, original batch/file hash, namespace, changed field names and NULL-to-present transitions. It contains no contact values, hashes of individual contacts or raw rows. Existing audit history remains intact. CLI output is aggregate counters only; failures use a fixed safe error code without paths, SQL or contact details. `missing_emergency_contacts` counts rows with either emergency field NULL; `proposed_updates` counts waivers with any NULL-to-value change. Repeat successful apply produces zero writes/audits. The original operator-owned private CSV is never copied, archived or deleted by this CLI.

## Focused local validation

- The actual 1,074-row file matched all historical fixtures by native ID/namespace.
- Migration 010 applied to a populated pre-010 database and preserved every prior normalized value and raw record/hash. New columns started NULL; old migration checksums and zero-pending status passed.
- Dry-run wrote nothing; apply recovered 1,025 contact pairs/partial pairs and preserved 49 all-blank records. A second apply wrote nothing.
- Existing corrections, reviewed/linked waiver data, member/identity/relationship tables, historical batches/errors and raw records were preserved. Source timezone stayed NULL.
- Synthetic cases covered Windows-1252 punctuation/accents, undefined bytes, missing/blank fields, formatting/extensions, overflow, duplicate/invalid IDs, wrong namespace/hash/count, unmatched IDs, inactive/viewer actors, private path checks, CLI defaults and future import/reimport behavior.
- Ordinary profile projection excludes emergency fields. Mapping excludes scoring/logging; audit and operational diagnostics contain no emergency values. Raw CHECK still rejects unrelated/mixed keys.
- Fresh migrations and consolidated snapshot produce equivalent table definitions and valid migration tracking. No account-specific definers or new dependencies were added.

**Final result: 61 emergency-specific checks and 83 existing Cognito compatibility checks passed (144 total), including the actual CSV. All eight changed/new PHP files passed PHP 8.2 lint; git diff --check passed. The populated-migration and fresh-migration/snapshot comparisons passed. Baseline hashes verified migrations 001–009 and all public/auth/reporting/Stripe files were unchanged. The targeted secret scan found no credential-shaped keys in changed/new files.** `tests/cognito-emergency-contacts.php` runs only on explicitly enabled localhost disposable databases, drops its own generated database/fixtures, and invokes the relevant existing `cognito-compatibility.php` checks there. It does not rebuild existing databases or run a full project suite.

### Local commands (PowerShell)

Use PHP 8.2 with PDO MySQL/mbstring and an existing local MariaDB test server. Supply development-only DB credentials in your shell; never put production secrets into test files. The integration test requires permission to create/drop its own randomly named `bma_schema_test_emergency_*` database.

```powershell
$files = @('app/Import/CognitoWaiverImporter.php','app/Import/CognitoEmergencyBackfill.php','src/CognitoSource.php','src/Retention.php','config/import-mappings.php','bin/backfill-cognito-emergency-contacts.php','tests/cognito-emergency-contacts.php','tests/cognito-compatibility.php')
foreach ($file in $files) { php -l $file; if ($LASTEXITCODE -ne 0) { throw "PHP lint failed" } }
# Pure contract/normalization checks only:
$env:BMA_EMERGENCY_DB_TEST = '0'
php tests/cognito-emergency-contacts.php
# Enable the focused disposable integration checks:
$env:BMA_EMERGENCY_DB_TEST = '1'
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '33317' # Replace with your existing local test port.
$env:DB_USERNAME = '<LOCAL_TEST_USER>'
$env:DB_PASSWORD = '<LOCAL_TEST_PASSWORD>'
$env:BMA_COGNITO_TEST_EXPORT = 'C:\Users\BloomMMA\Downloads\BloomsburgMartialArtsAcademy2(7).csv'
php tests/cognito-emergency-contacts.php
git diff --check
```

## Production deployment plan — operator-controlled

The live server is authoritative; compare against actual live files and retain server-only modifications. Do not blindly pull/reset/overwrite the application. None of the following steps has been executed in production.

1. **Read-only preflight:** verify the configured source namespace remains `bma-waiver`, source timezone remains unresolved, and original successful native batch is 1,074 inserted / zero errors. Check existing 001–009 migration status/checksums with the existing code before deployment. Identify an active canonical admin ID. Keep the original CSV private and byte-for-byte unchanged.

```sql
SELECT id,source_type,filename,file_hash,row_count,inserted_count,updated_count,
       skipped_count,error_count,status,import_options,source_timezone
FROM import_batches WHERE source_type='cognito_waivers'
ORDER BY id DESC LIMIT 10;
SELECT id,role,is_active FROM app_users WHERE role='admin' AND is_active=1;
```

2. **Private backups; no application-data change:** pause imports/manual edits/matching while deploying. Make a cPanel full database backup or equivalent consistent MariaDB dump, including schema, triggers and data. Verify the backup is readable and restoration is possible. Back up affected private files/migrations outside public_html. Use the host's verified PHP 8.2 binary; do not alter handlers or unrelated applications.

```sh
umask 077
cd /home/bbmmacom/repositories/Stripe_app
BMA_PHP82=/opt/cpanel/ea-php82/root/usr/bin/php
"$BMA_PHP82" -v
BMA_BACKUP=/home/bbmmacom/bma-private/emergency-contact-backup-$(date +%Y%m%d-%H%M%S)
mkdir "$BMA_BACKUP"
tar -czf "$BMA_BACKUP/private-code.tar.gz" app/Import/CognitoWaiverImporter.php config/import-mappings.php src/CognitoSource.php src/Retention.php sql/schema.sql sql/migrations
# Substitute the actual cPanel database user/name; -p prompts without putting the password in command history.
mysqldump --single-transaction --routines --triggers --default-character-set=utf8mb4 \
  -u '<CPANEL_DATABASE_USER>' -p '<CPANEL_DATABASE_NAME>' > "$BMA_BACKUP/database.sql"
# Check exit status and backup contents privately before proceeding.
```

If that PHP path or dump executable is unavailable, use the host's verified equivalents/cPanel backup facility. Never expose backup files through the web root.

3. **Private code deployment:** manually merge the four changed runtime files and add `app/Import/CognitoEmergencyBackfill.php`, `bin/backfill-cognito-emergency-contacts.php` and migration 010 as a coordinated release. Keep imports paused until migration 010 completes; the expanded native mapping cannot stage contacts under the old CHECK. Preserve existing config, paths, auth, public files and migrations 001–009. Preserve migration SQL bytes/checksums (010 is UTF-8/LF). The schema snapshot/documentation may be copied for reference but must not be executed against production.

```sh
for BMA_FILE in app/Import/CognitoWaiverImporter.php app/Import/CognitoEmergencyBackfill.php config/import-mappings.php src/CognitoSource.php src/Retention.php bin/backfill-cognito-emergency-contacts.php; do
  "$BMA_PHP82" -l "$BMA_FILE" || exit 1
done
"$BMA_PHP82" bin/install.php --check --config=/home/bbmmacom/bma-private/config.php
```

Expected: exactly `010_cognito_emergency_contacts` pending. Stop on checksum mismatch, in-progress migration or any other pending version. Do not edit schema_migrations manually.

4. **CHANGES PRODUCTION SCHEMA:** after backup/preflight approval, apply through the existing migration runner. The only pending migration must be 010.

```sh
"$BMA_PHP82" bin/install.php --apply --config=/home/bbmmacom/bma-private/config.php
"$BMA_PHP82" bin/install.php --check --config=/home/bbmmacom/bma-private/config.php
```

Expected: 010 applied, then zero pending. Verify the two nullable columns and that historical row counts/raw evidence remain unchanged. If DDL fails partway, stop; do not force a retry, drop data, or mark history manually.

5. **Read-only backfill dry-run:** use the actual original batch/admin IDs found in step 1. The private CSV's SHA-256 must match that original import. The namespace is explicit and must match configuration. Do not substitute a new export or alter counts to bypass a failure.

```sh
BMA_ORIGINAL_CSV='/home/bbmmacom/bma-private/BloomsburgMartialArtsAcademy2(7).csv'
BMA_SOURCE_BATCH='<ORIGINAL_SUCCESSFUL_BATCH_ID>'
BMA_ADMIN_ID='<ACTIVE_ADMIN_ID>'
"$BMA_PHP82" bin/backfill-cognito-emergency-contacts.php \
  --config=/home/bbmmacom/bma-private/config.php \
  --file="$BMA_ORIGINAL_CSV" --namespace=bma-waiver.BloomsburgMartialArtsAcademy2 \
  --batch="$BMA_SOURCE_BATCH" --actor-user="$BMA_ADMIN_ID" --expected-rows=1074 --dry-run
```

Expected if contacts are still NULL: 1,074 CSV/unique/matched rows, zero unmatched/failures, 74 rows missing at least one field, 1,025 proposed updates, 49 unchanged and zero updated. Different existing contacts may reduce proposals or cause a safe conflict; inspect privately before proceeding. No schema/hash/namespace override is provided.

6. **CHANGES PRODUCTION CONTACT DATA AND AUDIT:** only after reviewing the dry-run, run the same command replacing `--dry-run` with explicit `--apply`. This fills only the two emergency fields and appends audit; ordinary updated_at changes automatically. It does not rewrite historical snapshots or identities.

```sh
"$BMA_PHP82" bin/backfill-cognito-emergency-contacts.php \
  --config=/home/bbmmacom/bma-private/config.php \
  --file="$BMA_ORIGINAL_CSV" --namespace=bma-waiver.BloomsburgMartialArtsAcademy2 \
  --batch="$BMA_SOURCE_BATCH" --actor-user="$BMA_ADMIN_ID" --expected-rows=1074 --apply
```

7. **Read-only postflight:** repeat dry-run, expecting zero proposed/updated, 1,074 unchanged and zero failures. Check audit actor/reason/batch and aggregates without outputting contact values. Compare protected table counts, accepted/rejected decisions and original raw/hash/locator values against preflight/backup. Confirm source timezone stays unresolved and existing protected pages still function.

```sql
SELECT COUNT(*) AS native_waivers,
       SUM(emergency_contact_name IS NULL) AS missing_names,
       SUM(emergency_contact_phone IS NULL) AS missing_phones
FROM cognito_waivers
WHERE form_namespace='bma-waiver.BloomsburgMartialArtsAcademy2'
  AND source_format='bma2_v1';
SELECT actor_user_id,action,COUNT(*) AS changes
FROM audit_log WHERE action='cognito_emergency_backfilled'
GROUP BY actor_user_id,action;
```

Whole-namespace counts may include later waivers; the CLI counters are scoped to the exact original CSV IDs. Do not treat a whole-database count as proof that no unrelated row changed. Keep/restrict backups and the original CSV according to BMA's policy; no new fixed retention period is imposed.

## Rollback and remaining limits

Before contact apply, a code rollback can restore the backed-up private files while leaving additive migration 010 in place. Keep migration 010/history in the repository used by the runner once applied; restoring a code checkout without that file would trigger unknown-migration protection. Do not remove columns/constraints or edit migration history to force rollback.

After apply, reverting code does not revert data. Use the private preflight backup and audit's changed field names/waiver IDs to design a separately reviewed compensating update only for values unchanged since recovery. Preserve subsequent edits, links, all historical evidence and the original audit; record a new rollback audit. No automatic destructive rollback is supplied. Full-database restore requires a separate maintenance decision because it can discard later activity.

Production recovery is not yet verified. Conflicting existing contact corrections, missing original batch/file metadata, source/hash drift or an interrupted/incompatible schema are deliberate blockers requiring review. Linked/verified emergency corrections outside this NULL-only recovery still need an authorized audited editing workflow, which is not added here. Contact values are retained by explicit approval but remain excluded from ordinary reports/UI and matching. Source timezone is intentionally not resolved.

## Exact file inventory

Modified runtime: `app/Import/CognitoWaiverImporter.php`, `config/import-mappings.php`, `src/CognitoSource.php`, `src/Retention.php`.

New runtime: `app/Import/CognitoEmergencyBackfill.php`, `bin/backfill-cognito-emergency-contacts.php`.

Schema: new `sql/migrations/010_cognito_emergency_contacts.sql`; updated `sql/schema.sql` for fresh installs only.

Tests: new `tests/cognito-emergency-contacts.php`; updated `tests/cognito-compatibility.php` for the 18-key contract, migration 010 and newly approved encoding case.

Documentation: this file; updates to `docs/cognito-import-compatibility.md`, `docs/cognito-field-meanings.md`, `docs/import-field-mapping.md`, `docs/imports.md`, `docs/database-schema.md`.

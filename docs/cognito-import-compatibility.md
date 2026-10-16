# Cognito native export compatibility

## Completed correction — 2026-10-15

The source file `BloomsburgMartialArtsAcademy2(7).csv` uses internal form-field headers, a native submission ID, 12-hour AM/PM clocks and Windows-1252 text. The old importer expected the separate legacy shelled format and validated every source cell as UTF-8 before filtering; it therefore lost required identity evidence and rejected some records based on bytes in fields that should be excluded.

Source inspection found 1,074 records, 23 columns, no width errors, 1,074 nonblank distinct positive-integer IDs (maximum four digits). Eleven records contain bytes invalid in UTF-8. Four contain invalid bytes in approved fields; seven contain invalid bytes only in excluded fields. The observed extended bytes are 0x92/0x94 punctuation and 0xE9/0xFA accented letters, all defined in Windows-1252. No undefined Windows-1252 byte was found. No personal cell values are reproduced here.

## Exact field contract

The new internal `cognito_waivers_bma2_v1` mapping is selected by explicit headers, not fuzzy header guessing. Its five structural headers (native ID, StudentName_First, StudentName_Last, PrintName, Entry_DateSubmitted) must be present. Mixed native/legacy aliases fail preflight. All 16 approved original native keys/cells are retained in raw_cognito_waivers.raw_values; no generated combined Name or Address is written into native raw JSON. Ignored values never enter raw JSON, normalized records, matching, diagnostic messages or logs. Unknown columns default to ignored; their header names are safe metadata only.

| Source column | Handling | Destination / purpose |
|---|---|---|
| `BloomsburgMartialArtsAcademy2_Id` | IMPORT | cognito_waivers.source_record_id — Native submission ID, scoped to the named form; never a person ID |
| `StudentName_First` | IMPORT | cognito_waivers.source_student_first_name — Explicit student first-name source evidence |
| `StudentName_Last` | IMPORT | cognito_waivers.source_student_last_name — Explicit student last-name source evidence |
| `Phone` | IMPORT | cognito_waivers.contact_phone — Contact owner unresolved |
| `TextFriendly` | OPTIONAL | cognito_waivers.text_friendly — Contact text preference; not identity evidence |
| `StudentBirthdate` | OPTIONAL | cognito_waivers.student_birth_date — Student birth date only; never signer demographics |
| `Email` | IMPORT | cognito_waivers.contact_email — Contact owner unresolved |
| `Address_Line1` | OPTIONAL | cognito_waivers.address_text — Approved household/contact location evidence |
| `Address_Line2` | OPTIONAL | cognito_waivers.address_text — Unit or second address line distinguishes contact locations |
| `Address_City` | OPTIONAL | cognito_waivers.address_text — Contact city evidence; owner unresolved |
| `Address_State` | OPTIONAL | cognito_waivers.address_text — Contact state evidence; owner unresolved |
| `Address_PostalCode` | OPTIONAL | cognito_waivers.address_text — Contact postal evidence; owner unresolved |
| `PrintName` | IMPORT | cognito_waivers.signer_print_name — Printed signer snapshot, separate from named student |
| `Date` | IMPORT | cognito_waivers.signed_date — Source calendar signing date |
| `RelationshipToStudent` | IMPORT | cognito_waivers.relationship_to_student — Source relationship claim; no automatic edges |
| `Entry_DateSubmitted` | IMPORT | cognito_waivers.submitted_at — Original submission clock value; source timezone separate |
| `EmergencyContact` | IGNORE | Excluded everywhere — Excluded: Unnecessary third-party contact |
| `Phone2` | IGNORE | Excluded everywhere — Excluded: Emergency-contact phone |
| `Considerations` | IGNORE | Excluded everywhere — Excluded: Medical or special-consideration information |
| `Goals` | IGNORE | Excluded everywhere — Excluded: Sensitive free-form interests |
| `Entry_Status` | IGNORE | Excluded everywhere — Excluded: Workflow status has no reporting purpose |
| `Entry_DateCreated` | IGNORE | Excluded everywhere — Excluded: Creation metadata unnecessary for submission identity |
| `Entry_DateUpdated` | IGNORE | Excluded everywhere — Excluded: Update metadata not used to order or authorize imports |

The source student-name components populate dedicated source columns and derive `source_name` once for existing display/matching. That derived full name is not an additional independent match signal. `source_format=bma2_v1` and `source_name_role=student` establish the source label, not a verified BMA identity. Legacy rows default to `legacy/unresolved`; their old Name meaning is unchanged. Contact address text is derived consistently from line 1, line 2, city, state and postal code; approved component strings remain separately available in staging. Address line 2 is approved for unit/location context. No imported address is automatically assigned to a member or signer.

Entry_Status/Entry_DateCreated/Entry_DateUpdated are explicitly ignored: no workflow dashboard, update-order policy or identity rule needs them. EmergencyContact/Phone2/Considerations/Goals remain excluded. The operator must use a current export for source updates; ignored Entry_DateUpdated does not establish version ordering.

## Native submission ID verification and identity

Cognito's [entry import documentation](https://www.cognitoforms.com/support/219/entries/importing-entries) identifies FormName_ID as the internal Entry ID column used to update existing entries. Its [entry-number documentation](https://www.cognitoforms.com/support/81/entries/working-with-entries) describes unique entry numbers that are not changed or reused. These support using the supplied form-specific ID as a submission key, not a person identifier. The actual file's ID values were checked for distinctness and format.

`CognitoSource::entryId()` requires an exact positive decimal string, no leading zero, whitespace, NUL or nondigit, and at most the existing 255-character database limit. Values are never cast to integers or manufactured. The adapter calls the existing trusted-ID mechanism with that ID. The effective namespace is:

```
<configured COGNITO_FORM_NAMESPACE>.BloomsburgMartialArtsAcademy2
```

The existing external locator remains `cog-v1-` plus SHA-256 of the JSON tuple [effective namespace, exact native ID]. The content hash uses only approved original UTF-8 source values under their original keys. The configured namespace must uniquely identify the real source form and, together with the fixed suffix, fit 100 characters. Do not reuse it for another organization, copied form or unrelated source. A same-named form cannot be distinguished from these columns alone; correct namespace configuration remains an operator responsibility.

Reimport/update rules:

- Same native ID/content: skip. CSV filename, row order and ignored-field changes do not create a new normalized record.
- Same ID/new approved content: validate it first; update an existing unassociated/unreviewed record under the same internal ID/locator.
- Any identity link (including rejected/possible), match candidate, cached association or verified interpretation protects changed native evidence. Retain the new approved staging row and skip normalization with WAIVER_UPDATE_REVIEW_REQUIRED. No accepted/rejected decision or old normalized snapshot is overwritten.
- Different native IDs: distinct submissions even if names/contact details match. No people/members are automatically created.
- Duplicate IDs in one file: first occurrence wins; later rows are skipped with DUPLICATE_SOURCE_RECORD. Fix/retry a file if the first occurrence failed.
- Legacy fingerprint keys and content hashes remain unchanged. Changed legacy fingerprint content is still held with IDENTITY_AMBIGUOUS.

### Reviewed reconciliation is required for existing fingerprints

If a new native entry is encountered while fingerprint records exist in the configured base namespace or its native effective namespace, it is held as skipped with LEGACY_RECONCILIATION_REQUIRED. No contact/name heuristic can prove which old submission the ID replaces. Historical locators, raw rows, identity links and decisions remain untouched.

A reviewed native-to-fingerprint crosswalk/reconciliation writer is **not implemented** by this targeted correction. Before enabling actual native imports in that situation, inventory the old records, verify submission correspondence against source evidence and approve an audited reconciliation approach that preserves historical identifiers/links. Do not delete records or change namespaces merely to bypass the hold. This hold is deliberately conservative and may include distinct new submissions until the old form inventory has been reviewed. Production fingerprint presence was not inspected here.

## Encoding and streaming

UTF-8 remains the default. A new Cognito-only encoding selector accepts exactly UTF-8 or Windows-1252; the CLI equivalent is `--cognito-encoding=Windows-1252`. Stripe processing ignores that option and retains its existing UTF-8 behavior. No autodetection or whole-file conversion is performed.

CSV records are streamed with fgetcsv, preserving quoted commas/newlines and record counts. After the fixed whitelist selects approved cells, UTF-8 cells are validated without modification, or Windows-1252 cells are converted using the already-required mbstring extension. Undefined Windows-1252 bytes and NUL are rejected. Conversion must produce valid UTF-8 and round-trip exactly to the original byte string. No lossy substitution or //IGNORE option is used. Ignored/unknown cell values are never decoded or serialized; malformed bytes in excluded text cannot invalidate an otherwise legitimate record. Structural CSV, UTF-16/NUL-file and header checks remain in force.

Approved original text meaning, formatting and precision are preserved in UTF-8. The original byte encoding is recorded as batch metadata, and the file hash remains the original source-file hash. Original upload files remain transient under unchanged private upload/cleanup rules. No additional working CSV/archive is created. Encoding metadata is considered when deciding whether a completed file import can be skipped.

## Dates, source timezone and roles

The explicit source date convention is M/D/YYYY; ISO date-only fields remain supported by the existing normalizer. There is no per-row day/month guessing. New strict AM/PM formats support single-digit components and optional seconds, with correct noon/midnight and impossible-date rejection. ISO-offset and prior 24-hour formats remain supported. Source clock strings stay unchanged in staging.

No source timezone was established by this export. All 1,074 submitted clocks lack an offset. COGNITO_SOURCE_TIMEZONE remains unset/unresolved; APP_DISPLAY_TIMEZONE is not used to fill it. Verified explicit offsets normalize to UTC; a separately verified configured source zone retains existing DST ambiguity protection. Source birthdates/signed dates are DATE values and never timezone-shifted. The member profile can display the approved original native submission string, separately from a verified instant.

Student components, signer print name, household/contact fields and claimed relationship remain separate evidence. A child's birthdate is not signer evidence. No contact coincidence creates payer/member edges, automatic student associations, duplicate members or shared-household merges. Matching thresholds, candidate evidence/scoring, manual precedence and authoritative association services are unchanged.

## Diagnostics and counters

Failed identity exceptions now receive error severity in new batches, agreeing with error_count. Legitimate held/reviewable records remain skipped warnings. Invalid submitted timestamps are validated before review holds; a hold cannot hide malformed evidence.

- INVALID_IDENTIFIER: invalid native entry key; failed row.
- IDENTITY_AMBIGUOUS thrown for incomplete legacy fingerprint evidence: failed row/error severity.
- INVALID_ENCODING: malformed approved text for selected encoding; failed row.
- INVALID_DATE: malformed nonblank date/timestamp; failed row.
- TIMEZONE_UNRESOLVED: valid source clock without verified zone; successful/validated record warning.
- SOURCE_TIME_AMBIGUOUS: source clock is ambiguous/nonexistent in a verified zone; no guessed instant.
- SUBMISSION_TIME_MISSING: valid native ID but no submitted clock; retained with no fabricated timestamp.
- LEGACY_RECONCILIATION_REQUIRED / WAIVER_UPDATE_REVIEW_REQUIRED: staging retained, normalized update held, skipped warning.
- DUPLICATE_SOURCE_RECORD: skipped duplicate, existing first-occurrence policy.

Prior diagnostic rows and batch counters are not rewritten. Historical IDENTITY_AMBIGUOUS rows can still show warning severity even when old batch counters mark them failed. The administration page now displays authoritative batch status/inserted/updated/skipped/failed counts separately from warning/error diagnostic totals, plus source adapter, encoding, namespace and source timezone. Existing 500-row pagination remains available with explicit totals and Next/Previous instructions; it is not a 500-issue cap.

## Schema migration 009

`009_cognito_native_export.sql` extends the raw Cognito JSON-key CHECK to either the original ten-key legacy set or the explicit sixteen-key native set. It still rejects unknown/sensitive keys and disallows mixed-format payloads. Four additive normalized source-label columns preserve provenance: source_format, source_name_role, source_student_first_name, source_student_last_name. A CHECK restricts legacy/unresolved versus native/student combinations. No original source value, source locator, association or migration checksum is rewritten.

Migration 008 remains a separate prerequisite in the ordered chain; do not assume production has applied it. The consolidated schema snapshot is updated only for fresh installations. Never execute schema.sql over production.

## Focused results — 2026-10-15

**83 final focused checks passed** with `php tests/cognito-compatibility.php`. The new migration was applied through the existing migration runner to an existing eight-migration disposable database; repeat checks had no pending work. No existing test database was rebuilt and no broad regression/stress suite was run. The canonical snapshot was separately executed in a new empty disposable database: all nine migration checksums/inventory agreed, and both affected Cognito table definitions matched the upgraded fixture exactly. The temporary snapshot database was removed.

Checks include native/legacy mappings and hashes, exact IDs, native form scoping, duplicate/reordered imports, changed source, old fingerprint holds, confirmed/rejected decision protection, failed-row severity, partial retries, strict AM/PM/ISO/24-hour/DST parsing, lossless approved-cell encoding, excluded invalid bytes, multiline CSV, SQL key constraints, source-label persistence, no automatic people/members and unchanged prior business rows after fixture cleanup.

| Actual export measurement | Result |
|---|---:|
| CSV records / columns | 1,074 / 23 |
| Unique nonblank numeric native IDs | 1,074 |
| Records with invalid UTF-8 bytes anywhere | 11 |
| Invalid UTF-8 in approved fields | 4 |
| Invalid UTF-8 only in excluded fields | 7 |
| Default UTF-8 dry-run skipped / failed | 1,070 / 4 |
| Explicit Windows-1252 dry-run skipped / failed | 1,074 / 0 |
| Windows-1252 identifier/date/encoding errors | 0 |
| Source-timezone warnings | 1,074 |
| Unknown columns | 0 |
| Normalized writes during actual-file dry-run | 0 |
| Unexpected people/members/identity links | 0 |

The actual-file dry run used its own fresh disposable form namespace; no prior production fingerprint or review state was assumed. Sensitive source values were not printed. Synthetic actual imports verify normalization and update rules; the 1,074-row source file was not imported into production or automatically reimported. Test-created staging, normalized fixtures, links/audits and logs were cleaned up. Existing business/identity/financial row hashes matched their starting values (additive metadata defaults excepted). All modified/new PHP files pass lint.

## Safe split-cPanel deployment and rollback

Completed locally only. Do not git pull/reset the live repository or overwrite server-specific bootstrap/security fixes. Do not change .htaccess, credentials, APP_KEY, Stripe authentication, timezone settings or historical identifiers.

1. **Backup:** use the host's normal database backup facility and copy the current affected private/public files to approved private backup storage outside public_html. Confirm the backup is restorable. Arrange a short import-maintenance window so no import is running during DDL/code replacement.
2. **Preflight:** verify PHP 8.2, MariaDB and existing mbstring support. Review the three-state distinction: configured form namespace, legacy fingerprint presence and independently verified source timezone. Do not invent a source zone. Merge the targeted changes with any live fixes; prepare the whole changed-file set before activation.
3. **Migration check:** add the new 009 file to the private sql/migrations directory. Ensure 008 is present in that directory as well if production has not received it. Run:

```sh
php /home/bbmmacom/repositories/Stripe_app/bin/install.php --check --config=/home/bbmmacom/bma-private/config.php
```

Expected pending versions are 009 alone, or 008 followed by 009. If older migrations, checksum differences, unexpected inventory or an in-progress migration appear, stop and review that actual state. Do not bypass checks or overwrite migration history.

4. **Apply approved pending migrations:** after backup/preflight, use the existing mechanism:

```sh
php /home/bbmmacom/repositories/Stripe_app/bin/install.php --apply --config=/home/bbmmacom/bma-private/config.php
```

DDL auto-commits; a partial failure requires inspection rather than force retry. Do not execute the consolidated schema snapshot.

5. **Private PHP/config deployment:** install src/CognitoSource.php before activating updated src/Retention.php; deploy the remaining private changes as a coordinated set under `/home/bbmmacom/repositories/Stripe_app/`. Keep existing private configuration at `/home/bbmmacom/bma-private/config.php`. No Composer or new server dependency is required. New native imports require migration 009 first.
6. **Public deployment:** only public/admin/imports.php and public/admin/import-errors.php change, at matching relative paths beneath `/home/bbmmacom/public_html/customers.bloommma.com/public/`. Their existing admin, dashboard permission and CSRF guards remain. Deploy them with the private backend; no bootstrap/.htaccess change.
7. **Verify:** check pending migrations are zero, then use the protected Imports page with Cognito and confirmed Windows-1252 selected, dry-run enabled. Review batch counters and all diagnostic pages. If prior fingerprints trigger a reconciliation hold, preserve the records and obtain a reviewed crosswalk before any actual import. Confirm the selected configured namespace really belongs to this form. An actual production retry requires a separate deliberate operator action; none is performed by this deployment.
8. **Rollback:** stop imports, restore only the previously backed-up affected PHP/config-mapping/public files while preserving live secrets and host fixes. Keep additive schema changes and migration history; do not drop new source columns/keys or re-key/delete imported records. If a migration is incomplete, inspect its exact state and backups before any approved recovery. Restoring application code does not undo source records imported after deployment. Do not automatically restore/delete database data to roll back code.

The remaining production decisions are verification of source timezone and any required reviewed fingerprint reconciliation. No reviewed-reconciliation writer, source API sync or automatic person matching was added.

## Files changed

- `app/Import/CognitoWaiverImporter.php`
- `app/Import/ImportService.php`
- `app/Members/MemberProfileRepository.php`
- `bin/import.php`
- `config/import-mappings.php`
- `src/CognitoSource.php`
- `src/CognitoIdentity.php`
- `src/Retention.php`
- `public/admin/imports.php`
- `public/admin/import-errors.php`
- `sql/migrations/009_cognito_native_export.sql`
- `sql/schema.sql`
- `tests/cognito-compatibility.php`
- `docs/cognito-import-compatibility.md`
- `docs/cognito-field-meanings.md`
- `docs/import-field-mapping.md`
- `docs/imports.md`
- `docs/database-schema.md`
- `docs/deployment.md`

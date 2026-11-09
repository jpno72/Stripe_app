# Consolidated association reconciliation

This is a reference import against existing Cognito waivers and Stripe customer/payment records, not a new matching engine or source reimport. No migration is required. Existing reporting views retain their transaction/member grain. The earlier people/member finalization changes are dependencies and are deployed together; they do not run automatically.

## Inspected files

- `bma_consolidated_waivers_3years.csv`: 301 group records referencing 313 distinct source waiver IDs. Groups are not automatically unique verified people or members.
- `bma_stripe_association_evidence.csv`: 624 evidence rows, 427 distinct `pi_` payment identifiers. Repeated rows across student groups must not multiply payments.
- `bma_consolidated_waiver_duplicates.csv`: nine duplicate-manifest rows; these validate group/waiver membership and do not create sources.
- The supplied XLSX is a companion source workbook; the controlled process reads the three CSVs only. Workbook notes are data, not instructions.

Actual columns are read as written. Required reconciliation whitelist: group/student name and variants, source DOB/count/IDs, contact/address components, signer/relationship labels and Stripe customer IDs; evidence requires group/student/payment/customer IDs. Other columns, including aggregate payment counts, cardholder/evidence prose, charge descriptions and review notes, do not establish normalized financial or identity facts. Unknown columns are ignored in parsed payloads. CSV BOMs, quoted commas/newlines and blank values are handled by streaming `fgetcsv`. Each input is bounded to 20 MB and 10,000 records. Duplicate/missing structural headers fail closed.

The analysis CSVs are deliberately retained in private storage per this task. They are not uploaded source archives created by the original importers; existing raw import filtering/deletion policies remain unchanged. No complete row is serialized into staging, JSON, audit or error logs. Sensitive/free-form source columns are excluded from reconciliation payloads and are read only on permission-gated profiles. No identifiable rows or values are printed in CLI summaries or deployment logs.

## Identity and financial safeguards

Source waiver IDs resolve `cognito_waivers.source_record_id`; database row positions are never a fallback. IDs ambiguous across form namespaces, missing IDs, repeated IDs across groups, incompatible names, DOB conflicts/future dates, legacy student-name ambiguity, manual holds/rejections and competing accepted BMA IDs remain review conflicts. Existing source/cached identity links must agree. Native student roles and source values are checked before a group can be applied. DOB is filled from consistent linked Cognito source values only, never invented from a CSV-only value.

Existing independently reviewed student/group identities are reused. New student identities require an explicit `student_verified` administrator decision. Compatible existing people require selection using BMA ID; distinct existing IDs are never merged. Repeated compatible new person or disjoint payer proposals are flagged rather than allocating duplicates. Nickname/middle-initial comparisons use the existing helper as supporting evidence only.

A CSV association label (including direct/contact match), household contact or source Mother/Father/Guardian label does not authorize a merge or legal relationship. New Stripe links require `payer_verified`; payer/student edges additionally require `payer_student_verified`. `same_person_verified` requires compatible Self source roles and no conflicting accepted identities/DOB. Signer decisions require `signer_verified`, an already verified payer or selected compatible existing signer. Parent/guardian edges require `parent_relationship_verified`, a verified signer and a supported source label. The four outcomes remain distinguishable: verified same-person; verified payer/student; proposed household/payment; conflicting/unresolved.

The immutable mapping is an accepted manual `identity_links` scope: source type `consolidated_waiver`, external ID `bma3y-v1:<Group ID>`, role `student`. Individual waiver role links remain authoritative through IdentityAssociations; original submissions and dates are retained. No new financial transaction, subscription, waiver or membership record is created. Membership remains a separate evidence-based finalization workflow.

Every distinct supplied payment ID is resolved against imported `stripe_charge_id` or `stripe_payment_intent_id`. Missing payments are reported as missing source transactions; multiple charge rows for one PaymentIntent are reported as ambiguous. Customer mismatches are review conflicts. Aggregate CSV payment counts/descriptions are never summed into dashboards or written as transactions. Main revenue reporting stays based on unique imported transaction IDs.

Existing nonempty BMA demographics are preserved, including older/manual values whose verification date is unavailable. Personal contact/address fields additionally require explicit ownership verification and consistent source values; household contacts are displayed as source contact information without being copied to every student. Multiple addresses stay source history/review rather than being guessed into the profile. Sensitive considerations and goals remain read-only private consolidated source text, not stored in new database columns or general reporting/search. Medical/goals require the canonical administrator role; emergency information uses existing authorized-role permission checks. Supplemental groups must still resolve to accepted waivers of the current person before display.

## Private deployment and usage

Input directory: `<private project root>/storage/reconciliation/input/`, with directories 0700 and files 0600. It must resolve outside every served root. The directory is already Git-ignored, as are CSV/XLSX files. Never copy inputs to public/ or commit them. Do not upload the XLSX or private application configuration.

Administrator GET `/admin/reconcile-consolidated.php` provides counts, exclusions, source coverage and a preview digest. It has no POST/apply action. Only the active application-session admin with Matches permission can view it. GET and CLI dry run make no database writes. The operator may supply a private `approvals.json` containing only explicitly reviewed group decisions. No approval file is generated from CSV association labels.

CLI (private server terminal, PHP 8.2):

```text
php bin/reconcile-consolidated.php --admin-id=ADMIN_ID --directory=PRIVATE_INPUT_DIR --dry-run
php bin/reconcile-consolidated.php --admin-id=ADMIN_ID --directory=PRIVATE_INPUT_DIR --approvals=PRIVATE_APPROVALS_JSON --dry-run
```

A reviewed approvals object is keyed by Group ID; supported booleans are `student_verified`, `payer_verified`, `payer_student_verified`, `signer_verified`, `same_person_verified`, `signer_is_payer`, `parent_relationship_verified`, `contacts_student_owned`, `student_address_verified`. Optional BMA selections: `student_bma`, `payer_bma`, `signer_bma`. Decisions must be actual administrator review, not derived from matching score or source relationship alone.

After a complete database backup and **separate administrator authorization**, the private CLI supports:

```text
php bin/reconcile-consolidated.php --admin-id=ADMIN_ID --directory=PRIVATE_INPUT_DIR --approvals=PRIVATE_APPROVALS_JSON --apply --approve-digest=REVIEWED_DIGEST --backup-reference=PRIVATE_BACKUP_REFERENCE
```

Do not execute this command as part of deployment. Apply revalidates the entire snapshot and inputs under the existing review mutex/transaction. It preserves conflicts for review, uses People and IdentityAssociations, fills only permitted empty demographic fields, audits group mappings/field names without source values, and verifies before/after counts. Identical reruns make no duplicate people, links, edges or audit records. Any partial failure rolls back the whole operation. Changed source/decision snapshots require a fresh preview.

## Profiles and dashboards

`/member.php?person=<internal person ID>` supports verified people without a member row and clearly displays Membership not verified. BMA IDs work as before. Existing member routes remain compatible. Payer account totals and subscription context are labelled unallocated; no parent's total is assigned to each child. Actual paid charge descriptions are grouped with occurrence counts. Transaction details use the existing protected endpoint. Source waiver contacts, all waiver history and restricted supplemental information are retained under the person profile.

Membership charts continue counting unique actual member IDs, not imported groups or waiver submissions. Demographics additionally reports distinct confirmed student coverage, missing DOBs and participants without a member record; existing charts remain explicitly scoped to their member cohort. Revenue SQL/views are unchanged and retain unique transaction grain, separate currencies and unresolved fees.

## Focused validation

Use `tests/consolidated-reconciliation.php` with disposable localhost DB credentials. It reuses existing fixtures and tests source references, repeated waivers, duplicate accounts, shared parents/different surnames, DOB/accepted identity conflicts, missing PaymentIntents, unchanged financial rows, person profiles without membership, description grain, idempotence, rollback, private filtering/BOM handling, real supplied headers/counts, read-only actual-file preview and authorization. Existing profile/emergency tests verify sensitive access. No production apply, source reimport or matcher write run is executed.

Production source coverage and database counts must be measured by an authenticated read-only production preview; local fixture counts and the CSV association labels cannot substitute for that measurement.

Validation result: 98 focused reconciliation/base-workflow assertions passed, including actual-file read-only parsing and preview; the existing 78 profile/emergency assertions passed with these profile changes. All 18 changed/new PHP files pass syntax validation and Git whitespace validation passes. Existing native-name/grouping/finalization safeguards retain their previously passed targeted results. Production coverage remains pending an authenticated preview.

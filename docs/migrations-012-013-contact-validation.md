# Migrations 012/013 and profile contact consolidation

Review date: November 11, 2026. **No production migration is authorized or applied.**

## Schema-change review

| Migration | Exact schema addition | Existing data / activation risk |
| --- | --- | --- |
| 012 | Empty `stripe_transaction_attributions`: transaction PK/FK, person FK, reviewer FK, role, review time; two ordinary indexes and a role check | No existing rows, views or triggers change. Recording its completed marker enables the existing administrator-only direct-payment allocation editor. Future explicit allocations can affect member reporting context; the migration itself changes no payment amount, account ownership or billing. |
| 013 | Empty `waiver_association_reviews`: review PK, person/waiver/reviewer FKs, role, classification, review status, code-only JSON evidence, source digests and timestamps; one unique person/waiver/role key, three ordinary indexes, four checks | No source columns or existing records change. Enables on-demand discovery/review storage. Discovery writes suggestions only; identity or payer/guardian confirmation still needs a separate explicit administrator decision. Emergency identities stay separate from student/signer identity links. |

Both use InnoDB and utf8mb4, ordinary MariaDB DDL, and existing PHP/PDO services.
Neither changes schemas, financial records or Stripe state outside its new table.
The new tables have six foreign keys in total. No new daemon, privilege model,
account or Stripe API call is introduced.

The normal runner enforces chronological migration history, applied checksums,
object inventory and a database advisory lock. **012 then 013 is the supported
sequence**; skipping/pretending to apply 012 would corrupt that history. The SQL
adds independent tables, so no data remediation was necessary on the tested
production snapshot. Existing migrations and checksums were not edited.

## Restore and validation evidence

A new disposable database was freshly restored from the complete, previously
verified November 10 production backup. Its table fingerprints and object inventory
matched that backup before testing. This is a fresh restore of that snapshot,
**not a claim that today's live production history has been checked**.

PASS: the normal runner reported exactly 012/013 pending and applied both in
sequence. Every original table's data, including people, memberships, customers,
payments, subscriptions, identities, relationships, imports and audits remained
unchanged. Existing views/triggers/routines/events remained unchanged; both new
tables were empty with six foreign keys. Immediate empty-table rollback restored
the exact pre-migration fingerprints/inventory/history. Reapplying succeeded.

Original snapshot counts: 328 people, 34 members, 1,282 transactions. Full source
fingerprints were checked, not merely counts. Canonical Git SQL was copied to a
private test directory to avoid Windows checkout line-ending differences; stored
checksums and repository migrations were not changed.

Focused checks passed:

- 15 pure contact display/escaping/precedence tests.
- 54 affected member-profile regression checks on the new restore.
- 45 association-discovery/review checks after the normal migration sequence,
  including complete directory pagination, filters, sorting, audit rollback,
  rejected/held decisions, and no duplicate or automatic identity/member creation.
- 26 authenticated local browser checks: consolidated contacts, reviewed-primary
  and source alternatives, latest nonblank fallback, history warnings, source IDs,
  separate emergency information, association-count anchor navigation, verified /
  cached-proposed profile summary, discovery, directory filter/sort, desktop/mobile,
  viewer/anonymous restrictions. No production browser identity decision occurred.
- Eight original business/source/identity table fingerprints were unchanged by
  browser requests; contacts and original waivers were not rewritten.
- Changed PHP syntax and Git whitespace checks.

**Live preflight pending:** the previously used secure SQL forward at
`127.0.0.1:13306` was unavailable during this review. The operator was asked to
reopen it. Do not use the non-TLS remote SQL port directly, infer current migration
history from FTP files, or describe the snapshot rehearsal as live verification.

## Backup, approval and rollback plan

Before any separately authorized production operation:

1. Reconnect through the SSH tunnel. Read current `schema_migrations`, MariaDB
   version and table/view inventory in read-only mode. Require the expected intact
   001–011 history, no interrupted markers, neither new table, and no unexpected
   pending migration. Refuse a different baseline.
2. Make a new full consistent SQL backup (structure/data/views/triggers/routines/
   events) through the tunnel, stored outside the public root. Verify SQL and gzip
   SHA-256 and gzip integrity. Restore it to a new disposable database and compare
   all table fingerprints and object inventory with a read-only production snapshot.
   Repeat the focused sequence on that current restore. Stop if any check differs.
3. Obtain approval specifically for **CREATE TABLE from migration 012 followed by
   CREATE TABLE from 013**, and the normal runner's actual completed/in-progress
   migration ledger operations. No existing source-data update, allocation,
   association confirmation, matching run or Stripe operation is included.
4. Only after approval, stage the exact committed 012/013 SQL privately on the
   server and verify hashes. Do not rewrite older SQL/checksums. Run the existing
   installer's `--check` with the private configuration and require exactly those
   two pending migrations before `--apply`. Keep users from submitting writes
   during this short schema/validation window. Verify original data fingerprints,
   history, keys, empty new tables and authenticated access after application.

MariaDB DDL commits implicitly; a PHP/SQL transaction is not a DDL rollback plan.
For an approved immediate rollback while both tables remain empty and unused,
drop 013's table then 012's, remove only their actually applied ledger entries,
and verify exact original data/inventory/history. This was tested locally; it is
not permission to run DROP/DELETE in production. Once either table has review or
allocation data, do not drop it or erase audits: stop and prepare a separately
approved recovery that preserves subsequent legitimate changes. Retain the full
pre-migration backup as the restorable recovery point.

## Display-only release

One **Contact Information** card now includes BMA and latest accepted-waiver email,
telephone and complete mailing address (including address line 2, city/state/ZIP).
Existing `source_demographic_filled` field audits provide reviewed contact evidence:
the existing writer requires `contacts_student_owned` for phone/email and
`student_address_verified` for address fills. Identity links, shared household
contacts or mere field presence never create contact verification.

Reviewed BMA values take precedence. Otherwise the latest nonblank waiver value
is labeled **Waiver-provided — unverified**, with the differing unverified BMA
record retained as an alternative. Person-only values with no review provenance
remain visible as **verification not recorded**. Equivalent values are not repeated;
source-waiver attribution and historical disagreement warnings remain. Missing
waiver record IDs fall back to the available source locator. Emergency contacts
remain exclusively in their existing protected sections. No contact updates occur.

Administrator directory association counts link to
`/member.php?person=ID#other-waiver-associations`. That section shows separate
verified and cached proposed totals and offers reviewed results/discovery controls.
Without approved 013, proposed totals remain unavailable and discovery stays gated.
Existing roles, CSRF, dashboard permissions and source-data protections are unchanged.

Only contact/navigation runtime files are deployed for this release. Production
SQL migration files and schema operations remain outside this deployment scope.

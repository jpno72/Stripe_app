# Administrator bulk confirmation of exact matches

Implemented against main baseline 8eff1a016ee56ce4e63b81a307f10850f41bf3aa, locally validated 2026-11-03. This is an explicit administrator review operation, not a change to automatic matching scores or thresholds. No migration is needed.

## Rules and identity boundaries

Rule A compares the actual Stripe payer full name and Cognito signer_print_name plus the existing approved contact email. Full names are lowercased and all Unicode whitespace/separator runs collapse to one space; emails are lowercased and trimmed at their edges. Names/emails must be nonempty, and email syntax must be valid. Punctuation/accents are not removed and no fuzzy similarity is used. Exact payer/signer candidates may confirm one person, including a parent who is also the signer, but create no student/member relationship from Rule A alone.

Rule B additionally requires source_format=bma2_v1, source_name_role=student, the same normalized source student full name, and an explicit Self relationship. Only these student candidates can create membership and self relationships. Known minor/future birth dates or inconsistent DOB evidence exclude the group. Unknown DOB is not invented; the listed Self rule may qualify without a DOB. Student DOB is never used as payer/signer demographics in a parent case and is never copied onto a new person.

Parent/child student comparisons, legacy unresolved source-name comparisons, email-only/name-only matches and nonexact names are excluded. A parent's exact signer comparison can still qualify under A without touching the child. If a group also contains a qualifying student comparison, all relevant identity evidence must remain compatible. The source-label uncertainty flags student_identity_unverified/source_name_role_unverified alone may be resolved by Rule B's explicit criteria; other hard/contradictory evidence blocks bulk review.

Repeated waivers and multiple distinct Stripe customer rows with the same exact normalized name/email form a temporary decision group, not a permanent email master identity. Each group uses one unambiguous accepted BMA person or one unique existing BMA person with the exact name/email. Multiple existing people, contradictory existing person name/email/DOB, authoritative-link/cache disagreement, rejected identity links, manual holds, conflicting candidate statuses or rejected/deferred relevant decisions exclude the group. Compatible confirmed candidates are left unchanged and their accepted person may be reused. Only records represented by eligible candidate decisions receive new associations; other same-key records provide conflict/reuse evidence and are not silently linked.

New people use People/AUTO_INCREMENT and immutable BMA IDs. Only the source name is allocated: no shared email, phone, address, birth date or household relationship is copied automatically. Source names too long for current People columns are excluded in preview. Member status/start date retain existing unknown/NULL defaults; financial, subscription and original Cognito source values are unchanged. Authoritative reviewed identity links and their normalized person caches are synchronized by IdentityAssociations.

## UI and count meanings

The administrator-only Matches queue links to /admin/bulk-matches.php. GET computes a read-only consistent preview across the entire queue, independent of queue filters. It shows candidate totals, A/B breakdown, unique people to create/reuse, members to create, additional distinct waivers beyond the first in each group, identity scopes to confirm/promote, excluded records by primary reason and remaining open manual-review records. Counts are candidate records, not necessarily unique people; two comparison roles can refer to one waiver/person. Excluded counts include protected rejections; remaining manual excludes already rejected candidates. Already accepted counts cover confirmed/linked candidates left unchanged.

The confirmation panel explicitly states the number of candidate decisions, people, members and identity associations affected. An unchecked required acknowledgment and a separate authenticated POST are mandatory. No JavaScript is required. The POST uses existing CSRF protection, a cryptographically generated operation ID, displayed-count validation, and a server-session preview tied to the actor and expiring after 15 minutes. Neither candidate IDs nor eligibility supplied by the browser is trusted.

## Transaction, replay and audit guarantees

BulkExactMatchService rereads the complete bounded decision state with ordered InnoDB row/gap locks in REPEATABLE READ. A per-database GET_LOCK mutex serializes bulk operations across administrators. All approved names/emails, candidate states, relevant identity links, people/DOB, memberships and relationships contribute to the preview fingerprint; changed state fails the complete operation and requires a fresh preview. Source values are used only internally and are not added to page receipts or error logs.

Every candidate is applied through MatchReviewService.decideInTransaction using the one IdentityAssociations-owned transaction. It retains normal per-candidate before/after snapshots, actor/time, created objects/scope references, dependency-safe undo and notes. Bulk provenance adds operation ID, rule A/B, a hashed temporary identity-group key and preview digest. Existing manual actions still own their own transaction and keep their original audit payload shape. Compatible automatic accepted links are explicitly promoted to confirmed only in the bulk path. Before/after link snapshots support guarded restoration on reopen; later dependencies or changed link state block unsafe reversal. Existing member-removal safety rules remain conservative when other student associations still reference a person.

One final audit_log receipt (entity_type identity_bulk_operations, action identity_bulk_confirmed) commits with all decisions. A repeated operation ID from the same actor/digest returns the prior receipt without duplicate people, links, members or audit rows. A failure after some in-transaction candidate mutations rolls back the entire batch, including people/members/links/audits; no partially committed batch is resumed blindly. Failed attempts produce only a generic application log code. Deadlocks/busy locks fail safely and do not bypass revalidation. A committed receipt handles a response lost after commit.

The implementation deliberately bounds candidate scope to 1,000 rows and source/people/member tables to 10,000 rows, with 30,000 identity links/relationships. Exceeding a bound disables confirmation with a safe message; larger scopes need a deliberately designed smaller-batch workflow. Full-range source locks can briefly block concurrent imports/reviews during confirmation. No lock is held while the administrator reads the preview.

## Validation

| Suite | Passed assertions |
|---|---:|
| tests/bulk-exact-matches.php | 61 |
| tests/match-review-presentation.php | 214 |
| Native matching/manual review/HTTP charset | 49 |
| Existing security regression | 31 |
| Existing dashboard-access regression | 28 |
| Existing Cognito compatibility regression | 76 |
| Actual Chromium review/bulk layout and confirmation checks | 57 |
| Total | 516 |

Bulk tests create/drop only their own random localhost disposable database using current migrations. They cover exact name/email normalization, Unicode whitespace, A-only identity boundaries, native Self membership, parent/child separation, missing/invalid/nonexact fields, household email with different names, repeated waivers, duplicate Stripe accounts, existing people, duplicate-existing-person exclusion, DOB/minor conflicts, contradictory evidence, confirmed/rejected/deferred decisions, automatic-link promotion/reopen, accepted-link/cache drift, audit provenance, dependent undo, stale source/hold changes after preview, actual second-connection mutex exclusion, injected partial failure/rollback/retry, idempotent receipts, inactive users, viewer/analyst denial, direct unauthenticated access, CSRF, tampered counts, valid confirmed POST and duplicate POST, plus dashboard DENY.

The initial drift fixture tried a direct cache update; the existing database trigger correctly rejected it. The fixture was corrected to inject a missing authoritative link in its isolated test DB without disabling any trigger. The passing final run includes this guard. No production writes or matcher writes were used for tests.

Browser tests use synthetic rendered fixtures with fake session tokens and external CDN blocked. Desktop/tablet/mobile/narrow layouts and existing review forms remain usable; desktop/mobile bulk count panels and required confirmation controls were inspected. No review POST is sent by browser layout tests. Real confirmation HTTP tests run only against the disposable PHP server/database. UTF-8 escaping, local asset availability and the prior visual repair remain protected. Screenshots/logs are ignored under .local; temporary Playwright automation follows the Playwright skill.

Run with existing localhost-only credentials:

```powershell
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '33317'
$env:DB_USERNAME = '<LOCAL_TEST_USER>'
$env:DB_PASSWORD = '<LOCAL_TEST_PASSWORD>'
php tests/bulk-exact-matches.php
php tests/match-review-presentation.php
$env:BMA_NATIVE_MATCH_DB_TEST = '1'
php tests/matching.php --dry-run-only
git diff --check
```

## Files and deployment boundary

Runtime:

- app/Matching/BulkExactMatchService.php (new)
- app/Matching/MatchReviewService.php
- src/IdentityAssociations.php
- public/admin/_common.php (navigation context only)
- public/admin/matches.php (stylesheet version only)
- public/admin/bulk-matches.php (new)
- app/Views/match-review.php (preview link)
- app/Views/bulk-match-review.php (new)
- public/assets/css/match-review.css

Tests/documentation: tests/bulk-exact-matches.php (new), tests/match-review-presentation.php, docs/bulk-exact-matches.md (new). No schemas, migrations, credentials, web-server configuration, automatic scoring or source importers are changed.

Deployment must compare existing originals, retain production-only formatting and create a new private verified backup before installing only these nine runtime targets. Keep both earlier backups intact. Do not execute a production confirmation or matcher during deployment. The separately documented pre-existing migration 011 consolidated-snapshot checksum issue is unchanged.

## Production counts and access limitation

The owner's stated 212 production candidates (104 possible, 108 unmatched) are context, not a measured preview result. Exact eligible/group/member/excluded production counts require an authenticated live GET /admin/bulk-matches.php; none may be inferred from the synthetic tests or earlier scorer counts. The shared browser-session tool failed to initialize during this task, so no authenticated production preview was available during local validation. Deployment evidence and any final live HTTP checks are reported separately under ignored .local/bulk-exact-deploy. Do not claim synthetic counts as production results. The administrator should open the preview after login and inspect its actual counts before separately deciding whether to confirm.

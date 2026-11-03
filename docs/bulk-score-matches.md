# Administrator bulk confirmation using persisted scores above 50%

Baseline: main at 16ff47d06d54553f85a0657a7061019b1e5b089d. Validated locally 2026-11-03. No database migration is required, and existing scoring weights are unchanged.

## Threshold and review policy

The new Matches button opens /admin/bulk-matches.php?mode=score. The existing exact-name/email button remains available as a separate policy. The score policy is score_above_half_v1; the existing policy is exact_name_email_v1. Server-session previews, digests, receipts, per-candidate audits and association audits are policy-bound. Browser-supplied policy/count/operation values cannot switch an already reviewed preview to a different rule.

The source column is identity_match_candidates.match_score DECIMAL(5,4). The query evaluates `(match_score > 0.50) AS score_above_half` in MariaDB/MySQL. There is no threshold comparison on a rounded displayed percentage, a PHP binary float, match_status=possible, or a recalculated score. 0.4999 and 0.5000 are excluded; 0.5001 and 0.5049 qualify numerically even when the rounded UI percentage is 50. Existing scores and matcher thresholds are not modified.

Numeric qualification is not proof that a proposed identity is safe. The following safety checks remain mandatory:

- Signer comparisons require a named, explicit Cognito signer role, compatible full names and no contradictory evidence, accepted-link conflict, manual rejection or hold. Differing normalized source names or saved conflicting evidence are routed to review even if a high numeric score is persisted. Missing email alone is not a rejection: one isolated compatible payer/signer proposal can qualify at 0.52 without an email match.
- Student comparisons require source_format=bma2_v1, source_name_role=student, an explicit Self relationship, and matching normalized payer/signer/student names. A known minor/future DOB, contradictory DOB, parent/guardian relationship, differing student/signer names or legacy role ambiguity prevents student approval/member creation. Source label is distinct from BMA identity; previewing still establishes no person association.
- A Self student cannot indirectly confirm a signer at or below 0.50. There must also be a persisted above-threshold signer comparison for that payer/waiver, or an already manually confirmed authoritative signer link. Missing signer evaluations are not replaced with invented scores. Protected signer decisions/hard conflicts still block the group even if the companion score is high.
- A parent can qualify as the Stripe payer and waiver signer without any child/member/parent/guardian relationship being inferred. The student comparison remains in a separate role-review bucket. Name-only legacy source fields never establish students automatically.

## Grouping and duplicate prevention

BulkScoreMatchGroups is a pure source-ID graph planner. Nodes identify Stripe customer rows and Cognito signer roles; only eligible native Self students share the signer identity node. Actual above-threshold candidate edges connect source records. Previously authorized exact normalized full-name+email anchors and existing accepted person IDs can establish additional equivalence. Email alone, telephone, address, surname and fuzzy similarity never join graph components.

Every component must have one compatible normalized full name across its sources. Conflicting names/hard evidence quarantine the component rather than choosing the largest score. Multiple Stripe accounts are allowed when exact identity anchors or a consistent accepted person establish a common identity. Competing accounts without that support are excluded. Repeated compatible waivers anchored to one payer can reuse one person, including when email is missing. Multiple isolated name-only components cannot allocate duplicate people; existing same-name people without an unambiguous accepted/exact association require manual review. Distinct full names sharing one household email remain independent unless an explicit candidate bridge makes the proposals conflict; a conflicting bridge quarantines the component and does not cause transitive household merging.

Authoritative links must agree with cached source associations. Different existing BMA people are never merged/deleted. Unique person reuse, memberships and repeated-waiver counts remain global across compatible accepted identity groups. Native Self DOB conflicts and known-minor evidence are checked across an accepted person's records, not just one candidate.

New people use the existing People/AUTO_INCREMENT allocator and get names only; no shared email, phone, address or DOB is copied. Member status/start date retain existing unknown/NULL defaults. Stripe customer, Cognito waiver and financial rows are not merged or deleted; only reviewed normalized association caches are synchronized by IdentityAssociations after approval. Original source fields, transactions, subscriptions, imports and existing audit history are untouched.

## Preview and confirmation

The preview is an authenticated admin-only GET protected by the existing Matches dashboard permission. It separately displays all stored scores above 0.50, score-qualified records excluded by protections, eligible payer/signer decisions, eligible Self-student decisions, review groups, unique new/reused BMA people, new members, identity association changes and records remaining for individual review. All-excluded counts include scores at/below threshold; rejected records are excluded from remaining open manual-review counts. Already accepted records remain unchanged even if their scores are above threshold.

Score review groups list candidate IDs and reasons, with parent/student/legacy role cases separated from conflicting identity components. The screen shows the first 50 groups and totals for the complete bounded scope. No original contact values appear in bulk receipts.

An explicit required acknowledgment, expiring server-session preview (15 minutes), authenticated CSRF-protected POST, operation nonce and displayed-count validation are required. A local vanilla-JavaScript confirmation dialog states the exact candidate, person, member, reused-person and identity-association counts. If JavaScript is unavailable the separate preview, numerical confirmation panel, required acknowledgment and server-side controls still protect the action.

Execution reuses the existing bounded snapshot, shared per-database bulk mutex and ordered InnoDB row/gap locks. Both policies use the same mutex; they cannot race separate person allocations. All eligibility, roles, scores and source/link state are reread inside one transaction and the whole-state digest is checked. Score/source/decision changes invalidate the complete operation rather than approving an unreviewed subset. Any write failure rolls back all in-transaction people, members, links, candidate decisions and audits. Repeated submissions return a single committed receipt without duplicate writes. Existing capacity limits remain unchanged (1,000 candidates and the bounded source/link tables documented for exact review).

All candidate writes use MatchReviewService.decideInTransaction and IdentityAssociations; there is no direct bulk status UPDATE. Per-candidate audits include operation ID, policy, rule, persisted score and scope references. Every authoritative association audit also includes the bulk operation and policy, with the authenticated actor/time. Ordinary manual audits keep their original shape outside a bulk transaction. Existing promotion snapshots, undo safeguards, accepted decisions and rejection/defer precedence remain intact.

## Validation results

| Suite | Passed assertions |
|---|---:|
| Combined exact + score disposable regression | 122 (66 existing exact + 56 score checks) |
| Presentation/escaping/field contract | 218 |
| Native matching/manual review/HTTP charset | 49 |
| Security | 31 |
| Dashboard authorization | 28 |
| Cognito compatibility | 76 |
| Actual Chromium review/exact/score UI | 72 |
| Total | 596 |

The score cases cover stored values below/equal/above 0.50, rounded-50-but-qualified values, missing emails, signer-only boundaries, native adult Self members, a signer exactly at the threshold, missing signer evaluation, existing manual signer acceptance, parent/child separation, legacy roles, repeated waivers, duplicate payer accounts, name-only ambiguity, competing payers, transitive household bridges, independent names sharing email, known minors, missing signer names, contradictory Self DOBs, conflicting accepted people, holds/rejections, accepted person reuse across email groups, audit provenance for each association, idempotent replay, cross-policy replay/tampering, changing scores after preview, partial-write failure/rollback/retry, unauthenticated/direct-role denial, missing/invalid CSRF, and actual confirmed HTTP POSTs against disposable data only. The combined harness retains the previous exact-policy tests including concurrency mutex and undo coverage.

GET previews are verified by complete relevant-table hashes before/after. Test databases are random, prefix-guarded and localhost-only, initialized from existing migrations and dropped afterward. Actual matcher calls in the separate native regression use --dry-run only. No production writes are used for tests.

Chromium rendered desktop, tablet, mobile and narrow review layouts with the external CDN blocked. The new score preview, 15 count cards, exclusion groups and numerical dialog were checked at desktop/tablet/mobile widths. The dialog was opened and cancelled; no browser layout test sends a review POST. Desktop/mobile screenshots were inspected. Source values remain escaped and no mojibake/overflow/JavaScript/resource failures were found. Artifacts remain ignored under .local/match-review-ui; Playwright scripts stay in the operator temporary directory. Local fixtures and counts are synthetic, not production measurements.

Reproduce with existing localhost-only development credentials:

```powershell
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '33317'
$env:DB_USERNAME = '<LOCAL_TEST_USER>'
$env:DB_PASSWORD = '<LOCAL_TEST_PASSWORD>'
php tests/bulk-score-matches.php
php tests/match-review-presentation.php
$env:BMA_NATIVE_MATCH_DB_TEST = '1'
php tests/matching.php --dry-run-only
git diff --check
```

## Exact file inventory

Runtime:

- app/Matching/BulkScoreMatchGroups.php (new pure score/source-ID graph planner)
- app/Matching/BulkExactMatchService.php (policy selector and shared execution)
- src/IdentityAssociations.php (code-only bulk provenance on association audits)
- app/Views/bulk-match-review.php (score counts, groups and confirmation)
- app/Views/match-review.php (separate score button; exact button retained)
- public/admin/bulk-matches.php (policy-bound admin/CSRF/session flow)
- public/admin/matches.php (stylesheet version only)
- public/assets/js/bulk-match-review.js (new numerical dialog)

Tests/docs: tests/bulk-score-matches.php (new), tests/bulk-score-cases.php (new), tests/bulk-exact-matches.php (shared harness hooks), tests/match-review-presentation.php, docs/bulk-score-matches.md (new).

No MatchScorer/IdentityMatcher weights, authentication providers, credentials/configuration, web-server configuration, SQL schema/migrations, importers or financial/reporting logic were changed. The earlier native-role and visual fixes remain in place. The pre-existing migration 011 consolidated-schema checksum issue remains separate and unchanged.

## Production rollout and counts boundary

Deploy only the eight runtime targets after comparing the originals, creating a new private timestamped backup, recording absent new targets and verifying hashes. Keep all previous backups. The new helper must precede its caller; association audit support must precede execution, and the new JS must precede the dialog UI. Do not perform a full Git pull/reset on production. No production confirmation or matcher run is part of deployment.

Production counts must come from a signed-in live GET /admin/bulk-matches.php?mode=score. The owner's 212/104/108 baseline is context only: neither the above-0.50 count nor eligible/created/excluded counts can be inferred from it. The existing-session computer-use tool and alternate installed connection failed to start with the Windows sandbox helper error during this task. A fresh browser is unauthenticated. Until an authorized session is available, actual production score/eligible/people/member/excluded counts and a before/after production preview data comparison remain unverified. Never substitute synthetic fixture counts or claim an authenticated preview passed from file upload alone. Deployment and public GET evidence are recorded separately under ignored .local/score-bulk-deploy.

The administrator will open the read-only preview after login, review its counts and groups, and separately authorize/submit any production confirmation. This task must not click Confirm.

# Person-centered identity review

The regular `/admin/matches.php` queue groups compatible people/roles before search, status filtering and 20-group pagination. Previously a unique candidate for every customer/waiver/comparison-role combination produced six forms for three adult waivers. Original candidates, sources, financial accounts and audit entries remain intact.

## Group rules and counts

`IdentityReviewGroups` projects the existing `BulkScoreMatchGroups` graph. Stable source IDs, exact normalized full name plus email, compatible accepted BMA associations and score-qualified payer/signer edges provide anchors. Different names sharing household contact information are not equivalent identities. Candidate confidence is the stored decimal; eligibility is strictly greater than 0.50, never rounded before comparison. Display percentages use one decimal place.

Native explicitly labeled students stay separate from signer/payer proposals unless the existing adult Self safeguards pass. Repeated student names within a compatible payer component may share a proposal, subject to DOB and accepted-ID consistency. Conflicting DOBs/accepted person IDs, legacy unresolved roles, rejected comparisons and manual holds require separate review. Known ambiguity and cache/link drift are visible and block unsafe confirmation. Source role labels do not verify a BMA identity.

Stripe customer records do not currently have a person-owned phone field. Phone matching is therefore unavailable, not simulated. Contact phone can be searched/displayed as unverified waiver contact; grouped evidence suppresses historical `exact_phone` claims. No phone values are invented or used as identity anchors.

Cards contain account IDs, customer-grain transaction counts, waiver/submission IDs, candidate IDs, score range, signer/student/unresolved counts, linked BMA ID, status and expandable stored evidence. Transaction counts are informational per group: a parent and child can each show the same payer's count. They are not additive financial totals. Counts are queried as `GROUP BY stripe_transactions.customer_id` before presentation; transactions are never joined to waivers/candidates. No subscriptions or transactions are reassigned by display.

The five workload cards are dataset-wide: groups still needing decisions, raw candidates represented, unique waivers, existing linked BMA people and conflict groups. Filters affect complete groups rather than splitting a person's repeated waivers; totals remain labeled workload counts. Confirmed role links remove redundant comparisons from open workload. Rejected/held records remain available under their statuses and in raw history.

## Explicit review workflow

1. A group action opens `/admin/group-match.php` as a SELECT-only preview. The server lists exact affected candidates/accounts/waivers, excluded candidates, projected new/reused people and members. No identities are created by GET.
2. The session retains actor, action, group/selection, whole-state fingerprint, random operation ID, count and a 15-minute expiry. An explicit acknowledgment, CSRF token and exact displayed count are mandatory. A confirmation dialog can cancel submission.
3. Same-person decisions delegate to the existing score-policy bulk writer with a server-controlled candidate subset. Conflict analysis still sees the **entire** snapshot; filtering cannot hide conflicting records. Existing per-candidate association/audit/reopen behavior is retained.
4. Native parent/student review requires matching payer/signer source names, a supported explicit parent/guardian label, safe above-50% signer evidence or prior confirmed signer comparison, above-50% unresolved student comparisons, consistent DOB/roles and authoritative accepted links agreeing with caches. Any affected manual rejection/hold blocks the operation. Existing ambiguous student-name proposals require individual BMA selection. The administrator explicitly confirms that payer equals signer but is separate from this child.
5. The existing reviewed relationship writer creates missing reviewed people inside its own audited candidate operation, reuses selected/cached BMA IDs across all waivers, confirms role links and creates only the student's membership. Payer, parent/child or guardian edges use the existing deduplication writer. Signer companion decisions are included explicitly in the preview. It never infers a member start date or copies household contact details onto a new child identity.
6. A shared database mutex, ordered row locks, current active-admin check and one InnoDB transaction protect each operation. Stale previews fail before mutation. Parent previews are rebuilt under lock; same-person previews use the existing scoped locked plan. Any failure rolls back identities, members, relationships, candidate statuses and audits. Operation receipts make repeat POSTs idempotent; every underlying candidate retains its individual audit. Relationship audits include structured group operation/fingerprint context. Existing dependency-aware reopen remains available in raw review; groups are not new persistent identity records.

`?view=raw` preserves the seven existing individual actions and audit histories. A group's individual-review link restricts that view by prepared candidate-ID predicates; search/pagination retain raw/group selection. Conflicts, holds and existing-person selection are deliberately resolved there rather than bypassed in grouped confirmation.

## Security and compatibility

All routes require the canonical active application-session administrator and Matches dashboard permission. Shared CSRF, session and generic-error handling remain in use. New source output is escaped; JSON evidence is interpreted through existing renderers, not printed raw. No source raw JSON, medical fields or new identity-scoring fields are loaded. No migration, dependency, production configuration or authentication architecture change is required. PHP 8.2, MariaDB/InnoDB and normal cPanel hosting remain supported.

The existing bounded preview limits remain: 1,000 candidates, 10,000 customers/waivers/people/members, 30,000 identity links/relationships. Over-limit data fails closed; this change does not introduce an unbounded write batch. Transaction counts are a separate read-only aggregate and are not part of the identity decision fingerprint.

## Validation (2026-11-03)

- Disposable localhost database only: `tests/grouped-matches.php`, including existing exact/score checks and grouped projection, self/parent/sibling, repeated waiver, customer-grain transaction, existing BMA, holds/rejections, DOB/conflict, threshold boundary, mutex/concurrent stale decision, rollback/audit, idempotence and HTTP authorization/CSRF checks.
- Existing native/student/security/dashboard-access/Cognito tests: `tests/matching.php --dry-run-only` with `BMA_NATIVE_MATCH_DB_TEST=1`; no matcher write mode.
- Existing presentation regressions: `tests/match-review-presentation.php`.
- Actual visible Chromium rendering of synthetic local HTTP fixtures with production CSS/JS; desktop/tablet/mobile/narrow viewport, expanded evidence, long/untrusted names, empty state, navigation, precise group preview and canceled confirmation. Screenshots were visually inspected. Initial missing wrapper styling was corrected and compact evidence-icon assertions added.
- Results: **171** grouped/exact/score database and HTTP assertions, **218** presentation assertions, **49** native matching assertions plus **31** security, **28** dashboard authorization and **76** Cognito compatibility assertions, and **112** visible-browser assertions: **685 passed**. All **13** changed PHP files linted; `git diff --check` passed.

Synthetic example: three adult self-waivers/six candidate comparisons -> **one group, one person, one member** after explicit local confirmation. Three child waivers -> **two role groups**, then **one payer, one child/member, one payer edge**, all three waivers retained. These are test outcomes, not production counts.

Production authenticated counts and outstanding conflict details require an authenticated read-only visit to `/admin/matches.php`. Fresh unauthenticated requests cannot read them. The existing-session browser runtime was unavailable; do not substitute fixture counts, old operator totals or anonymous login responses for measured production data. No production confirmation is authorized by deployment or performed by this validation.

## Changed files

Runtime: `app/Matching/BulkExactMatchService.php`, `app/Matching/MatchReviewService.php`, `app/Matching/IdentityReviewGroups.php`, `app/Matching/GroupedMatchReviewService.php`, `app/Views/match-review.php`, `app/Views/grouped-match-review.php`, `public/admin/_common.php`, `public/admin/matches.php`, `public/admin/group-match.php`, `public/assets/css/match-review.css`, `public/assets/js/group-match-review.js`.

Tests: `tests/bulk-exact-matches.php`, `tests/native-student-matching.php`, `tests/grouped-matches.php`, `tests/grouped-match-cases.php`. Documentation: this file and the linked update in `docs/match-review.md`.

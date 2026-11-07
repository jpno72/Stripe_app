# Finalize People & Members

Administrator route: `/admin/finalize-members.php`, linked from Identity Matches. Canonical active application-session administrator and Matches dashboard permission are required for both GET and POST. cPanel Directory Privacy is not an application identity provider.

## Existing approvals and reuse

Current identity approvals immediately create/reuse BMA people, confirm Stripe/customer and waiver signer/student scopes through IdentityAssociations, and record explicitly verified payer/parent/guardian relationships. There is no separate persisted queue of approved-but-unallocated people. Finalization therefore reuses these existing immutable BMA IDs and never manufactures another person or infers an approval from score, household contact, nickname or subscription. Normal approved data consequently has zero people and zero source associations/relationships left to create. Missing/inconsistent/held/unresolved scopes are excluded for identity review instead of repaired with guesses. Existing verified people, account/waiver-role counts and relationships are listed separately in the preview.

One individual can retain several customer accounts and waivers. People allocation continues to use AUTO_INCREMENT plus the immutable BMA identifier in the existing approval service. Subsequent sibling/account reviews reuse the compatible already verified payer; competing accepted BMA IDs require separate administrator review. Curated nickname and middle-initial safeguards are unchanged.

## Identity is separate from membership

`student_verified` confirms a person/waiver participant, not academy membership. `member_verified` separately attests an actual academy membership relationship; for compatibility it also attests student identity. Bulk/exact/score identity approval, raw role linkage, creation of a separate person and reviewed household identity assignment no longer automatically create member rows. A historical waiver or parent subscription does not establish the participant's membership. No existing member records or prior decisions are deleted or silently reclassified.

The new finalization page offers participation outcomes (membership undetermined, waiver-only, trial attendance without established membership) and established enrollment statuses (`unknown`, `active`, `inactive`, `former`, `trial`). A student must be explicitly selected, have a consistent manually confirmed student role, and have an evidence/reason note. A member insert/status change also requires a separate established-membership checkbox. Trial attendance alone remains an audited participation review without a member row; explicitly established trial enrollment uses the existing `trial` status, never `active` by inference. The established relationship can be verified while its current status is still `unknown`.

A verified payer/signer without a student identity cannot receive a member record. Existing members cannot be downgraded to waiver-only or deleted in this workflow. Unknown legacy statuses remain visible for review. Member start/end dates, programs, dues, subscriptions and original external data are never inferred or changed by finalization.

## Preview and explicit confirmation

GET and selection-preview POST are read-only database operations. They use the existing per-database review mutex and InnoDB transaction to read a consistent bounded snapshot plus prior membership-review audits. The preview shows approved groups awaiting review, unique people reused, already established source/relationship scopes, member inserts/status updates supported by the selected evidence, participation-only decisions and exclusions. All source values are escaped; sensitive waiver fields are not selected.

Selection POST requires CSRF. The session stores actor, random operation ID, original selections, exact count, fingerprint, backup reference and a 15-minute expiration. A separate acknowledgment and POST applies precisely the previewed changes. Production writes additionally require a nonblank operator-supplied database-backup reference. This is an operator attestation, not an automated backup or a substitute for confirming that the backup exists.

The authoritative `IdentityAssociations::finalizeMembership()` rechecks the active administrator and confirmed student scope, reuses/supplements that scope, inserts a unique member only if missing, and applies only the reviewed status. Audit records contain previous/new membership state and evidence. Participation decisions are recorded as `membership_reviewed` audit events without a new schema or an artificial member status. The latest explicit review is used for the next preview. Existing membership/relationship and identity history remains intact.

Stale previews and conflicting/held scopes fail closed. All member changes and audits share one transaction. Operation receipts make retry POSTs idempotent. Identical evidence/outcome in a fresh preview is skipped. Established status changes require a fresh explicit selection and evidence; they preserve IDs and known dates. No production confirmation is executed as part of deployment or GET verification.

## Member profiles

Existing reporting repositories continue to supply IDs, names, statuses, dates, explicit enrollment/program assignments, accepted payer accounts, payment context and permission-gated emergency contacts. Profiles additionally show verified parent/guardian identities even when the signer is not the financial payer, and display the actual membership status. All associated waiver/subscription history is available rather than silently stopping at 50/100 records.

A parent's financial context is not member revenue when another verified payer-to-student edge exists, including a sibling who does not yet have a member record. The profile checks all linked student identities as well as members before enabling exclusive attribution. Transaction-grain queries and currency/fee unknowns remain unchanged. No reporting view or migration is modified.

## Validation and deployment

`php tests/finalize-members.php` reuses the existing disposable localhost migration/fixture/HTTP harness and affected grouped/bulk tests. Additional cases cover identity-only Self and parent/sibling approvals, different surnames, account/name variants, trial/waiver participation, explicit membership statuses, unknown dates/programs, shared payer without a sibling member row, idempotence, stale holds, rollback, audit, authorization and CSRF. Profile/emergency access checks reuse `tests/member-emergency-contact.php`; native matching/security regressions use `tests/matching.php --dry-run-only`. No matcher write run, production fixture write, migration or database rebuild is authorized.

Before production changes, confirm the task-required fresh database backup and affected-file backup, verify deployed source hashes and inspect current approved state. Deploy only reviewed runtime files with the verified selective FTP process; preserve server formatting/configuration and prior backups. Never perform a production Git pull/reset/checkout. After deployment an authenticated administrator must open the read-only preview and inspect its actual counts. Lack of a signed-in session means production counts cannot be claimed from fixtures or the approximate reconciliation counts reported earlier.

No migration, new secret, Node service, daemon or VPS dependency is required; PHP 8.2, PDO and MariaDB on shared cPanel remain supported.

Validation result (2026-11-07): **244** affected grouped/bulk/finalization assertions, **184** native/security/dashboard/Cognito assertions, and **78** profile/emergency assertions passed (**506 total**). All modified/new PHP files pass lint and Git whitespace validation. Read-only FTP baselines are verified; no production uploads or data changes have occurred. Deployment remains gated on confirmation of the task-required fresh database backup. Production approval/member/participant counts have not been measured without an authenticated session.

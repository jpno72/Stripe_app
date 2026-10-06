# Match review (Step 4)

`/admin/matches.php` shows saved Stripe customer ↔ Cognito waiver candidates. It displays payer and waiver sides separately, the form's relationship label, a 0–100 confidence score, supporting and conflicting evidence, linked BMA person IDs, status, and recent decision history. Search covers names, email, Cognito phone, Stripe customer ID, and BMA person ID. Stripe customer phone is absent from the supplied CSV; the page says so rather than inventing one. Cognito contact ownership remains unverified.

## Access and deployment

The page requires an active application `admin` session through `public/admin/_common.php`. Step 11 replaced the temporary cPanel username/email mapping; IMPORT_ADMIN_USERS and MATCH_REVIEW_ADMIN_MAP are no longer used. Provision accounts privately with `bin/create-user.php --password-stdin`. Every POST decision checks the shared session CSRF token and audits the application user ID. See [security.md](security.md) and [deployment.md](deployment.md).

The public web root should be `public/`. No new table or migration is required: the existing candidate, identity-link, person, member, relationship, and audit tables support review. Apply migration 004 before opening this page. cPanel Directory Privacy may be retained as an optional outer barrier.

## Decisions

- **Confirm same person:** Select an existing BMA person ID or leave it blank to create a person from the Stripe billing name. The selected waiver role is linked to that person. A `source_name_unresolved` role becomes a student/member only through this explicit administrator action. A `Self` relationship creates a self edge only when the administrator confirms that student role.
- **Confirm payer/member relationship:** Enter existing BMA IDs for payer, signer, and student. Payer and student must differ. The student becomes a member with unknown `member_since`; a payer edge is recorded. The source parent/guardian label creates its respective edges only if the administrator checks the form-verification box. Use the source form and other records to verify these assignments.
- **Link to existing person:** Select the Stripe payer, Cognito signer, or Cognito student side and enter a BMA ID. The candidate stays in review until its overall interpretation is decided.
- **Create new separate person:** Create distinct payer and waiver-role people from their respective source names, with no shared email or phone copied into `people`. The same-person suggestion is rejected. On an unresolved student role, the new student becomes a member with an unknown start date.
- **Reject proposed match:** Reject the candidate without creating a person or relationship.
- **Mark for later review:** Keep the candidate in the review queue and protect that manual hold from matcher reruns.
- **Undo / reopen:** Reverse the latest Step 4 decision on that candidate, including only the links, member, and relationship edges created by that decision. Newly allocated BMA people remain as historical records, unlinked if appropriate. Reopening preserves all audit rows and places the candidate on manual hold. If linked records changed or a later active review depends on this decision, reversal fails; reopen the dependent decision first. A prior Step 3 decision without a Step 4 snapshot cannot be undone through this page.

Each action uses a confirmation dialog and one database transaction. `IdentityAssociations` remains the writer of accepted links, cached foreign keys, membership, and relationships. Existing accepted links to another person cause an explicit conflict; the page does not silently reassign them. Candidate decisions record the authenticated admin ID, UTC timestamp, previous and new status and person associations, action, notes, and created entities in `audit_log`. History is never deleted. The page shows the 20 most recent decisions; older records remain in the database. Notes can contain sensitive context, so enter only information needed to explain the decision.

## Status and matcher interaction

Open queue includes `possible`, `unmatched`, and `conflict`. Confirmed and rejected candidates remain searchable with status filters. A manually deferred, partially linked, or reopened candidate has `match_reason=manual_review_pending`. The matcher preserves that row and will not automatically attach its signer; a manual decision must resolve it. Scores and evidence are retained when a candidate is held. Review actions do not recalculate a score.

## Focused validation

On a disposable database populated from the four supplied shelled CSVs, the page query found the two saved candidate roles. Tested search and rendered HTML escaping, defer/reject/reopen, preservation of a manual hold across a matcher rerun, same-person confirmation and undo, separate-person creation and undo, linking an existing person and undo, payer/member relationship and undo, and actor/before/after/notes audit history. The separate-person and relationship decisions were synthetic administrator choices over normalized records imported from the CSVs in the **test database only**; they are not assertions about the real people in the CSV. A separate adult self-payer fixture confirmed student/member and self relationship creation and undo while preserving the payer and signer links that existed before the action. PHP syntax checks passed. No Step 5 work was started.

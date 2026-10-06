# Step 4 final validation

> Historical stage evidence. Step 11 application login and roles supersede temporary cPanel authorization; see security.md and deployment.md.

Validation used existing disposable MariaDB databases on 2026-10-04. No production data was changed, no database was rebuilt, and no reporting view was created. The Step 4 workflow checks in [match-review.md](match-review.md) and the 17 passing Step 3 matcher checks were reused without rerunning them.

| Check | Result | Evidence |
|---|---|---|
| cPanel mapping and server authorization | PASS | A cPanel username mapped to a viewer returned HTTP 403; a username outside `IMPORT_ADMIN_USERS` also returned HTTP 403. An active mapped admin rendered the page. |
| CSRF | PASS | An authenticated POST with an invalid match-review token returned HTTP 403 before the decision handler. |
| Untrusted source text | PASS | A synthetic `<script>` value in a Stripe customer name rendered as escaped text and was then restored. |
| Approved payer/student decision | PASS | The test review kept payer, signer, and student as three distinct people, wrote three accepted authoritative role links, synchronized the normalized person foreign keys, created one payer edge and one member with `member_since=NULL`, and recorded actor, before/after state, action, and notes. |
| Repeat decision | PASS | Repeating the approved decision was refused; identity-link, relationship, member, and decision-audit counts remained unchanged. |
| Dependent undo | PASS, reused | The Step 4 workflow test blocked reopening a decision used by a later review, then allowed reverse-order reopening. |
| Older unsnapshotted decision | PASS | Reopening a Step 3 confirmed candidate without a Step 4 snapshot returned `NO_REVERSIBLE_REVIEW_DECISION`; identity links were unchanged. |
| Database integrity | PASS | Zero external roles had multiple accepted links; zero relationships referenced missing people; zero cached associations lacked a matching accepted link; zero accepted links disagreed with their cached association. |
| Reporting readiness | PASS | A test payer with two members and one 6,000-minor-unit transaction produced 6,000 with an accepted-link query using `EXISTS`; a direct join to both payer edges produced 12,000. Eight possible/rejected link-history rows were excluded by the accepted-status filter. |

**Blockers:** None found for Step 5.

**Known limits:** The supplied Stripe customer CSV has no phone field. Cognito contact ownership and its `Name` role remain unresolved until reviewed. Step 3 decisions made before Step 4 audit snapshots cannot be automatically undone by the review page; it refuses those reversals. Reporting queries must select `automatic`/`confirmed` identity links and avoid joining transaction facts directly to multiple payer/member edges when summing money.

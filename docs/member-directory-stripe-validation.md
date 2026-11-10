# Directory and Stripe-association validation

Focused validation used the existing private post-migration restore, not production write-mode tests. The restored baseline contains 328 people, 34 members, 1,282 transactions and the existing identity/waiver history. Test fixtures were removed; original business-table row fingerprints match that backup.

| Check | Result / evidence |
| --- | --- |
| Complete directory | PASS — pages beyond row 50 and complete unique traversal; 25/50/100/250 sizes, total/filtered counts, ten allowlisted sort columns in both directions. |
| Scope and filters | PASS — Members Only agrees with actual members; All People includes nonmembers. Has/no customer, subscription and payments partition the same scope; BMA search, literal SQL-like search, age bounds/unknown DOB, explicit program and resetting filters checked. |
| Customer / payer review | PASS — imported lookup, separate payer/student identities, controlled new-payer creation, two siblings, ownership conflicts, explicit correction, manual rejection precedence and removal preserving other students. Confirmed link metadata is reused rather than needlessly rescored. |
| Subscriptions | PASS — existing member required; source customer/billing untouched; current/shared assignment approval, same-day edit with full audit snapshot, older assignment closure/history and removal tested. |
| Payment allocation | PASS locally with 012 — PaymentIntent/charge lookup, one current allocation, explicit competing-recipient correction, removal preserving source ownership, guest/unassigned payments and exact source-field preservation. No duplicate financial rows; household context remains unallocated. |
| Financial refresh | PASS — unique totals, paid/failed/full-refund counts, grouped charge descriptions, paid gross and collections-before-fees correct; no double subtraction; fees remain unknown. Explicit allocations do not enable whole-member revenue cards. Existing global reporting views remain transaction-grain and unchanged. |
| Transactions / audit | PASS — stale previews rejected, repeated operation token returns existing receipt, changed token payload rejected. Actor/reason/action/before/after snapshots preserved. Membership count/status not changed by association editing. |
| Security | PASS — canonical active admin plus Members permission required, viewer/analyst/staff denied editor, no edit controls for read-only roles, missing/invalid CSRF returns 403, source HTML-like names escaped, no injected dialogs or browser errors. Anonymous directory API returns 401. |
| Migration / rollback | PASS locally — only new 012 tested, empty-table rollback/reapplication passed, nonempty rollback refused. Missing table or missing migration-history entry fails closed. Existing profiles and customer/subscription review remain usable without 012. |
| Regression | PASS — 78 new targeted assertions; existing 54 profile/dashboard assertions and 78 profile/privacy/access assertions. No full project suite rerun. |
| Actual application | PASS — 33 authenticated browser checks, numbered next/previous controls, header sort, filters retained across pages, exact scope totals, preview/confirm/verify/remove, financial refresh, 1440px and 390px directory/editor layouts. |
| PHP / JS / whitespace | PASS — scoped PHP lint, new/affected JavaScript syntax checks and Git whitespace validation. |

The historical migration comparison found only LF/CRLF differences for 006–011. Private copies matching stored checksums passed the migration inventory check without changing original SQL or history. No historical migration was replayed or production schema modified.

Production deployment installs code only. **Direct payment allocation remains disabled until migration 012 is separately authorized and applied.** The migration and guarded rollback utility are supplied in Git with backup/preflight instructions in [the feature documentation](member-directory-stripe-associations.md). Production associations are not created or edited by deployment. Authenticated interaction tests above used the disposable local application; live unauthenticated access checks and deployed hashes are recorded separately in the private deployment manifest.

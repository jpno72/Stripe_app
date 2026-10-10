# Individual member profile

Step 10 supplies `/members/{numeric members.id}`, `/members/{BMA person ID}`, and `/member.php?id=...`. Email is never a URL identity. Existing Membership and Revenue member links open this profile.

## Access and editing

The page and paginated transaction API require an active application session. Authorized viewers can read member contact and financial data; identity details require an active application administrator. Step 11 replaced the temporary cPanel allowlist/mapping. There are no source-data editing endpoints; GET is required. Manual relationship corrections use the existing CSRF-protected, transactional, audited Matches workflow.

## Sources and financial scope

Member status, factual tenure and derived age come from member reporting views. Contact is the member's own normalized person record. Accepted authoritative identity links determine Stripe customer and Cognito student/signer associations. No email, phone or name matching occurs while displaying a profile.

Subscriptions require explicit `subscription_members` assignments. Historical assignments are labelled; recorded item prices, quantities and monthly equivalents retain their documented amount basis and currencies. Next billing is unavailable because the schema has no reliable next-charge date. Current period end is labelled separately. Program labels are taken only from current explicit assignments. Latest 100 assigned subscriptions are shown.

Lifetime member financial cards require exactly one payer whose scope contains exactly one member. Shared or multiple payer associations leave those cards unavailable. Related payer totals, chart and ledger remain useful but are prominently labelled unallocated context, never member revenue. Transaction scope uses EXISTS, avoiding row multiplication. A transaction unlinked to an accepted payer does not appear.

Amounts are currency minor units, not major-unit dollars. Currencies are never summed together. A currency selection applies consistently to totals, chart and ledger. With several currencies and no selection, separate totals are shown and the chart requires a currency. Unknown currency, refunds or fees remain unresolved. Net is gross minus recorded refunds and fees, never profit. Paid/captured charge rules come from `vw_transaction_summary`; failed original charges appear in the ledger but not paid totals. Undated paid charges remain in lifetime totals and are excluded from the local calendar monthly chart. First/last payments refer only to recorded dated charges.

## Waiver privacy

Latest 50 waivers require an accepted student link. Reviewed signer identities and source printed signer text are distinguished. Relationship text is labelled source information; it does not create relationships. Unresolved source timezone or interpretation stays unresolved. Goals, medical and special-consideration fields are excluded by the approved retention mapping; the page displays unavailable states and a separate sensitive-data section. It never reads staging JSON, birth dates, medical values or excluded fields. Latest 100 identity links are visible only to mapped administrators; review evidence and free-form notes are not dumped into this page.

## Implementation and validation

`MemberProfileRepository` queries views and normalized associations; `MemberProfileService` owns financial attribution and source selection. Shared layout/components, Bootstrap, Chart.js and server-paged DataTables provide presentation. No schema migration or authentication implementation was required. `tests/member-profile.php` requires a disposable test database and rolls back temporary scenarios. See `step10-validation.md`.

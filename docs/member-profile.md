# Individual member profile

Step 10 supplies `/members/{numeric members.id}`, `/members/{BMA person ID}`, and `/member.php?id=...`. Email is never a URL identity. Existing Membership and Revenue member links open this profile. `/member.php?person={people.id}` and BMA-ID routes also open people without member records. Members navigation opens the existing profile page’s bounded people lookup; search accepts name, BMA ID, email or phone, with literal prepared values and escaped output.

## Access and editing

The page and paginated transaction API require an active application session. Authorized viewers can read member contact and financial data; identity details require an active application administrator. The effective Members dashboard permission is required for every profile; emergency contacts additionally require a canonical admin or staff role. Step 11 replaced the temporary cPanel allowlist/mapping. There are no source-data editing endpoints; GET is required. Manual relationship corrections use the existing CSRF-protected, transactional, audited Matches workflow.

## Sources and financial scope

Member status and factual tenure come only from membership records. Age is calculated from the canonical person DOB on the application-local date, including for nonmembers. Contact is the person's own normalized record; latest nonblank accepted-waiver contacts are shown separately with source ID, unverified ownership, and warnings about differing historical values. No contact fallback writes to people. Accepted authoritative identity links determine Stripe customer and Cognito student/signer associations. No email, phone or name matching occurs while displaying a profile.

Subscriptions require explicit `subscription_members` assignments. Historical assignments are labelled; recorded item prices, quantities and monthly equivalents retain their documented amount basis and currencies. Next billing is unavailable because the schema has no reliable next-charge date. Current period end is labelled separately. Program labels are taken only from current explicit assignments. Existing assigned subscriptions and unallocated payer subscription references remain separate.

Lifetime member financial cards require exactly one payer whose scope contains exactly one member. Shared or multiple payer associations leave those cards unavailable. Related payer totals, chart and ledger remain useful but are prominently labelled unallocated context, never member revenue. Transaction scope uses EXISTS, avoiding row multiplication. A transaction unlinked to an accepted payer does not appear.

Amounts are currency minor units, not major-unit dollars. Currencies are never summed together. A currency selection applies consistently to totals, chart and ledger. With several currencies and no selection, separate totals are shown and the chart requires a currency. Unknown currency, refunds or fees remain unresolved. Net subtracts refunds on paid records and known same-currency fees, never profit. The profile separately reports all source-recorded refunds (including fully refunded records), but never subtracts fully refunded amounts twice. Collections before fees subtract only refunds on successful charges from paid gross. Paid/captured charge rules come from `vw_transaction_summary`; failed original charges appear in the ledger but not paid totals. Undated paid charges remain in lifetime totals and are excluded from the local calendar monthly chart. First/last payments refer only to recorded dated charges.

## Waiver privacy

All associated waivers require an accepted student link. The history summary shows count and earliest/latest recorded submissions; unresolved source timezones are explicitly retained. DOB is selected and shown only for an application administrator; ordinary profile/API projections exclude it. Reviewed signer identities and source printed signer text are distinguished. Relationship text is labelled source information; it does not create relationships. Unresolved source timezone or interpretation stays unresolved. Goals, medical and special-consideration values are not added to normalized tables by this task; the established CSV importer retention mapping remains unchanged. Original approved submission timestamps may be read from staging. Existing private consolidated source information supplies goals and medical considerations only for administrators, while emergency contacts retain the existing admin/staff policy. This is read-only access to an already approved private source, not an import or new retention policy. Unverified signer/household/Stripe source evidence is displayed to administrators as proposals only, never as accepted associations. Latest 100 identity links are visible only to authenticated application administrators; review evidence and free-form notes are not dumped into this page.

## Implementation and validation

`MemberProfileRepository` queries views and normalized associations; `MemberProfileService` owns financial attribution and source selection. Shared layout/components, Bootstrap, Chart.js and server-paged DataTables provide presentation. No schema migration or authentication implementation was required. `tests/member-profile.php` requires a disposable test database and rolls back temporary scenarios. See `step10-validation.md`.


## Emergency contacts — 2026-11-01

Each accepted student-linked waiver now has its own read-only Emergency Contact subsection for authorized **admin/staff** users. Name and phone are escaped exactly as stored, including telephone formatting/extensions. An absent individual field shows “Not provided.”; two absent fields show “No emergency contact information provided.” No contact is selected as authoritative across waivers, and no parent/guardian/payer relationship is inferred. Members without accepted waivers retain the existing empty state.

`MemberProfileRepository::waivers` defaults to the original redacted projection. Only a server-provided access configuration requests emergency data; the repository independently resolves `SessionCurrentUserProvider` and checks the canonical admin/staff role plus `DashboardPermissionService::canViewDashboard(..., 'members')` **before selecting the two columns**. Analyst/viewer, inactive, unauthenticated, Members-denied or Members-disabled users never retrieve those values. Staff emergency access does not grant administrator-only identity review or Matches access. Role and override settings are not changed.

A conservative `tel:` helper accepts ordinary digits, formatting and a numeric extension, constructs a digits-only dialing URI with optional `;ext=`, and leaves the displayed source text untouched. Invalid/ambiguous phone values remain safely escaped plain text. No emergency data is added to JSON, hidden fields, scripts, APIs, audit, logs or telemetry. The page sets `Cache-Control: private, no-store` after session startup, so PHP's session cache limiter cannot replace the final privacy header.

No schema migration, import or backfill is included or executed. Existing migration 010 columns are prerequisites. See [emergency profile validation and deployment](member-emergency-contact-validation.md) for tests, exact commands and the controlled cPanel file list.

## Post-migration completion

Payment summaries show unique total, successful, failed and fully refunded counts per verified Stripe account and currency. Charge descriptions aggregate successful source charges only; descriptions do not establish enrollment. The individual transaction ledger is collapsed by default. Missing fees and final net remain unknown. Shared-payer totals remain explicitly unallocated account context and must not be summed across students. No attribution is inferred for guest/unassigned payments. See [completion validation](member-profile-completion-validation.md).

## Directory and Stripe association editing

The existing People lookup now supports server-side paging, sorting, filtering and All People / Members Only. Administrators with Members permission can open Manage Stripe Associations on a profile. Imported financial data stays read-only; reviewed links/assignments use transactional preview/confirmation, conflict handling, CSRF and audit receipts. Direct payment allocations require the separately approved migration 012. Profiles show related account/direct-payment context without claiming full member attribution when explicit allocations exist. See [directory/editor documentation](member-directory-stripe-associations.md) and [validation](member-directory-stripe-validation.md).

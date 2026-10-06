# Step 8 implementation validation

> Historical stage evidence. Step 11 application login and roles supersede temporary cPanel authorization; see security.md and deployment.md.

Result: PASS. Validation used the existing disposable `bma_schema_test_installer_7bdd63ff` database. No database rebuild or migration replay occurred. Existing Step 5 view-grain, shared-payer, and interval-rate results were reused.

| Check | Result | Evidence |
|---|---|---|
| View agreement and entity distinction | PASS | Member active count agrees with `vw_active_members`; subscription active count agrees with `vw_subscription_summary`. Existing fixture snapshot: four member records, one active member, four active subscriptions, two distinct subscription customers. These are combined imported/synthetic test records, not production business totals. |
| Missing facts | PASS | Three unknown member start dates leave complete new-member count unavailable. The imported active subscription has unknown rate/currency; complete MRR remains unavailable despite known synthetic USD rates. There are no known completed member spells in the existing snapshot, so average length is unavailable. |
| Subscription/member grains | PASS | Status and product-mix counts each partition subscriptions once. The member table has one row per member. Shared payer last-payment values remain unallocated; shared subscription rates are unavailable per member. |
| Focused PHP checks | PASS | `tests/membership.php`: 19 checks for view agreement, scopes, completeness, product filtering, table search, fractional rate formatting, rejected invalid input and empty scopes. Two existing records were changed temporarily inside a rolled-back transaction to test undated/dated cancellations and a 366-day completed spell. No synthetic records were added or persisted. |
| Desktop browser | PASS | 1440 px: ten cards including retention, four member rows and two available charts. The factual-date event chart correctly has an empty state. Annual Program filter yields one member, one subscription and 1,000 USD minor units/month from a synthetic annual subscription. No document overflow. Table search by BMA ID returns one row; no browser page errors. Empty-chart containers collapse rather than leaving blank chart space. |
| Empty scope | PASS | An unmatched member-status filter produces no members/subscriptions, an empty table and no chart data. |
| Mobile browser | PASS | 390 px: no document overflow; table scrolls within its container, cards and filters stack. Navigation opens and closes via Escape. Desktop/mobile screenshots inspected. The browser script initially attempted to click the covered header toggle while the navigation was open; using its existing supported Escape close action passed. |
| Access and shared shell | PASS | Unauthenticated page and API requests return 403; authorized requests return 200. Dashboard, Revenue and Imports still return 200 under the existing cPanel test user. Navigation highlights Membership and reuses the shared shell. |

The supplied imports establish one active Stripe subscription with unresolved currency/rate and no factual member start dates. Current active counts and program labels are supported at subscription grain; complete member historical trends, churn, and imported-data tenure/retention are unavailable. Local retention outcomes and known USD rates above originate from existing synthetic reporting fixtures.

Blockers: none. Remaining data limits are visible in the page and documented in [membership-dashboard.md](membership-dashboard.md). No Step 9 work was performed.

## Final validation review - 2026-10-04

**PASS.** Reviewed the current repository, KPI formatter, template, JavaScript, endpoint authorization and prior validation evidence. Dashboard logic has not changed since the successful checks above, so no database rebuild, test-suite rerun or additional synthetic scenarios were necessary.

| Requested final check | Result | Evidence reused / final review |
|---|---|---|
| Representative displayed KPIs vs SQL views | PASS | Active member and subscription comparisons in the 19-check test; the browser's Annual Program scope shows one active member, one active subscription and 1,000 USD minor units/month from the existing annual-rate fixture. Cards consume repository/formatter values without template business rules. |
| Principal filters | PASS | Product filter browser check and member/subscription-status SQL checks. Both query scopes use current explicit assignments and EXISTS predicates; chart distributions partition subscription counts. Table search scopes the table only, as documented. |
| Empty / unknown / unavailable data | PASS | Existing empty-scope browser/SQL results and NULL-completeness checks. Unknown member starts, active rates, shared-payer payment dates and completed-spell averages remain unavailable. Historical active-member trends and churn are not fabricated. Retention exposes known-result denominators and unresolved counts. |
| Desktop and mobile | PASS, reused | One 1440-px desktop and one 390-px mobile check, screenshots inspected; product filtering, BMA-ID search, empty states, contained table scrolling and mobile navigation passed. Shared layout remains unchanged. |
| Access and shared components | PASS | Existing 403 page/API checks and authorized Dashboard/Revenue/Imports 200 checks. Current page and endpoint still call adminConfig before database access; the Apache route targets the protected page. Existing Matches actor-map protection is unchanged. |
| Membership integrity | PASS | Existing one-row-per-member, one-bucket-per-subscription, shared-payer/shared-subscription protections and fractional-rate tests. Current joins remain at member/subscription grain. Currency groups are never added together, and subscription starts never replace member_since. |

**Remaining blockers: none.** Source-data limitations remain as documented above. No application code was changed during this final review, no previously passed tests were repeated, and Step 9 was not started.

# Step 6 final validation

> Historical stage evidence. Step 11 application login and roles supersede temporary cPanel authorization; see security.md and deployment.md.

Validated on 2026-10-04 with the existing disposable MariaDB test data. No database rebuild, import, full suite, or responsive-browser retest was performed. The prior [Step 6 shell check](dashboard-shell.md#validation) covers desktop, tablet, mobile, empty-state, navigation, and Imports rendering; the [Step 5 view check](step5-validation.md) covers SQL execution and financial row grain.

| Check | Result | Evidence |
|---|---|---|
| Direct-route authorization | PASS | With a local HTTPS/cPanel-user simulation, direct requests to Dashboard, Imports, and Matches returned 403 without an authenticated user. An allowlisted user opened Dashboard and Imports (200) but received 403 from Matches without an active-admin mapping. A mapped active admin opened all three (200). All allowed pages rendered the shared shell. This test changed no database records. |
| Deployment access contract | PASS | [Dashboard shell access](dashboard-shell.md#access) requires cPanel Directory Privacy for all of `public/`, HTTPS, an `IMPORT_ADMIN_USERS` allowlist, and the separate `MATCH_REVIEW_ADMIN_MAP` plus active `app_users` admin for Matches. Existing import and match POST CSRF checks remain in place. |
| Home KPI values and labels | PASS | Four rendered cards were compared with read-only queries against `vw_active_members`, `vw_subscription_summary`, `vw_transaction_summary`, and the latest `vw_monthly_revenue` month. Counts and gross minor units matched. The member label now says “Members marked active,” matching the view's explicit-status definition. The gross card names its currency and actual latest available month; it does not call gross profit. |
| Unknown data | PASS | The prior Step 5 check found imported subscription rate/currency and fee/net facts unresolved as `NULL`. The home page shows a dash when there are no dated paid charges or when the latest month has multiple currencies, and the existing empty-database check shows an import empty state. It does not sum currencies or substitute a zero for an unresolved amount. |
| Revenue component reuse | PASS | The shared layout owns the sidebar/header. `uiKpiCard()`, chart-card start/end around a future canvas, date-only `uiFilterBar(false, ['start','end'])`, and table-card start/end can support Step 7 without duplicating the layout. The current Revenue route remains a placeholder with disabled controls. |
| Existing navigation | PASS | Dashboard, Imports, and Matches returned 200 for an appropriately mapped admin and used `app-shell`. The previously passed responsive checks were reused. |
| Reporting-view readiness | PASS, reused | Step 5 validated all 14 views under SELECT-only privileges. `vw_transaction_summary`, `vw_revenue_by_period`, `vw_monthly_revenue`, and `vw_revenue_by_program` keep gross, refunds, fees, and net distinct, group by currency, and leave incomplete amounts `NULL`. The Step 5 shared-payer and transaction-grain fixtures already passed; no view SQL changed in Step 6. |

**Remaining limitations:** The local cPanel-user simulation does not configure hosting Directory Privacy or database grants; verify both on deployment. The latest-month card reports the latest **available** month, not necessarily the current calendar month. Actual supplied imports do not establish subscription rates/currency, Stripe fees, or program attribution, so Step 7 must show those as unknown or unassigned. The chart and filters have no analytics behavior yet.

**Blockers for Step 7:** None.

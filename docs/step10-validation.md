# Step 10 validation

> Historical stage evidence. Step 11 application login and roles supersede temporary cPanel authorization; see security.md and deployment.md.

Validated 2026-10-04 against the existing disposable MariaDB fixture database; no database rebuild or production import.

| Check | Result |
|---|---|
| Internal member ID and BMA routes resolve the same member | PASS |
| Transaction rows and gross totals agree with reporting views; no duplicated charge IDs | PASS |
| Shared payer scope cannot become member-attributed revenue | PASS |
| Exclusive payer allocation enables supported member totals | PASS — rolled-back scenario |
| Unknown membership start remains NULL; unlinked financial scope is empty | PASS — rolled-back scenarios |
| Basic profile data excludes administrator identity details; missing mapping denies review access | PASS |
| Desktop and mobile page, ledger search and shared-payer warning | PASS |
| Direct unauthorized page/API requests | PASS — HTTP 403 |
| Read-only profile rejects POST; missing member returns 404 | PASS |
| New PHP files syntax validation | PASS |

35 focused database assertions passed. Browser check had no JavaScript errors. Existing Step 4 manual-correction/audit tests and Step 5 transaction-grain/shared-payer tests remain applicable; neither suite was repeated. Desktop and mobile screenshots inspected.

## Limitations

Current fixture members have shared payer scopes; the exclusive allocation branch was checked using a rolled-back relationship scenario. No new identity decisions were committed. Medical and goals fields remain excluded; next billing date and unresolved currencies/timezones cannot be invented. Source prices are recorded snapshots, not a live Stripe quote. This release keeps the existing temporary cPanel allowlist; full application authentication remains Step 11. No schema changes were necessary.

No remaining Step 10 blockers. Step 11 was not started.

## Lightweight final review — 2026-10-04

The existing 35 database assertions and successful desktop/mobile browser checks were reused. No relevant layout, security or profile code changed; responsive and authorization tests were not repeated. Only 12 missing financial/filter checks ran, read-only, against the existing disposable database.

| Final check | Result / evidence |
|---|---|
| Representative financial values vs SQL views | PASS — reporting fixture payer: 2 paid charges, gross 21,000 USD minor units, refunds 0, fees 500, net 20,500. These appear as unallocated payer context; shared-member KPI cards correctly remain unavailable. |
| Currency consistency | PASS — USD totals/chart/ledger share the same scope; selecting EUR with no records produces empty totals, chart and ledger. |
| Chart reconciliation | PASS — fixture dated monthly gross and net sum to lifetime payer totals. |
| Ledger search / sort | PASS — failed-status search returns the failed charge, excluded from paid totals; server ordering and currency scope execute. Existing browser search/empty-result check reused. Search filters the ledger only, not lifetime cards/chart. |
| Empty / missing metrics | PASS — previous NULL tenure and unlinked-scope checks reused; empty currency adds no factual zeros. Goals, medical information and next billing remain unavailable. |
| Desktop / mobile and shared components | PASS — previous Step 10 screenshots and browser checks reused; no JavaScript errors or settled mobile overflow. |
| Access protection | PASS — previous unauthorized page/API 403, read-only POST 405 and active administrator mapping checks reused. |
| Data integrity | PASS — charge-grain and shared-payer tests reused; failed charges do not inflate paid counts or gross. Repository does not retrieve raw staging or excluded sensitive fields. |

All 12 additional checks passed. No genuine blockers identified. No database rebuild, full regression suite or new synthetic data was required. Step 11 remains unstarted.

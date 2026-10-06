# Step 7 Revenue dashboard validation

**Result: PASS.** Focused checks used the existing disposable `bma_schema_test_installer_7bdd63ff` database. This database contains imported shelled rows and clearly synthetic reporting fixtures. No database rebuild, migration replay, stress test, or Step 8 work was performed.

| Check | Result | Evidence |
|---|---|---|
| KPI values vs reporting view | PASS | September 2026 USD gross 21,000, refunds 0, fees 500, net 20,500 minor units and two paid charges agree with `vw_monthly_revenue`. These are synthetic fixture values. |
| Filters and transaction grain | PASS | The focused PHP test has 20 passing checks. Program filter shows one 12,000-minor-unit charge; payer filter preserves 21,000 across three rows including one failed charge; member filter shows only the explicitly assigned 12,000 charge. Status, date, currency, and table search remain scoped. Shared-payer charges are counted once. |
| Empty and incomplete data | PASS | An empty date range shows no payment values, charts, or transaction rows. The imported charges still have unresolved Stripe fees and net; active subscription MRR remains NULL. The page shows unavailable values, not factual zeros. |
| Desktop browser | PASS | At 1440 px, six cards, three charts, and three recent transaction rows loaded. The program filter reduced the table to one row and gross to 12,000 USD minor units. No page errors. |
| Mobile browser | PASS | At 390 px, six cards remained visible, navigation opened, and no document-level horizontal overflow occurred. |
| Access and shared shell | PASS | Direct unauthenticated requests to Revenue, its JSON endpoint, Dashboard, Imports, and Matches returned HTTP 403. Authorized Revenue, JSON, Dashboard, and Imports returned HTTP 200. Matches retained its additional actor-map requirement, returning 403 when that test mapping was absent. Revenue uses the existing layout/navigation. |
| Additional integrity | PASS | The table count equals transaction-view count for the selected period; a failed charge is visible without contributing to payment KPIs. Accepted payer/member links do not multiply amounts. Product attribution requires a direct subscription link. |

The prior Step 5 transaction-grain and shared-payer fixture results remain applicable. This validation did not repeat those suites.

**Known limits:** Imported transaction fees and subscription rate/currency remain unresolved; imported payments have no direct program assignment. The program chart covers only directly attributed charges. Currency display uses integer minor units. The complete member profile is outside Step 7.

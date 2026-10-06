# Revenue dashboard (Step 7)

Route: `/revenue` (Apache rewrite to `public/revenue.php`). The page and JSON transaction endpoint require the existing HTTPS, cPanel authenticated user, and `IMPORT_ADMIN_USERS` authorization check. The shared layout supplies navigation and cards. The transaction table pages on the server and never loads the whole charge history into PHP memory.

The dashboard reads `vw_transaction_summary`, `vw_monthly_revenue` definitions, and `vw_subscription_summary`. Money is displayed as **integer minor units plus an uppercase currency code**. It never combines currencies. The default period is the latest month with dated paid charges, in UTC. The date range, currency, product, status, and payer/member filters scope the payment KPIs, chart queries, and transaction table. The table includes unpaid rows for the same filters; payment KPIs count only paid rows. "All dates" includes all dated charges. Undated charges cannot be assigned to a reporting period.

- **Gross paid volume**: eligible paid charge amounts. This is payment volume, not earned revenue or profit.
- **Refunds recorded** and **Stripe fees recorded**: displayed only when every included paid charge has the corresponding known amount. Fees must share the charge currency.
- **Net after recorded costs**: gross minus recorded refunds and Stripe fees when both are complete. It excludes other costs.
- **Active subscription MRR**: current subscription snapshot, separate from payment dates. It is unavailable if any active rate or currency is unresolved, or if a program, transaction status, or payer filter is applied. This avoids implying that a payment filter also scopes the subscription snapshot.
- **Per active member**: unavailable until payments can be allocated to individual members without duplicating a shared payer's charges.
- **Program composition**: top 12 directly linked, single-item subscription product labels. Unlinked payments and multi-item subscriptions are excluded. Product labels are not a verified program taxonomy.
- **Recurring/non-recurring mix**: omitted because current source data cannot distinguish it reliably.
- **Transaction member link**: shown only for one explicit subscription-member assignment covering the charge date. A payer-to-member household relationship alone does not assign a charge to a member.
- **Stripe Dashboard links**: omitted because the source account/environment is unresolved; showing a guessed link could point to the wrong Stripe context.

The query starts at transaction grain and joins one-row subscription and payer views. A grouped subscription-member join is at most one row per subscription, and ambiguous member assignments are hidden. All filters use bound PDO parameters; sort columns use a fixed allowlist. JSON output escapes source text. The member drilldown currently opens a small read-only profile; the full member workspace is a later stage.

See [reporting definitions](reporting-definitions.md) for source metrics and [Step 7 validation](step7-validation.md) for focused checks.

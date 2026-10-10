# Reporting definitions (Step 5)

The views in [sql/views.sql](../sql/views.sql) use normalized records and accepted identity links. Source money is stored as `BIGINT` **integer minor units** and is grouped by lowercase currency. MariaDB returns `SUM()` aggregates as `DECIMAL`; those totals remain integer-valued minor units. Monthly equivalent amounts use `DECIMAL` minor units with four decimal places because interval conversion can produce fractions of a cent. Convert to display units only in the application. Do not add different currencies together.

## What the current imports support

The four supplied shelled exports produced one active subscription with one item, two imported paid transactions, one waiver, one non-stub Stripe customer, and a Stripe customer stub. The subscription item's amount and currency are unresolved, so its MRR is `NULL`. Neither imported transaction has a direct subscription link, so program revenue is unassigned. Stripe fees are unresolved, so net after fees is `NULL`. The supplied data does not establish membership start dates, member birth dates, or subscription-to-member assignments. The disposable Step 4 test database also contains clearly synthetic people, transactions, and relationships; those fixture values are excluded from the preceding source-data assessment.

## Member and identity views

| View | Grain and definition |
|---|---|
| `vw_member_summary` | One row per `members.id`. Person name, status, dates, and demographic fields come from the linked `people` row. A Stripe customer is not assumed to be a member. |
| `vw_active_members` | Members explicitly marked `active` whose `member_until` is absent or today/later. A subscription being active does not establish member activity. |
| `vw_member_demographics` | One row per member. Age is recalculated from `people.birth_date` as of the database date; future or absent birth dates yield `NULL`. Brackets: 0–12, 13–17, 18–24, 25–34, 35–44, 45–54, 55+. Youth is under 18. City, state, and ZIP come only from the member person's record, not a payer's or ambiguous waiver contact address. |
| `vw_member_retention` | One row per member. `retained_3_months`, `_6_months`, and `_12_months` refer to the corresponding anniversary of a **factual** `member_since`. After the anniversary passes, they are 1 if `member_until` reaches it, or if the member is explicitly active; 0 if a known `member_until` precedes it; `NULL` when the start date is unknown, the anniversary has not passed, or status/end evidence is insufficient. A Stripe subscription creation date, waiver date, or first payment is never substituted for `member_since`. This is milestone status, not a historical cohort survival curve. |

`identity_links` is authoritative. Reporting joins include only `match_status IN ('automatic','confirmed')`; possible, rejected, and unmatched evidence never creates a payer or student association. A payer/student `person_relationships` edge is a reviewed relationship, not proof that a particular subscription or transaction belongs exclusively to that student.

## Subscriptions and MRR

`vw_subscription_item_rates` has one row per subscription item. It uses the item's approved amount. `amount_basis='unit'` multiplies by an explicit quantity; `csv_reported` treats the reported amount as the whole item amount and never multiplies it again. The item currency must be explicit or resolved from its linked Stripe price, and contradictory item/price currencies make the rate unknown. The interval and interval count must be known; no default count is invented. Monthly equivalent formulas are:

| Billing interval | Monthly equivalent minor units |
|---|---|
| Month | amount × quantity ÷ interval count |
| Quarter | amount × quantity ÷ (3 × interval count) |
| Year | amount × quantity ÷ (12 × interval count) |
| Week | amount × quantity × 52 ÷ (12 × interval count) |
| Day | amount × quantity × 365 ÷ (12 × interval count) |

For `csv_reported`, the effective quantity in this table is 1. The 52-week and 365-day factors are analytical MRR conventions, not earned-revenue recognition. `vw_subscription_summary` has **one row per subscription**. Its `active_mrr_minor` sums item rates only when status is exactly `active`, all items have calculable rates, and all items have one known currency. Trialing, past-due, paused, canceled, incomplete, and other statuses contribute no active MRR. Its product name is exposed only for a single-item subscription. `subscription_started_at` is the explicit Stripe start field and remains `NULL` when the CSV only provides Stripe creation time. `explicitly_linked_member_count` uses `subscription_members`, not household contact matching.

## Payments and revenue

`vw_transaction_summary` has **one row per Stripe charge**. `is_paid=1` requires status `paid` or `succeeded` (case-insensitive) and `captured` not explicitly false. An absent capture flag with a paid status remains eligible; a false flag does not. `gross_paid_minor` is the charge's amount for eligible rows, otherwise 0. It is gross payment volume, not profit or earned revenue. `refunds_minor` is the recorded refunded amount. An absent refunded amount on a paid charge remains unknown, not zero. `stripe_fees_minor` prefers the balance-transaction fee; otherwise it uses the source fee. A fee is shown only when its currency matches the charge currency. `net_after_recorded_costs_minor = gross − recorded refunds − recorded Stripe fees` only when both components are known in the same currency. It is **not profit** and excludes other expenses, taxes, and unrelated adjustments. Original and converted currencies are never mixed.

`vw_revenue_by_period` aggregates eligible dated charges by UTC day, Monday-start week, calendar month, and calendar year, **separately by currency**. Each transaction appears once per requested grain, so compare or sum within one grain only. `vw_monthly_revenue` selects the monthly grain. Both show gross, known-component counts, complete refunds and fees, and net only when every included charge has the required components. Paid charges without `stripe_created_at` remain in `vw_transaction_summary` and payer totals but cannot enter a calendar period.

`vw_revenue_by_program` requires a transaction's direct `subscription_id`, a single-item subscription, and an item product label. It excludes unlinked transactions and multi-item subscriptions because invoice-line allocation is not available. `current_subscription_status` is the status **now**, not at payment time. The product label is a source snapshot, not a verified program taxonomy. Program totals are therefore a covered subset of revenue; do not present them as a complete allocation. A payer's active subscription alone is never used to assign a charge to a program.

## Member financial attribution

`vw_payer_member_scope` contains reviewed payer→member edges and explicit self edges. `vw_payer_financial_summary` groups eligible charges by accepted Stripe payer person and currency, without joining to members. It is the appropriate household-level financial total.

`vw_member_financial_summary` has **one row per member**. It exposes payer identity and explicit subscription information. Its money, transaction count, and payment dates are populated only when the member has exactly one payer, that payer has exactly one member, and the payer's paid transactions use one currency. A shared payer yields `financial_attribution_status='shared_payer_unallocated'` and `NULL` member money. Other statuses explain no payer, multiple payers, multiple currencies, and no linked paid transactions. This prevents a household payment from appearing as full revenue for each child. It does not allocate a household charge proportionally.

Member-level monthly equivalent is populated only for one current explicit subscription link that itself has exactly one linked member and a calculable active MRR. `subscription_allocation_status` distinguishes no explicit link, multiple subscriptions, shared subscription, unresolved rate/status, and exclusive subscription. A shared subscription's MRR remains available at subscription grain but is `NULL` on each member row. Subscription dates and product are shown only for an unambiguous single explicit link; no membership tenure is inferred from them.

## Import health

`vw_import_health` has one row per import attempt, including duplicate and failed attempts. `error_rate = error_count ÷ row_count` when row count is positive; an empty batch has `NULL` error rate. Inserted, updated, skipped, error, status, and completion fields come directly from `import_batches`. These are processing outcomes, not distinct-person counts.

## Query rules for later dashboards

Filter on currency before summing money. Use transaction-grain or payer-grain views for academy revenue; **never sum member financial rows to derive academy revenue**. Filter `vw_revenue_by_period` to one `period_grain`. Keep `NULL` values visible as unknown. If later Stripe API sync supplies invoice lines, fee currencies, or subscription-item details, revise these definitions through a new migration and update this document before adding new KPIs.

## Membership dashboard (Step 8)

The `/membership` page follows these views at member and subscription grain. Its scoped month-to-date event counts, completed-spell average, known-outcome milestone percentages, current MRR, filter scope and unsupported historical/churn metrics are defined in [membership-dashboard.md](membership-dashboard.md). Source dates are never substituted for factual member dates.

## Demographics dashboard (Step 9)

The `/demographics` page uses derived age_years with configurable display/filter brackets, known-age mean/median, explicit missing-data coverage and aggregate member geography. Its cohort/filter semantics and conservative all-date geographic gross attribution are defined in [demographics-dashboard.md](demographics-dashboard.md). It does not expose birth dates or query medical/ignored waiver text.

## Application local calendar policy — 2026-10-10

Database sessions and the existing SQL views remain UTC. Their `*_utc` columns and aggregate periods retain that meaning. User-facing revenue filters convert local inclusive dates into a half-open UTC interval; overview revenue, Revenue charts and member payment charts group verified instants by America/New_York calendar months. They do not reuse the UTC monthly aggregate as an Eastern aggregate. Completeness, currency isolation and transaction grain are unchanged. Application age, activity, factual member-start events, completed spells and retention milestones use the local as-of date without timezone conversion of DATE fields. See [time-handling.md](time-handling.md) for implementation, deployment and validation.

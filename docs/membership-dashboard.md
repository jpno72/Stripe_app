# Membership dashboard (Step 8)

Route: `/membership`, rewritten to `public/membership.php` by Apache. The legacy shell URL redirects here. The page and `/api/membership-members.php` require active application login through `adminConfig()`. Step 11 added application authentication; no membership-specific schema migration was required.

## Data and scope

`MembershipRepository` owns SQL and metric completeness rules. `MembershipMetrics` produces card values and descriptions and preserves fractional monthly-equivalent minor units. Templates render these results. The page uses the existing shared layout, cards, empty states, Chart.js and DataTables. Member rows are paged/searched/sorted on the server, with prepared filter parameters and a fixed sort-column allowlist. Source text is escaped, including chart data embedded with JSON HEX flags. No waiver free text is queried.

The page distinguishes member records, Stripe subscriptions and distinct Stripe customers referenced by subscriptions. A parent payer is not counted as a member unless an actual member record exists. Multiple subscriptions can belong to one customer. Subscriptions with no member link remain visible in the unrestricted subscription scope.

Program/product, member-status and subscription-status filters affect cards, charts, retention and the member table. Member scope uses explicit current subscription-member assignments for subscription/product filters. Subscription scope uses an `EXISTS` member assignment for a member-status filter, so multiple members do not multiply a subscription. Product filters include a subscription once if any item matches; its MRR represents the entire subscription, not an invoice-line allocation to that product. The table's search is a separate table-only search. Current filters do not reconstruct historical program membership or historical statuses.

## KPI definitions

| KPI | Rule and limitation |
|---|---|
| Active members | Count of scoped `vw_member_summary` records with explicit active status and no past member end date; uses the same status/end-date policy as `vw_active_members`, evaluated on the local calendar date. It is not derived from subscription status. |
| Active subscriptions | Scoped `vw_subscription_summary` records whose current status is exactly `active`. |
| New members this month | Factual `member_since` dates in the current local calendar month through today. If any scoped member has no start date, the complete KPI is unavailable; its description still shows the known event count and unknown-date count. No subscription/payment/waiver date substitutes for a member start. |
| Canceled subscriptions this month | Recorded `canceled_at` events in the current local calendar month through today. A currently canceled subscription with no cancellation date makes the complete KPI unavailable. This counts stored cancellation events, not ended member records or scheduled future cancellations. |
| Past-due subscriptions | Scoped subscriptions with the actual current Stripe `past_due` status. |
| Active subscription MRR | Sum of `active_mrr_minor` from scoped active subscriptions, separately by currency. Any unresolved active currency/rate makes the complete KPI unavailable. Multiple known currencies are displayed separately, with no conversion or addition between currencies. Decimal monthly-equivalent minor units are preserved, including interval conversions. Empty active scopes show unavailable. |
| Average completed membership length | Mean `DATEDIFF(member_until, member_since)` for factual completed spells ending on/before today. Displays days and the contributing-record count. Ongoing memberships and unknown dates are excluded. This is an average of known completed spells, not average tenure for all current members. |

Application calendar calculations use America/New_York (or APP_DISPLAY_TIMEZONE). Date-only membership values are unchanged; verified cancellation instants use local calendar boundaries converted to UTC. Database sessions remain UTC. Currency/rate assumptions remain those in [reporting-definitions.md](reporting-definitions.md). Current snapshots are not historical recurring-revenue estimates.

## Charts and retention

- Historical active-member trend is explicitly unavailable: isolated dates/current status do not prove complete historical membership periods. No historical active totals are fabricated.
- The last-12-month event chart compares **recorded new member starts** and **recorded subscription cancellation dates** as separate entity counts. Missing dates are excluded. Only months with at least one recorded event appear; zero on one series means no recorded event in that displayed month, not proof of complete source coverage.
- Subscription/product mix places each scoped subscription in one bucket: its single product label, Multiple items, or Unassigned. It is a subscription mix, not a count of students in each program.
- Status distribution uses actual current Stripe status values, with Unknown for NULL. Trialing/past_due/canceled/etc. are displayed when present, without fabricated categories.
- Three-, six- and twelve-month cards use the `vw_member_retention` spell policy evaluated on the local calendar date. The percentage is retained known outcomes divided by all known outcomes at that milestone. Each card exposes the denominator and unresolved/not-yet-eligible count. A zero denominator shows unavailable. These are factual milestone outcomes under the existing view definition, not a cohort survival curve. The actual imported dataset does not establish member tenure; observed milestone percentages in the local database come from synthetic fixtures.
- Churn is omitted: there is no reliable historical starting active-member/subscription population or complete cancellation history to support a period churn formula.

## Member table

One row per immutable BMA member identity. `vw_member_financial_summary` supplies reviewed payer identity, unambiguous current subscription/product, monthly-equivalent rate and last payment. Member status and start come from the factual member record. Multiple payers/subscriptions and missing program labels are marked explicitly. Shared payer payments and shared subscription rates remain unallocated, with explanatory statuses. A member's unknown last payment is not replaced with the payer's household payment. Member links open the existing protected profile.

See [Step 8 validation](step8-validation.md). Step 9 has not been started.

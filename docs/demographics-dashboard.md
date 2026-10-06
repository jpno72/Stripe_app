# Demographics dashboard (Step 9)

Route: `/demographics` (Apache rewrite to `public/demographics.php`). The legacy shell link redirects here. The page and `/api/demographics-geography.php` require existing HTTPS/cPanel user authorization before any database queries. They use the shared Bootstrap layout, Chart.js and a server-paged DataTables geographic table. No schema migration or new authentication system is required.

## Sources and privacy

Demographics come from `vw_member_demographics`, joined one-to-one to `vw_member_summary` for factual membership dates and activity. Cognito demographic evidence reaches this page only through a verified internal member/person record. The dashboard never assumes that a waiver contact, signer, Stripe payer and student are the same person, and never substitutes household billing/waiver addresses for missing student geography.

All queries select aggregate counts, derived ages, city/state/postal groups and permitted financial sums. They do not select or serialize birth dates, names, emails, telephone numbers, BMA IDs, raw waiver JSON, medical fields or sensitive waiver free text. Exact birth dates remain internal inputs to the existing age view. The geographic endpoint returns aggregate rows only. Source city/product labels are escaped in HTML/JSON; JSON embedded in the page uses HEX flags. GET filters use prepared parameters, and table sorting uses a fixed column allowlist.

The actual Cognito export has `goals` and `considerations` fields, but both remain **IGNORE** in the approved import mapping. Consequently training goals/interests are unavailable. No ignored text is reimported, normalized, scored or exposed. A future explicitly approved structured goals contract would be needed before adding those charts.

## KPI definitions and coverage

The denominator is one member record per selected scope. People who only pay are not members. Ages are whole completed years as of the database UTC date, recalculated from the existing demographic view; absent/future birth dates have unknown age.

| KPI | Definition |
|---|---|
| Average age | Arithmetic mean of known member ages only, displayed to one decimal. Missing ages are excluded and their count is shown. |
| Median age | Weighted median of the aggregate age histogram. Odd populations use the middle known age; even populations average the two middle known ages. No individual birth dates or expanded member list is needed. |
| Youth members (known age) | Known age under 18. |
| Adult members (known age) | Known age 18 or older. Unknown ages contribute to neither count. |
| Cities represented | Distinct known trimmed city/state pairs. A known city without state is a separate pair; unknown city is excluded. Member coverage is shown. |
| ZIP / postal codes represented | Distinct known trimmed, uppercased full postal-code strings. Leading zeros and ZIP+4 are retained. Country or US ZIP semantics are not inferred. |

With no known ages, age/youth/adult KPIs show Unavailable rather than invented zeros. With no known city/postal information, those KPIs show Unavailable. A zero known adult count when known ages exist is factual, with missing-age coverage visible. The page uses the database's UTC as-of date and labels it explicitly; it may differ from the local calendar date near midnight.

## Configurable age brackets and charts

`config/demographic-age-brackets.php` supplies inclusive boundaries: Under 8, 8-12, 13-17, 18-24, 25-34, 35-44, 45-54 and 55+. `AgeBrackets` validates unique stable keys, contiguous nonoverlapping bounds starting at zero, and an open-ended final bracket. These page brackets use `age_years`; they do not change the older 0-12 bucket in the SQL view. Changing labels/bounds does not store a permanent age. Keep keys stable for saved filter URLs.

Age and youth/adult charts include an explicit unknown-age category when ages are partially available. City and ZIP charts show the top ten groups plus Other locations, including unknown geography. Each chart's counts cover the selected member scope once. A chart with no known data displays an empty state. Medical/special-consideration information is never a chart dimension.

## Filters

All filters scope cards, charts and the geographic table together. The table's free-text search additionally scopes its displayed rows, without changing KPI totals or the percentage denominator.

- Activity: Active agrees with `vw_active_members` (explicit active status and no past end). Inactive/non-active includes explicit non-active, non-unknown statuses or a factual past end. Unknown includes unknown/empty status with no past end. These are current states, not historical activity estimates.
- Program/product: requires an explicit current `subscription_members` assignment and a matching subscription item label. `EXISTS` prevents multiple items/subscriptions from multiplying the member. Shared contact or payer relationships do not imply program membership.
- Age bracket: uses the configured inclusive boundaries, plus a selectable Unknown age scope.
- City: trimmed member city (SQL collation treats case-equivalent labels together); same-named cities in different states can be selected together and remain separate table groups.
- ZIP/postal code: normalized string equality, preserving leading zeros.
- Member cohort from/through: inclusive factual `member_since` dates. Unknown starts are excluded only when a cohort boundary is applied. Stripe creation, first payment, waiver submission and person-record creation dates are never substituted. Ages are still current ages, not ages at cohort entry.

## Geographic table and revenue

Rows group by normalized city and state, including unknown locations. Percent of members is group count divided by every member in the selected scope. Display rounding can make rounded percentages differ slightly from 100%. Search does not change this denominator. The table is searchable/sortable/paged on the server; attributed gross money is intentionally not sortable across currencies.

The optional financial column is **attributed gross paid volume, all dates, in integer currency minor units**. It is not profit, earned revenue, net after costs or a cohort-period payment total. A city group shows it only when every included member has `exclusive_payer` attribution, known gross, and the same known currency in `vw_member_financial_summary`, and the city itself is known. Any shared payer, missing association/money, mixed currency or unknown city leaves that group's value Unavailable. The financial view is joined one-to-one by member, and its approved exclusive-payer rule prevents household charges from being repeated for multiple children. Cohort filters select member records but do not filter payment dates.

## Validation and limits

See [Step 9 validation](step9-validation.md). Existing imported data does not establish member birth dates or locations through resolved student records; demographic values in the local populated test database originate from synthetic fixtures. No membership identities, importer mappings or original source values were changed for this page. Step 10 has not started.

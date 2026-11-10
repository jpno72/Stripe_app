# Member profile completion and dashboard validation

Validated against a disposable restore of the successfully migrated production data. No production data mutations, imports, matching, migrations or billing/API operations were performed for this task.

| Check | Result | Evidence |
| --- | --- | --- |
| New post-migration backup | PASS | Complete private SQL and gzip copies retained separately from the pre-migration backup. Restore command, decompression, all 29 table row fingerprints and inventory of 14 views / 6 triggers match the read-only production snapshot. Contains 328 people and 1,282 transactions. Private manifest records hashes and paths. |
| Every person accessible | PASS | All 328 existing people resolve by internal person ID, including nonmembers. Existing Members navigation now opens bounded search on the same profile page; no second profile system. |
| Waiver history and contacts | PASS | Accepted submissions shown once, total/first/latest summaries, latest nonblank attributed source contacts and differing-value warnings. Original person contacts and source rows unchanged. Unresolved timezone remains unresolved. |
| Restricted demographics | PASS | Admin-only DOB display; calculated age on local application date. Existing admin/staff emergency and admin medical policies retained. Viewer/API projections do not retrieve restricted details. |
| Payment summary | PASS | Verified account/currency summaries include total, successful, failed, fully refunded counts, gross, all recorded refunds, collections before fees, unknown fees/net, dates and grouped successful charge descriptions. Individual ledger available separately. |
| Family separation | PASS | Disposable, rolled-back authoritative-service fixture verifies distinct siblings and payer, no member creation or subscription assignment, and unallocated financial context. Failed/full refunds do not inflate gross or get subtracted twice. |
| Membership dashboard | PASS; unchanged | 34 actual member records versus 29 active subscriptions / 29 subscription customers. Zero members explicitly active; all 34 membership starts unknown. New-member and tenure KPIs remain unavailable. Subscription activity does not activate people. |
| Revenue dashboard | PASS; unchanged | Independent source SQL agrees with repository cards and historical monthly chart: USD 115,760.22 paid gross, 1,224 successful records; USD 171.00 partial refunds on paid records. The 17 fully refunded source records total USD 1,007.00 and contribute no successful revenue. Collections before unresolved fees USD 115,589.22. Failed/date/empty filters and transaction grain checked. |
| Unassigned payments | PASS | 453 total stored unassigned transactions, including the 425 inserted by migration and 28 pre-existing. Included in aggregate totals without assigning them to profiles. |
| Demographics dashboard | PASS; unchanged | 34 member subjects, 17 known ages, mean 28.5882, median 23, 17 adult / 0 known youth / 17 unknown age. City and ZIP coverage unknown. Charts partition members once. Coverage separately counts 311 verified student people, 290 with DOB and 277 without membership. No duplicate counting from repeated waivers. |
| Focused tests | PASS | `tests/member-profile-completion.php`: 54 assertions; rollback-only fixtures. Existing `tests/member-emergency-contact.php`: 78 checks, including its existing member-profile and dashboard-access regressions. No full project suite rerun. |
| Actual local application | PASS | 33 authenticated browser checks plus seven focused lookup checks: seven available real-data profile categories, transaction drilldown, three dashboards, 1440px/390px layouts, safe search rendering, viewer restrictions and admin-only endpoints. Screenshots and credentials remain private. |

One requested real-data category (minor with a separately verified payer) was unavailable; family separation is covered by the explicitly synthetic rollback fixture, not represented as a production relationship. Household proposals remain unapproved, unknown ownership/fees/programs/dates stay unknown, and waiver-only people are not activated as members.

Newly corrected: nonmember access, Members placeholder navigation, waiver contact/history summary, restricted DOB display, per-account payment counts/collections and administrator-only household source evidence. Already functioning and preserved: accepted links, waiver details, emergency/medical source restrictions, subscriptions, payer separation, dashboard business rules and role-based access.

Production deployment provenance and final private backup locations are retained in the private deployment/result manifests; no backups, credentials, source rows or CSV files are committed. No database migration is required.

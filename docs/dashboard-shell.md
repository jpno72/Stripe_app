# Dashboard shell (Step 6)

`public/index.php` is the dashboard entry point. It uses the shared layout in `app/Views/layout.php`, sidebar and header partials, reusable components, and `public/assets/css/app.css` plus `public/assets/js/app.js`. The same layout wraps the existing Imports, Matches, and Import diagnostics pages. Navigation highlights the current section; at tablet and mobile widths, the sidebar opens from a labeled button and closes with its backdrop or Escape.

The shell uses Bootstrap 5.3.3 and its bundle from the pinned CDN URL already used by the admin pages. It needs no Node process or build step on the host. Serve **only** `public/` as the document root.

## Access

All PHP pages and APIs require application login. Dashboard access permits active viewers; Imports, Matches, Settings and User Management require administrators server-side. Step 11 replaced the cPanel allowlist/mapping. cPanel Directory Privacy may remain as an additional outer barrier, and public/.htaccess does not override it. See [security.md](security.md) and [deployment.md](deployment.md).

The session cookie now uses path `/` so navigation and administrative actions share the same secure, HTTP-only, SameSite=Strict session on HTTPS. Existing import and match-review POST actions keep their CSRF checks. Do not put the external private configuration under `public/`.

## Home page data

The KPI cards read `vw_active_members`, `vw_subscription_summary`, `vw_transaction_summary`, and the latest `vw_monthly_revenue` period. They show a count of members **marked active**, active Stripe subscriptions, and eligible paid charges. The member count does not estimate unknown statuses. Latest-month gross is shown in **minor units with its currency** only when that month has one currency. Multiple currencies or no dated paid charges show an unresolved display instead of adding unlike currencies or inventing a value. Gross payments are not profit.

If no member, subscription, or transaction records exist, the page displays an import empty state. The trend chart is an honest placeholder; no fabricated points are drawn. Recent imports read `vw_import_health`. The other navigation sections have disabled filter layouts and empty states, ready for later report work without implying that their analytics exist today.

Reusable components include page headers, KPI cards, chart and table cards, filter bars, status badges, empty states, error alerts, loading indicators, pagination, and modal markup. Source text and user-visible values are HTML-escaped. The disabled filter bar establishes controls for date range, program, membership status, city, and age group; filtering behavior belongs to later stages.

The Revenue page can use `uiKpiCard()`, `uiChartCardStart()` / `uiChartCardEnd()` around a future Chart.js canvas, `uiFilterBar(false, ['start', 'end'])` for date inputs, and `uiTableCardStart()` / `uiTableCardEnd()` for transactions. These components render inside the existing layout; Step 6 adds no chart data or filter processing.

## Validation

PHP syntax checks passed for the new shell and existing admin pages. Browser checks at 1440×900, 820×1000, and 390×844 found no horizontal page overflow; all 11 navigation links were present, the active page was highlighted, and the mobile menu opened and closed with Escape. The Revenue placeholder and existing Imports page rendered through the shared layout. A separate existing empty test database rendered the import empty state. The populated preview database also contains earlier synthetic fixtures, so its displayed counts are test values, not production BMA totals.

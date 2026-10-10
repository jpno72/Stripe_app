# Local time and original timestamp preservation

## Policy

`AppTime` is the shared display/calendar utility. Default `APP_DISPLAY_TIMEZONE=America/New_York` uses PHP's IANA timezone rules, including historical EST/EDT transitions. The optional value can come from the private PHP config or environment. Invalid timezone identifiers fail explicitly. PHP's process timezone is not changed: import parsers, authentication clocks and application storage remain unchanged. PDO still sets its session timezone to `+00:00`.

Three distinct values are kept separate:

- Approved original CSV cells remain unchanged in filtered staging JSON, including source formatting and precision.
- A verified normalized UTC instant can be converted for display (`Oct 9, 2026, 11:03 PM EDT`). Both second and microsecond database precision are accepted.
- Application-generated timestamps remain UTC in storage and use the same display formatter.

Unknown/invalid display timestamps show Unknown or Unavailable. The display helper is never used to normalize imports. DATE fields, including birth dates, signed dates and membership dates, receive no offset conversion.

Cognito parsing, fingerprinting and field allowlists are unchanged. No default COGNITO_SOURCE_TIMEZONE was added. An unknown timezone keeps `submitted_at` unresolved. The member profile shows the original approved `Date Submitted` cell separately, labeled original source submission time. A normalized waiver submission is displayed as an instant only when its source timezone status is present. Explicit offsets continue to normalize through the existing importer. Ambiguous local source times remain unresolved under existing parsing rules.

## Reporting calendars

Revenue inclusive date filters become `[local midnight on from, local midnight after to)` converted to UTC. Timestamp predicates remain on the underlying timestamp column. This correctly represents 23/25-hour DST days. Day, Monday-through-Sunday week, month and year ranges can use the same boundary helper. Existing interfaces currently chart monthly grain; no unsupported new metrics were added.

Overview revenue, Revenue and profile monthly financial charts group verified charge instants in the local calendar. They retain paid/captured rules, transaction grain, separate currencies, missing-component NULLs and existing attribution. `AppTime::localSql()` generates prepared UTC transition boundaries and trusted integer offsets from PHP's timezone database. MariaDB named-timezone tables and CONVERT_TZ privileges are not required. Date ordering remains by original UTC timestamp; chart JSON uses `month_start_local` and JavaScript treats it as a calendar label without browser-timezone parsing.

Membership cancellation filters and charts use local boundaries; factual membership starts remain DATE values. Application age, current activity, completed spells and milestone retention queries use the local as-of date. Their business definitions are unchanged. Existing SQL views and historical migrations retain their UTC semantics: do not read a `*_utc` aggregate as Eastern revenue. No database migration is required because local grouping is implemented in the repositories over the transaction-grain view.

Displayed system timestamps are updated in the shared page header, overview imports, import history/errors, match audit history, user last login, member identity review history and JSON transaction tables. Dashboard-access administration has no timestamp display of its own; its audit writes remain unchanged.

## Validation — 2026-10-10

`php tests/local-time.php`: **44 PASS** using the existing populated disposable reporting database and session-local temporary transaction fixtures. No persistent schema or data changes, reimports or production connections were made.

Checks cover winter/summer conversion, midnight rollover, both DST transitions/repeated hours, microseconds, original Cognito/Stripe cells, unresolved/ambiguous source times, stable Cognito fingerprints, local day/week/month/year boundaries, application/import timestamp formatting, empty periods, membership/demographic/profile query execution, card/chart/table agreement, financial completeness and gross/refund/fee/net totals. Hashes of existing raw and normalized imported records were unchanged after execution.

All modified/new PHP files are linted. Existing authentication, authorization, CSRF, Stripe/source parsing, matching, allowlists, bootstrap/path resolution and SQL/migration files remain unchanged, verified by file hashes. The database loader change is limited to loading/configuring AppTime and accepting APP_DISPLAY_TIMEZONE; database connection/query setup is unchanged. No full regression or stress suite is run.

## Split cPanel deployment

Deploy private PHP changes under `/home/bbmmacom/repositories/Stripe_app/`, including the new `src/AppTime.php`. Deploy changed public PHP and JS at their same relative paths under `/home/bbmmacom/public_html/customers.bloommma.com/public/`. Upload both portions together to avoid missing-helper or chart-field mismatches. Leave app/src/config/vendor outside the web root. Do not modify the existing public bootstrap or its configured paths for this correction.

The private config at `/home/bbmmacom/bma-private/config.php` can omit APP_DISPLAY_TIMEZONE to use America/New_York. Optional setting: `'APP_DISPLAY_TIMEZONE' => 'America/New_York'`. Example files remain examples; do not copy them over live secrets. Leave COGNITO_SOURCE_TIMEZONE unchanged unless its source timezone is independently established. No migrations, Composer dependency changes, database updates or CSV reimports are required. Refresh browser JS assets after deployment. Production deployment and live-browser verification were not performed.

## Historical limitations

Unknown historical timezone values cannot establish an absolute instant or reliable Eastern calendar bucket. Original cells remain available without guessing. Missing original cells cannot be reconstructed. Existing SQL view-only consumers still use UTC dates/aggregates; application reporting repositories provide the local business calendar. Existing view-derived cached subscription allocation context retains its database as-of-date semantics; source association decisions are not modified by this correction. All-time financial totals are unchanged, while day/month totals can move across boundaries as expected when switching from UTC to Eastern.

## Files changed

- `.env.example`
- `app/Demographics/DemographicRepository.php`
- `app/Members/MemberProfileRepository.php`
- `app/Membership/MembershipRepository.php`
- `app/Revenue/RevenueFilters.php`
- `app/Revenue/RevenueRepository.php`
- `app/Views/components.php`
- `app/Views/layout.php`
- `config.example.php`
- `docs/deployment.md`
- `docs/member-profile.md`
- `docs/membership-dashboard.md`
- `docs/reporting-definitions.md`
- `docs/revenue-dashboard.md`
- `docs/time-handling.md`
- `public/admin/import-errors.php`
- `public/admin/imports.php`
- `public/admin/matches.php`
- `public/admin/users.php`
- `public/api/member-transactions.php`
- `public/api/membership-members.php`
- `public/api/revenue-transactions.php`
- `public/assets/js/member-profile.js`
- `public/assets/js/revenue.js`
- `public/demographics.php`
- `public/index.php`
- `public/member.php`
- `public/membership.php`
- `public/revenue.php`
- `src/AppTime.php`
- `src/database.php`
- `tests/deployment-paths.php`
- `tests/local-time.php`
- `tests/revenue.php`

# Step 11 validation

Validated 2026-10-04 (local EDT; some timestamps fall on 2026-10-05 UTC). Application authentication replaces the historical cPanel-only guard.

| Check | Result |
|---|---|
| Migration 006 on existing schema / consolidated snapshot | PASS — applied to two existing disposable databases with valid histories; all six migrations tracked, zero pending. No applied migration was rewritten. |
| Password hashing / verification / policy | PASS — hash-only storage, PHP defaults, length bounds and reset revocation. |
| Sessions | PASS — login ID rotation, secure/HttpOnly/Strict cookies, idle and absolute expiration, logout replay rejection, role/activity/version reload. |
| Throttling | PASS — database-backed account and IP limits; correct credentials blocked after limit; unknown/disabled accounts use generic failures. |
| Authorization | PASS — anonymous pages require login, APIs return 401; viewers receive 403 from Imports, diagnostic URLs, Matches, Settings, User Management and legacy settings/sync aliases. Admin identity section hidden from viewers. |
| CSRF | PASS — invalid tokens rejected for login, logout, imports, matches, account and user-management actions. Valid deactivation and own password change succeeded. |
| Upload hardening / actor audit | PASS — unsafe extension/type rejected; synthetic approved CSV dry run accepted; created_by is signed-in admin, no normalized customer created, processing upload removed. |
| SQL / XSS review | PASS — bound values; code-controlled identifiers/sorts and bounded pagination; escaped source text/API cells and hexadecimal-safe JSON. Untrusted display-name HTML rendered as text. |
| Security headers / errors | PASS — CSP, framing protection, nosniff and referrer policy; production HTTP denied; generic exception response and safe code-only private logging. |
| Official Stripe SDK | PASS — restricted StripeClient construction; valid raw payload signature accepted; modified, invalid and stale signatures rejected. No Stripe API calls or webhook processing enabled. |
| Desktop / mobile | PASS — login, authenticated layout and protected report tables; no JavaScript errors under CSP or settled mobile overflow. Screenshots inspected. |
| Dependencies / syntax | PASS — 70 runtime/config/CLI PHP files linted; updated files linted again. Composer strict validation passed; locked dependency audit reported no known advisories. |
| Secret / ignore review | PASS — 129 project files scanned for sk_, rk_, whsec_, password, secret and token; no real Stripe keys or actual config.php/.env found in source candidates. Matches were placeholders, generated test material or code/documentation. No Git metadata exists here, so committed-history claims cannot be made. |

32 focused service checks, 44 browser checks, five additional valid-action/upload checks and four database/file checks passed. Existing matching, transaction-grain, shared-payer and retention results were reused. No production imports, database rebuild, stress test or live Stripe request was performed. Disposable browser accounts and upload test records were cleaned up after validation.

## Defects resolved during review

- Viewer home shortcuts still led to admin pages; removed for viewers while keeping server-side denials.
- Revenue used the integer formatter for fractional MRR, producing an incomplete page with weekly rates. It now uses the existing decimal-safe formatter without changing financial calculations.
- Geography exposed a connection/view collation conflict (MariaDB error 1267). PDO now explicitly sets utf8mb4_unicode_ci, matching the schema; the geography API passed afterward.
- Uncaught rendering failures could emit a partial page before the error handler set status. Security bootstrap now buffers output and clears it for generic exception/fatal responses.

The older fixture database bma_schema_test_installer_7bdd63ff had a pre-existing stale 005 checksum. The installer refused it; tests used existing databases with valid histories. No historical checksum was bypassed.

## Remaining deployment checks and limitations

These tests used a local development HTTPS simulation. Secure cookies were explicitly supplied for API-client requests over local HTTP; actual TLS and cPanel Apache/PHP configuration must be checked on the host. Hosting privileges, private directory permissions, cron cleanup and backup access remain deployment responsibilities. The CSP trusts the pinned CDN assets by origin and allows styles required by the UI. MFA/email password recovery and future staff capabilities are not implemented. No production webhook route is registered; Step 12 must use the verified raw-request helper before enabling event processing.

No remaining application blockers identified in this review. Step 12 was not started.

## Lightweight final validation — 2026-10-05

Step 11 changes authentication/security rather than adding a new analytics dashboard. The previous 32 service checks, 49 browser/action checks and four integrity checks were reused. Desktop login/authenticated layout and mobile member-profile screenshots were already inspected; protected Revenue, Membership and Demographics table APIs passed under the new login/CSP. No relevant presentation or authorization behavior changed afterward, so these browser checks were not repeated.

| Requested check | Result |
|---|---|
| Representative displayed KPI sources vs SQL views | PASS — gross, refunds, fees and net agree with vw_monthly_revenue; current MRR agrees with vw_subscription_summary and fractional minor units format correctly. |
| Principal filters | PASS — six existing-data scopes covering currency, payment status, empty currency, empty date range, program and payer/person reconcile totals, monthly chart sums and paid ledger rows. Table search remains table-only, as previously documented. |
| Empty / missing / unavailable values | PASS — empty currency/date returns empty totals/chart/ledger; unknown amount or currency formats as unavailable; shared-payer member attribution remains unavailable. Earlier missing tenure, fees, next-billing and waiver minimization evidence reused. |
| Desktop and mobile | PASS — existing Step 11 browser checks and inspected screenshots reused; no responsive suite repeated. |
| Access / shared components | PASS — existing anonymous API 401, viewer admin 403, CSRF, logout, revocation and protected-report rendering results reused. |
| Dashboard-specific integrity | PASS — gross/chart/paid-ledger reconciliation excludes failed charges; MRR stays decimal-safe; PDO connection collation matches the schema. Existing transaction-grain and shared-payer protection results remain applicable. |

17 additional read-only checks passed against the existing bma_schema_test_step5_0c33c209 database. No data was inserted or changed; no database rebuild, full regression suite or synthetic scenario was needed. No genuine application blockers found. Real cPanel TLS, privileges and private directory permissions remain deployment checks, not locally verified hosting claims.

Final validation complete. Step 12 was not started.

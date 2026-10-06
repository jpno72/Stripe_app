# Dashboard access-control final validation

Date: 2026-10-05. Scope: authorization only; Step 12 was not started. No application, schema or business-data changes were made. No database rebuild, full regression suite or unrelated importer/matching/reporting tests were run.

Evidence reused: [dashboard-access-control.md](dashboard-access-control.md), 28 successful service assertions in tests/dashboard-access.php, 21 successful browser checks and two final desktop/mobile layout checks. The relevant authorization, provider and sidebar code has not changed since those results. New checks used the existing local MariaDB test database bma_schema_test_step5_0c33c209 and the existing CSRF/provider classes; no test accounts or business records were inserted.

| Requested check | Result | Evidence / limit |
|---|---|---|
| 1. Admin / analyst / viewer effective permissions | PASS | Existing service tests: admin all, analyst non-admin sections; viewer Overview, Membership, Demographics and Members; Revenue denied. Canonical database role is used. |
| 2. Denied dashboard hidden and direct access denied | PASS | Existing viewer Revenue sidebar absence and page/API HTTP 403 browser results. |
| 3. User ALLOW overrides role denial | PASS | Existing service and saved browser override grants Revenue; sidebar agrees. |
| 4. User DENY overrides role allowance | PASS | Existing service Membership denial and saved browser Revenue denial. |
| 5. No override inherits role | PASS | Existing INHERIT service result, absent override row and editor effective-access display. |
| 6. Disabled dashboard denied and hidden | PASS | Existing disabled Revenue denies admin/user even with an ALLOW override. Code inspection confirms getVisibleDashboards requires effective_access and show_in_navigation; effective_access requires is_enabled. No admin preview. |
| 7. Imports / Matches mandatory admin floor | PASS | Existing grants to viewer still denied; direct viewer requests HTTP 403; admin pages HTTP 200. |
| 8. Access administration admin-only | PASS | Existing browser viewer HTTP 403 and admin editor HTTP 200; mutation service independently verifies active admin with Settings access. |
| 9. Inactive app_users denied | PASS | Existing canonical inactive-user and inactive override-target service results; previous Step 11 session-revocation results reused. |
| 10. Invalid / missing CSRF | PASS | Existing invalid-token permission POST HTTP 403. Two new subprocess checks execute the unchanged Security::csrf guard with missing and invalid tokens; both record status 403. The permission page calls this guard before mutation. |
| 11. Permission audit | PASS | Existing override metadata actor/previous/new/dashboard checks and role-permission-change audit assertion; writes and audits share the transaction. |
| 12. Prevent administrative lockout | PASS | Existing global Settings/User management disable rejection and user-management service guard. Central transaction check requires at least one active admin to retain both capabilities. |
| 13. Deployed session authentication/provider | PENDING — host access required | Corrected requirement: validate the existing application session provider on the deployed host. Local evidence is retained below; it does not establish deployed-host behavior. |
| 14. Desktop / mobile navigation | PASS — reused | Existing browser sidebar tests and final layout checks. No material navigation changes occurred during this validation, so browser checks were not repeated. |

## New execution results

Seven narrow checks passed: five unauthenticated identity-value checks and two CSRF rejection checks. Development harness: .local/dashboard-access-final-check.php (ignored local storage). CSRF subprocesses verified the HTTP status set by the actual guard; they did not make live HTTP requests. The existing permission-changing HTTP test covers the admin-page integration.

## Correction of the earlier validation assumption

The earlier failure was caused by an **obsolete cPanel-to-app_users mapping requirement**, not a failed authentication or dashboard-authorization test. That requirement and the suggestion to restore the mapping are superseded. No cPanel identity mapping will be restored or implemented.

The approved current architecture is:

```text
Application Login
    ↓
Authenticated PHP Session
    ↓
SessionCurrentUserProvider
    ↓
app_users
    ↓
DashboardPermissionService
```

cPanel Directory Privacy, if enabled, is only an optional outer barrier. It does not provide application identity. Authentication and dashboard authorization code remain unchanged. Previously passed authorization, override, CSRF, audit, navigation and lockout results remain valid and were not rerun.

## Revised item 13 — deployed session checks

| Deployed-host check | Status | Existing local evidence to reuse / live observation needed |
|---|---|---|
| Successful application login resolves to the correct active app_users record | PENDING | Step 11 login/session tests passed locally. Verify a known test account's session-derived identity on the actual host without logging credentials or session IDs. |
| Canonical database role controls dashboard authorization | PENDING | Existing permission tests use canonical app_users activity and role; verify the deployed account's expected access matrix. |
| Denied dashboard direct URL returns expected 403 | PENDING | Existing viewer Revenue page/API browser checks returned 403 locally; verify on the deployed host. |
| Unauthenticated browser cannot access protected pages | PENDING | Existing local login redirect/API 401 evidence; verify a clean browser context on the deployed host. |
| Logout invalidates protected-page access | PENDING | Existing Step 11 logout/replayed-session checks passed locally; verify protected-page access after deployed logout. |
| Inactive application user is denied | PENDING | Existing inactive-account/session-revocation tests passed locally. Verify using an authorized disposable deployed test account, preserving existing accounts. |
| Client identity headers cannot substitute for a valid application session | PENDING | Five local provider checks reject REMOTE_USER, AUTH_USER, HTTP_REMOTE_USER, HTTP_X_AUTHENTICATED_USER and HTTP_X_FORWARDED_USER with no session. Verify actual deployed HTTP requests with spoofed identity headers, including X-Authenticated-User and X-Forwarded-User. REMOTE_USER is ordinarily a server variable; a browser-supplied similarly named header must not confer identity. |
| Optional cPanel Directory Privacy coexists without becoming identity source | PENDING | Establish whether it is enabled on the deployed host. If enabled, verify its outer challenge and separate application login. Otherwise mark this check not applicable. |

## Current result

No deployed-session check has failed: those checks have **not yet been executed**. The obsolete mapping failure is withdrawn. This workspace contains an example APP_URL only (`https://analytics.example.com`), not a configured deployed URL; no authenticated deployed session was supplied. The deployed URL and access to suitable test sessions/accounts are needed to finish item 13. Do not provide passwords in documentation, source control or chat.

Readiness for Step 12 is pending actual deployed-session verification; it must not be inferred from local tests. No application changes or new test-suite runs are required solely because of the superseded assumption. Step 12 has not started.

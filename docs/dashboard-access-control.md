# Dashboard access control

## Deployment and administration

Apply `php bin/install.php --check` and `php bin/install.php --apply` with the existing private database configuration before deploying the updated PHP files. Migration **007_dashboard_access.sql** adds only permission tables and their seed configuration. Migrations 001–006 and reporting views are unchanged. `sql/schema.sql` includes 007 for new empty installations; never load it over an existing database. The application account needs SELECT on the new tables, and administrators use the existing PDO account for permission writes and audit inserts. No new secrets or configuration variables are required.

Open **Settings → Dashboard access** (`/admin/dashboard-access.php`). Each dashboard has a display-name, enabled/navigation flags and role matrix. Select an active user to see inherited role access, Allow/Deny/Inherit and effective access. Settings and User management must remain accessible together to at least one active administrator. User deactivation/demotion uses the same safeguard. Failed changes roll back both settings and audit entries.

## Authentication integration

The attached feature specification described the earlier cPanel identity mapping. The actual project already has the validated Step 11 application login. This enhancement preserves that existing authentication; it does not implement or replace login, sessions, passwords or password reset. `CurrentUserProvider` is the boundary; `SessionCurrentUserProvider` delegates to the existing Authenticator and resolves an active `app_users` record. No Apache REMOTE_USER or browser identity header is used for application identity now. Optional cPanel Directory Privacy remains an outer protection layer and must not override application authorization. Private configuration remains outside public_html.

Replacing the provider later (including with a deliberate server-authenticated Apache mapping) does not require rewriting permission rules. Restoring the obsolete cPanel mapping is not part of this enhancement. Historical Step 11 validation remains accurate.

## Schema

- `dashboards`: auto-increment primary key, unique ASCII stable key, display name, fixed route, global enabled/nav flags, navigation order and timestamps. Display names and URLs are not permission identifiers.
- `role_dashboard_permissions`: composite primary key `(role,dashboard_id)`; FK to dashboards; boolean can_view. Roles reuse app_users: admin, analyst, viewer and the already reserved staff role.
- `user_dashboard_overrides`: composite primary key `(user_id,dashboard_id)`; FKs to app_users and dashboards; ALLOW/DENY only. Inherit deletes the row. Dashboard FK indexes support joins. No cascade deletes or second audit/user/role system.

## Registered keys and defaults

| Key | Route | Admin | Analyst | Viewer / Staff |
|---|---|---|---|---|
| overview | /index.php | Allow | Allow | Allow |
| revenue | /revenue | Allow | Allow | Deny |
| membership | /membership | Allow | Allow | Allow |
| demographics | /demographics | Allow | Allow | Allow |
| members | /index.php?page=members | Allow | Allow | Allow |
| transactions | /index.php?page=transactions | Allow | Allow | Deny |
| waivers | /index.php?page=waivers | Allow | Allow | Deny |
| reports | /index.php?page=reports | Allow | Allow | Deny |
| imports | /admin/imports.php | Allow | Deny | Deny |
| matches | /admin/matches.php | Allow | Deny | Deny |
| settings | /admin/settings.php | Allow | Deny | Deny |
| users | /admin/users.php | Allow | Deny | Deny |

Members listing, Transactions, Waivers and Reports retain their existing shell placeholders. Individual member profiles and their transaction API use the members key. Reports and the reserved staff role are existing project concepts; this feature creates no new reporting tools. The overview key maps to the existing shell's dashboard active marker.

Defaults are editable. Admin-only floors for Imports, Matches, Settings and Users remain mandatory regardless of any grant to another role. Existing account creation supports admin/viewer; this feature does not expand account provisioning.

## Central rules and direct access

`DashboardPermissionService` loads the canonical user's activity and role from the database, so supplied/stale role values do not confer access. Unknown keys and inactive users fail closed. Effective access requires a registered, globally enabled dashboard, then applies **DENY override → ALLOW override → role grant → default deny**, subject to the mandatory admin-only floor. A disabled dashboard is inaccessible to everyone; there is no admin preview. No underlying business data or SQL views are removed.

Protected page controllers and corresponding JSON APIs call `requireDashboardAccess` immediately after the existing authentication guard, before reporting queries. Denial returns HTTP 403 without fetching dashboard data. Login absence retains existing browser login redirects / API 401. Legacy index routes and member profiles are guarded as well. Account self-service and logout remain authenticated operations independent of dashboard grants.

The shared desktop/mobile sidebar obtains visible rows from `getVisibleDashboards`: enabled, navigation visible and effectively allowed. Hiding navigation does not revoke direct URL access. Overview admin shortcuts use the same central permission checks. No parallel navigation markup or scattered permission SQL was added.

## Changes, validation and audit

Only admins with effective Settings access can modify permissions. POST uses the existing shared CSRF token. Keys, names, roles, booleans, active target users and override values are validated server-side. Dynamic output is escaped. SQL values are bound through PDO. Stable keys/routes/order cannot be edited from this page.

Permission mutations lock users in ID order, then registry rows, and check administrative availability in the same transaction. User management follows the same lock order and rechecks availability after role/activity changes. The check permits an admin to restrict their own access only if another active admin retains both administration capabilities. It does not guarantee that a human remembers that other account's password.

The existing audit_log records actor, action, dashboard entity ID, timestamp and JSON previous/new metadata, including affected role or user where relevant:

- dashboard.enabled / dashboard.disabled
- dashboard.navigation_changed
- dashboard.display_name_changed
- dashboard.role_permission_changed
- dashboard.user_override_changed

No-op writes do not produce duplicate override rows or change audit entries. Records contain no credentials, session identifiers or imported source rows. Registry/history is not deleted by this feature.

## Focused validation — 2026-10-05

| Check | Result |
|---|---|
| Migration 007 applies to existing MariaDB test database; all previous ledger checksums accepted; repeat installer check has zero pending | PASS |
| 28 service assertions: roles and editable role-matrix audit, overrides/inheritance, global disable, canonical inactive user, unknown inputs, admin floors, audit, lockout and repeated writes | PASS |
| 21 browser checks: viewer pages/API 403, admin pages 200, new editor, persisted Allow/Deny, sidebar consistency, unknown key 403, CSRF 403 and escaped source text | PASS |
| Desktop/mobile sidebar and new page overflow checks; final role-label layout adjusted and verified in two additional browser checks | PASS |
| PHP syntax for security helpers, pages/APIs, shared views and schema snapshot generator | PASS |
| 91 runtime/test source files scanned: no real-format Stripe credential literals; no actual config.php/.env in repository root | PASS |

Used `bma_schema_test_step5_0c33c209`; no database rebuild or business-record changes. Temporary test accounts, overrides and their audit entries were cleaned up. Existing Step 11 session, throttling, upload and security-header evidence is reused; no complete regression or stress suite was repeated. Existing financial reporting tests remain applicable because SQL/KPIs were unchanged. Browser scripts/screenshots were kept in local temporary development storage. Local simulated HTTPS remains a test convenience; verify real HTTPS/cPanel configuration on deployment.

Known limits: no admin preview, role creation, bulk permission editor, navigation reordering UI or alternate login-provider switch. Adding a new feature requires registering its stable key/routes, adding a server guard and defining defaults. No Step 12 synchronization was started.

### Files added / integrated

Added migration 007, CurrentUserProvider.php, DashboardPermissionService.php, public/admin/dashboard-access.php, tests/dashboard-access.php and this guide. Updated the shared admin guard, layout/sidebar, protected reporting pages/APIs, index shortcuts, Settings, UserManagement lockout guard, consolidated schema and schema snapshot generator. README, security and database-schema documentation link this feature. Existing Security::csrf and Security::audit are reused.

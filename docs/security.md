# Authentication and security — Step 11

## Access model

All report pages, member profiles and JSON APIs require an application session. Browser pages redirect unsigned users to `/login.php`; APIs return HTTP 401. Active `admin` and `viewer` users can read internal dashboards and member contact/financial records. Imports, import diagnostics, Matches, Settings, User Management and future sync controls require `admin` on the server. Viewer navigation and dashboard shortcuts omit administrative actions. Staff is a reserved future role; legacy analyst/staff accounts receive only viewer permissions. New accounts can be provisioned as admin or viewer.

Application login replaces `IMPORT_ADMIN_USERS`, `REMOTE_USER` and `MATCH_REVIEW_ADMIN_MAP` as authorization. A cPanel username or forged forwarding header never creates an application identity. Optional cPanel Directory Privacy remains an outer barrier, not the role authority. Member identity details require the signed-in active application administrator. `adminConfig()` retains its existing name for compatibility with existing pages; its default means authenticated dashboard access, while administrative pages explicitly pass `admin`.

## Passwords and sessions

Provision accounts using `bin/create-user.php --password-stdin`; no default account or password exists. Passwords are hashed with PHP `PASSWORD_DEFAULT`, verified with `password_verify`, and rehashed when defaults change. They must be 12–72 bytes with no NUL byte; the upper bound prevents silent bcrypt truncation on current PHP builds. No passwords, hashes, CSRF tokens or session identifiers enter audit metadata or error logs. User management never displays password hashes. Private CLI resets are audited and revoke existing sessions. Users can change their own password with the current password and CSRF token.

Cookies are HttpOnly, SameSite=Strict, Secure on HTTPS, host-only and session-lived. Production requires HTTPS and a private configured session directory. Strict cookie-only sessions prevent fixation; successful login regenerates the ID and rotates CSRF. Logout is POST with CSRF and destroys the session/cookie. Default idle expiration is 30 minutes; absolute expiration is 8 hours. Every protected request reloads role, active state and `auth_version` from the database. Disabling an account, changing its role or resetting/changing its password revokes earlier sessions. Version changes do not permanently delete users or audit history. Concurrent updates lock user rows in stable order and refuse removal of the last active admin.

## Login throttling

Migration 006 adds `auth_throttle`: HMAC bucket keys using private APP_KEY, window start, attempt count. No raw email, IP or password is retained in this table. The default 15-minute window permits five failed/reserved attempts per normalized email and 30 per source IP, shared across PHP processes. Row locks reserve attempts before password verification. Successful requests release only their own reservation, preserving previous failures. Unknown and inactive accounts use the same failure text. Unknown accounts use a dummy random hash; hashing work also occurs for known accounts. Locked accounts return the same message, without exposing account existence.

Do not trust client `X-Forwarded-For` or `X-Forwarded-Proto`; configure the host to provide trusted HTTPS/server values when using a proxy. Run `bin/cleanup-security.php` daily to remove expired bucket metadata. That cleanup has no waiver-data retention effect. Account-scoped throttling can temporarily block legitimate access during a targeted attack; administrators should investigate the security audit and wait for the window to expire. MFA and automated email password recovery are not implemented in this step.

## CSRF, SQL and rendering

Login, logout, password changes, CSV imports, user access changes and manual matching decisions validate a cryptographic session CSRF token. Read-only reporting endpoints reject POST. Every SQL parameter is bound through native PDO prepared statements. Dynamic SQL identifiers in import/identity code come from fixed code/allowlists. Sort expressions and directions come from fixed arrays; pagination is bounded integer arithmetic. SQL migrations are trusted repository SQL and are never request-provided.

Dynamic text uses `htmlspecialchars` with ENT_QUOTES and UTF-8 substitution. DataTables API cells are escaped server-side, including permitted member links. Embedded chart JSON uses JSON_HEX_TAG/AMP/APOS/QUOT. Matches confirmation logic is an external script. The CSP permits only same-origin requests and pinned CDN script URLs by origin, blocks inline scripts, objects and frames, and restricts form submission to self. Inline styles are allowed for Bootstrap/DataTables/Chart.js rendering. X-Frame-Options DENY, nosniff, no-referrer, Permissions-Policy and HTTPS HSTS are set. CDN availability and trusted CDN origins remain operational dependencies; scripts/styles can later be vendored if required.

## Uploads and source minimization

CSV uploads require admin, CSRF, PHP upload provenance, `.csv` extension, an allowed detected MIME type and configured size limits (default 50 MiB). MIME values are not proof of CSV; the streaming importer separately validates encoding, headers and row shape. Original filenames are metadata only; processing copies use generated non-executable `.upload` filenames outside the actual public root, locked and chmod 0600. Originals and private copies are removed when processing ends; abandoned uploads require scheduled cleanup. Never execute upload content. Only approved mapping values enter staging; unknown and ignored values never enter JSON, normalized records or diagnostic logs. Medical and special-consideration fields remain excluded.

Web imports record `created_by` from the application admin, not a submitted user ID. Matching corrections continue to write previous/new-state audits through the authoritative association service. Login outcomes and account changes are audited without credential material. Existing reviewed waiver/contact data remains PII and must stay internal.

## Stripe

Composer includes the official Stripe PHP SDK. `StripeSecurity::client()` constructs `StripeClient` and requires a dedicated restricted `rk_` key, refusing unrestricted `sk_` keys. Configure read permissions only for resources later used by sync: customers, subscriptions/items, prices/products, charges/payment intents, invoices, refunds and balance transactions as required. Keys stay server-side and never enter page data.

`StripeSecurity::verifyRequest()` reads bounded raw php://input and the Stripe-Signature header and delegates to `verify()`, which uses the untouched body, Stripe-Signature value, private endpoint whsec secret, official `Webhook::constructEvent`, and a 300-second tolerance. Invalid, modified or stale signatures throw. No webhook receiving route or event processing is enabled yet; Step 12 must call this verifier before parsing/mutating records and return an error for invalid signatures. Do not register a production endpoint until processing and retry/idempotency handling exist. Read-key construction and verifier tests do not contact Stripe or enable synchronization.

## Configuration, errors and review

Environment wins over the private PHP config referenced by BMA_CONFIG_FILE. Resolved config, upload, session and application-log locations must be outside the actual public root. Production requires a non-placeholder APP_KEY of at least 32 bytes, private writable session directory and private error-log path. The flat uppercase example config matches the loader; `.env.example` documents variables and is not automatically read.

Production display errors and native exception argument logging are suppressed. The private application log contains only timestamp and fixed codes, never exception messages, SQL, source rows, credentials, URLs or request bodies. Uncaught failures return generic HTTP 500; handled failures use fixed messages. `.user.ini` suppresses early PHP diagnostic output; configure the same settings in cPanel because not every PHP handler reads .user.ini. Review host-level logs too: disable request-body capture and keep logs/backups private. Do not use a public log fallback.

Steps 1–10 and the new authentication paths were reviewed for guards, prepared SQL, output encoding, upload handling and error propagation. The review also fixed fractional MRR rendering and explicitly aligned PDO connection collation with the schema after the geography API exposed a collation conflict. No unresolved high-risk application defect was identified in the reviewed paths. This is not a penetration test or a guarantee about the host. Deployment still requires valid grants, HTTPS, filesystem isolation, secure backups and maintained PHP/MariaDB. See `step11-validation.md` and `deployment.md`.

References: [PHP password_hash](https://www.php.net/manual/en/function.password-hash.php), [PHP session security settings](https://www.php.net/manual/en/session.security.ini.php). Use a maintained PHP release supplied by the host.

## Dashboard authorization enhancement

[Dashboard access control](dashboard-access-control.md) adds a central registry, role permissions and user overrides through migration 007. It preserves the completed Step 11 authentication and mandatory administrative restrictions. Dashboard pages and APIs require effective permission before querying reporting data. Settings exposes the audited CSRF-protected editor, with transactional protection against administrative lockout. No new authentication mechanism or credentials are introduced.

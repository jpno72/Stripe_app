# Member profile emergency contacts: validation and deployment

Implemented locally on 2026-11-01. No push, deployment or production access occurred. No import, backfill or migration runner was executed. Production migration 010 and the successful recovery are operator-supplied prerequisites; they were not rerun.

## Result and UI

The existing Waivers card now contains an Emergency Contact subsection **inside each accepted waiver article**. It displays that waiver's stored name and phone using HTML escaping only; source formatting, accents, punctuation, spaces and extensions are preserved. A validated telephone gets a clickable digits-only `tel:` URI with optional numeric `;ext=`. Unsupported/ambiguous phones remain escaped plain text. No parent/guardian/payer/member relationship is inferred.

Missing name or phone displays `Not provided.`; both missing displays `No emergency contact information provided.`. Multiple accepted waivers keep their own possibly different contacts. The existing latest-50 limit/order is unchanged; no record is arbitrarily chosen as authoritative. Without an accepted student link, the existing `No accepted waiver link` empty state remains. Signer-only links do not qualify.

The inset light card uses the existing profile facts grid and responsive layout. No JavaScript changes, new endpoint, edit action, hidden contact field or data serialization were added.

## Authorization and privacy

`MemberProfileAccess::canViewEmergencyContacts` resolves the existing `SessionCurrentUserProvider`, which reloads the active canonical app_users role/session version. It permits only admin/staff **and** effective Members access through `DashboardPermissionService`. User DENY, role denial without ALLOW, disabled Members, inactive users, invalid/stale sessions and missing authentication deny emergency viewing. Analyst/viewer remain denied even if they can read Members. Client identity headers do not authenticate.

The repository defaults to its original redacted waiver projection. The profile service passes trusted server configuration to request the additional fields. The repository independently checks the current user/permission before adding the two fixed column names to its SELECT. Unauthorized calls do not retrieve the values at all; no request flag or claimed role can enable them.

The existing query still requires an `automatic` or `confirmed` **student** identity link with exactly the viewed member's person ID. Possible/rejected/unlinked, other-member and signer-only waiver records cannot supply contacts. No names/emails/phones are used for association. No existing source/identity/member/relationship/payment records are changed by viewing.

Administrator-only identity review and Matches remain separate and unchanged. Staff contact access does not grant review rights. The page retains its existing application login/Members guards and GET-only behavior. It explicitly sends `Cache-Control: private, no-store` after session startup; the PHP session cache limiter otherwise replaces the earlier Security header. Authentication/session implementation itself is unchanged.

Contact values are absent from analyst/viewer HTML, service JSON, embedded scripts, APIs, hidden fields and outgoing telemetry requests. The existing profile-data JSON continues to contain only member ID, currency and payment trend. The transaction API is unchanged and never retrieves emergency fields. Reads add no audit events/contact metadata; existing error handling logs fixed codes only.

## Focused results

| Check | Result |
|---|---|
| Admin/staff with effective Members access receive contacts | PASS |
| Analyst/viewer omit contacts from SQL projection, service data and HTML | PASS |
| Members DENY/role denial/disabled state enforced; staff ALLOW honored | PASS |
| Inactive/unauthenticated/stale-role users and identity-header spoofing denied | PASS |
| Direct Members denial returns HTTP 403 | PASS |
| Accepted automatic/confirmed student links only | PASS |
| Multiple accepted waivers retain their own contacts; unrelated/signer-only records excluded | PASS |
| Missing fields, punctuation, accents, HTML-like source text | PASS |
| Formatting/extensions preserved; unsafe dialing strings remain plain text | PASS |
| Identity review and Matches remain admin-only | PASS |
| Private no-store HTTP response; no contacts in transaction API/log/audit | PASS |
| Existing profile payments, shared-payer attribution and membership reads unchanged | PASS |

The focused PHP integration passed 78 checks, plus the existing `tests/member-profile.php` (26 checks) and `tests/dashboard-access.php` (28 checks). The optional Playwright run adds one aggregated focused check (79 total), with separate desktop/mobile and staff/analyst/viewer privacy checks. Tests used synthetic data in a newly created, guarded localhost-only disposable database built from the existing schema snapshot. The fixture database, generated sessions/config/logs and local server were removed afterward. Existing databases were not rebuilt. No production data or actual CSV was needed for this display feature.

Desktop and mobile screenshots were inspected locally; browser coverage includes 1440px desktop and 390px mobile, correct waiver placement, escaped text, extension links, no horizontal overflow, no sidebar obstruction after its existing responsive transition, staff access and analyst/viewer redaction. The existing server on port 8080 was left untouched; the tests used an isolated ephemeral localhost server with real application login. No global navigation/CSS behavior was modified.

Final verification: all seven changed/new PHP files passed PHP 8.2 lint; git diff --check passed. Baseline hashes confirm only the five listed existing runtime files changed, plus the new private partial. SQL/migrations, import/backfill code, mappings, authentication/session code, bootstrap/configuration and shared navigation are unchanged. The targeted credential-pattern scan found no credential-shaped keys in changed/new files. Desktop/mobile screenshots were visually inspected after the existing sidebar transition settled.

## Exact file list

Modified application files:

- `app/Members/MemberProfileAccess.php` — separate canonical-session emergency viewing permission.
- `app/Members/MemberProfileRepository.php` — permission-gated sensitive columns; existing accepted-student association unchanged.
- `app/Members/MemberProfileService.php` — pass trusted server configuration; default remains redacted.
- `public/member.php` — per-waiver subsection and final private no-store response header.
- `public/assets/css/member-profile.css` — scoped emergency subsection styling.

New application file:

- `app/Views/partials/member-emergency-contact.php` — escaped display/empty states and conservative telephone helper.

Tests:

- New `tests/member-emergency-contact.php` — focused unit/SQL/service/HTTP checks plus existing relevant regressions; optional external local Playwright script support.
- Updated `tests/member-profile.php` — `require_once` for the access helper now also loaded by the repository; existing assertions unchanged.

Documentation:

- Updated `docs/member-profile.md`.
- Updated `docs/cognito-emergency-contacts.md` with the subsequent explicitly approved display policy.
- New `docs/member-emergency-contact-validation.md` (this file).

No SQL, mappings, import/backfill code, authentication/session code, authorization settings, bootstrap/configuration, .htaccess, shared navigation, payment logic or existing data was modified. No new migration is required.

## Exact local commands (PowerShell)

PHP 8.2 requires existing PDO MySQL, mbstring, cURL and session support. The integration test needs only development credentials on an existing localhost MariaDB server and permission to create/drop its own randomly named `bma_schema_test_profile_emergency_*` database. It never runs against a remote host.

```powershell
$files = @('app/Members/MemberProfileAccess.php','app/Members/MemberProfileRepository.php','app/Members/MemberProfileService.php','app/Views/partials/member-emergency-contact.php','public/member.php','tests/member-emergency-contact.php','tests/member-profile.php')
foreach ($file in $files) { php -l $file; if ($LASTEXITCODE -ne 0) { throw 'PHP lint failed' } }
$env:BMA_PROFILE_EMERGENCY_DB_TEST = '0'
php tests/member-emergency-contact.php
$env:BMA_PROFILE_EMERGENCY_DB_TEST = '1'
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '33317' # Substitute your existing local test port.
$env:DB_USERNAME = '<LOCAL_TEST_USER>'
$env:DB_PASSWORD = '<LOCAL_TEST_PASSWORD>'
php tests/member-emergency-contact.php
git diff --check
```

The integration command automatically invokes the existing profile and dashboard-access tests in the same disposable fixture database, so no separate production/config setup is needed. Browser automation used the Playwright skill's runner and a script under the system temporary directory, not production code. On this workstation it can be repeated while that temporary script exists:

```powershell
$env:BMA_PROFILE_EMERGENCY_BROWSER_SCRIPT = Join-Path $env:TEMP 'playwright-test-member-emergency.js'
$env:BMA_PROFILE_EMERGENCY_BROWSER_RUNNER = 'C:\Users\BloomMMA\.agents\skills\playwright\run.js'
$env:BMA_PROFILE_BROWSER_SCREENSHOTS = Join-Path (Get-Location) '.local'
php tests/member-emergency-contact.php
```

The optional runner path is development-only, not a deployment dependency. Temporary browser fixtures/passwords are generated at runtime and not committed. Screenshots contain synthetic test values only and stay ignored under .local.

## Controlled deployment plan

No command below has been executed on production. Live files are authoritative; compare and manually merge server-only customizations before replacing any file. Do not blindly pull/reset the live checkout. Keep application PHP 8.2 and the current split deployment structure.

1. Confirm the existing migration-010 columns are present and the reported recovery is complete. **Do not rerun migrations, imports or backfill.** Back up the six affected runtime files privately, preserving permissions. No data changes are involved; use normal deployment backup policy if taking an additional database backup.
2. Compare the three changed private classes and the public page/CSS against their actual live counterparts. Merge only the described additions. Add the new partial under private app/Views/partials before activating the public page that requires it.
3. Deploy private files under `/home/bbmmacom/repositories/Stripe_app/`:

```text
app/Members/MemberProfileAccess.php
app/Members/MemberProfileRepository.php
app/Members/MemberProfileService.php
app/Views/partials/member-emergency-contact.php
```

4. Deploy only these public files under `/home/bbmmacom/public_html/customers.bloommma.com/public/`:

```text
member.php
assets/css/member-profile.css
```

Do not upload private app files into public_html. Do not overwrite live .htaccess, .user.ini, private configuration, authentication bootstrap or other server-only files. Tests/docs are optional repository artifacts, not public application files.

5. With the host's verified PHP 8.2 CLI binary, lint the coordinated release:

```sh
BMA_PHP82=/opt/cpanel/ea-php82/root/usr/bin/php
"$BMA_PHP82" -v
cd /home/bbmmacom/repositories/Stripe_app
for BMA_FILE in app/Members/MemberProfileAccess.php app/Members/MemberProfileRepository.php app/Members/MemberProfileService.php app/Views/partials/member-emergency-contact.php; do
  "$BMA_PHP82" -l "$BMA_FILE" || exit 1
done
"$BMA_PHP82" -l /home/bbmmacom/public_html/customers.bloommma.com/public/member.php
```

If that usual binary path is unavailable, substitute the host's already verified PHP 8.2 binary; do not change handlers.

6. Verify a known member with an accepted student waiver link as admin and, where already authorized, staff. Check each contact against its corresponding waiver using authorized private records. Test analyst/viewer redaction, an existing Members DENY, no accepted link, and missing fields. Do not grant roles/permissions merely to make a test pass. Check the final response cache header and use a fresh/private browser session or hard refresh for the stylesheet. Verify desktop/mobile placement, normal financial sections and administrator-only Matches access.
7. On failure, restore only the backed-up affected code/CSS as a coordinated set. No data/schema rollback is needed because the feature is read-only. Retain server-only customizations and do not restore unrelated files/databases.

## Limits and remaining blockers

No local blocker was found. Production verification remains operator work. A member without an accepted automatic/confirmed **student** link has no displayed emergency contact, even if a name, signer, email or phone seems to match. Resolve associations only through the existing audited administrator review workflow. Latest 50 accepted waivers remain the existing profile limit. Unsupported phone formats are displayed as text rather than guessed dialing links. Medical/Goals data remains excluded. No contact editing workflow is added.

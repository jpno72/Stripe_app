# Identity Match Review interface repair

Validated locally on 2026-11-03 against feature baseline e2c4b51983ff4675f03b1683e076e41176bdbb42.

## Diagnosis and scope

The old shared shell loaded Bootstrap 5.3.3 CSS/JavaScript exclusively from jsDelivr. app.css customizes components but does not implement Bootstrap grids, utility spacing, buttons or form components. An actual Chromium rendering of the old template with jsDelivr blocked reproduced unstyled controls and a block-layout .row. The old matches.php contains literal mojibake in interface labels, check/warning decorations, separators, ellipses and history arrows. These are source-string defects; no database encoding conversion is required. The current CSP already permits the old CDN and same-origin assets; no security policy was changed. There is no evidence that changing CSP would solve this issue.

This reproduction establishes the old CDN failure mode, not the cause of any specific production browser's network failure. Original user screenshots were not attached in this task context. Before/after screenshots were captured from the baseline/current templates with synthetic source records.

The shell now loads one local Bootstrap 5.3.3 stylesheet and one local matching bundle before app overrides. Upstream MIT license is included. Source: https://github.com/twbs/bootstrap/tree/v5.3.3 (dist/css/bootstrap.min.css, dist/js/bootstrap.bundle.min.js, LICENSE). Only source-map URL comments were removed; source maps are not production dependencies. No build or Node process is required on shared hosting. Existing independent login markup and other report-specific third-party dependencies are outside this repair.

Matches rendering moved to a presentation-only app/Views/match-review.php. Its scoped stylesheet has source comparison panels, a separate score assessment, supporting/conflicting/unverified evidence sections, wrapping identifiers and long text, labeled controls, keyboard focus indicators, an aligned relationship checkbox, and a non-overlapping submit footer. All seven review actions and submitted field names remain available with the existing confirmation script. No action-specific fields are programmatically hidden or disabled. Native source-label interpretation, legacy ambiguity and score/high-confidence conditions are unchanged. A score is explicitly described as comparison evidence, not proof of identity.

Interface decorations use fixed SVGs, HTML entities or plain text. Changed PHP sources are UTF-8 without BOM. Shared HTML layout declares both meta charset and HTTP Content-Type text/html; charset=UTF-8. Names, contact values, notes, reasons, history and query-string output remain escaped. Original source values and stored historical audit text are not converted or updated.

## Results

| Check | Result |
|---|---|
| PHP syntax, six new/modified PHP files | PASS |
| git diff --check | PASS |
| Pure presentation assertions: encoding, escaping, roles, statuses, labels/IDs, field/action contract, empty state, local assets | PASS, 210 assertions |
| Native matching/review and HTTP security/UTF-8 checks | PASS, 49 assertions |
| Existing security regression | PASS, 31 assertions |
| Existing dashboard authorization regression | PASS, 28 assertions |
| Existing native/legacy Cognito compatibility regression | PASS, 76 assertions |
| Actual Chromium visual/layout/confirmation checks | PASS, 48 assertions |
| Review controller/POST handler and guards unchanged | PASS, exact comparison to baseline |
| Matching/association services, security services, config and SQL unchanged | PASS |

Total: 442 assertions. Local native tests use a randomly named disposable localhost database; all matcher calls are read-only/dry-run. Existing review GET table hashes prove no people, members, links, candidates, relationships, cached associations or audits are rewritten by rendering. Manual review service fixtures are isolated and never connect to production.

Actual Chromium windows were rendered at 1440x1000, 820x1100, 390x844 and 320x740 with external CDN blocked. Computed Bootstrap row display is flex; the local bundle exposes Bootstrap.Modal, and the page grid/form styles apply. Local asset requests return success; repaired shell makes no external asset request. No horizontal page overflow, control/card overflow, overlapping submit button, injected markup, JavaScript errors or broken local resources were found. Long Unicode names, long contact values/Stripe identifiers, all statuses and empty states were exercised. Mobile/tablet navigation opens and closes. Each existing decision confirmation was exercised and cancelled; zero browser review POSTs were sent. Desktop and mobile candidate screenshots were visually inspected, not just generated.

Screenshot/log artifacts are kept only in ignored .local/match-review-ui/. The Playwright automation script is in the operator's temporary directory, as required by the Playwright skill. It serves exported synthetic HTML and local production assets from a loopback-only fixture server. It does not expose production records or use a production session.

## Reproduce local checks

```powershell
php tests/match-review-presentation.php
# Use only the documented existing localhost development DB credentials:
$env:BMA_NATIVE_MATCH_DB_TEST = '1'
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '33317'
$env:DB_USERNAME = '<LOCAL_TEST_USER>'
$env:DB_PASSWORD = '<LOCAL_TEST_PASSWORD>'
php tests/matching.php --dry-run-only
git diff --check
```

Optional: set BMA_REVIEW_RENDER_DIR to an existing private directory before the presentation test to export matches.html/empty.html with synthetic data and fake tokens. These exports must never be installed into a public production directory. Do not run the default write-mode matching test or matcher for this visual task.

## Exact change inventory

Runtime/deployment:

- app/Views/layout.php: local Bootstrap URLs, explicit UTF-8 HTML response, entity separators.
- app/Views/match-review.php: new presentation-only view/helpers.
- public/admin/_common.php: optional stylesheet list on adminHead; guards unchanged.
- public/admin/matches.php: unchanged guarded controller plus new view call.
- public/assets/css/match-review.css: new scoped page design.
- public/assets/vendor/bootstrap/5.3.3/bootstrap.min.css: pinned upstream CSS.
- public/assets/vendor/bootstrap/5.3.3/bootstrap.bundle.min.js: pinned upstream JS.
- public/assets/vendor/bootstrap/5.3.3/LICENSE: required MIT license.

Tests/documentation:

- tests/match-review-presentation.php: new database-free presentation tests/exporter.
- tests/native-student-matching.php: one additional actual HTTP charset assertion.
- docs/match-review-interface-validation.md: this report.

app.css and match-review.js were inspected and remain unchanged. No migration, matcher write, production review decision, source-record modification or member creation is part of this repair.

## Production verification boundary

Local screenshots/tests do not establish authenticated production rendering. Deployment must compare original targets, preserve any production-only changes and permissions, create/verify a NEW private backup, install only the eight runtime/assets/license targets, and verify installed hashes. The earlier native-student-backup.20261103-042312Z.c68826 must be retained. Shared production files with formatting-only differences can be patched minimally only after executable PHP token equivalence is verified; functional differences must block deployment.

Authenticated production visual verification requires an existing authorized application session. If it is unavailable, report that limitation explicitly. Never submit match decisions merely to test appearance. No schema/migration change is needed. The separately documented pre-existing migration 011 consolidated-snapshot checksum issue remains untouched.

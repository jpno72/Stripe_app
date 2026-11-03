# Native Cognito student matching validation

Local main baseline: e1a2c76. Validated 2026-11-02. No commit, push, deployment, production access or matcher write-mode execution occurred.

## Behavior

Only the conjunction of source_format=bma2_v1 and source_name_role=student establishes the source name's student **label**. It does not establish the actual BMA person. Legacy Name ambiguity is unchanged. A shared email, phone, surname, address, Self claim or repeated waiver does not create a person/household merge or student link.

The scorer retains all weights and 0.90/0.45 default thresholds. Native student evidence includes explicit_student_name (zero additional points) and student_identity_unverified as a hard automation guard until an administrator-confirmed student link exists. Student DOB remains student evidence only, not signer demographics. The signer attachment eligibility helper explicitly rejects non-signer roles. No new automatic association path is introduced.

The matcher loads existing native metadata and recognizes previously administrator-confirmed student identity links read-only. Accepted payer/student links to different people remain conflicts; manual native student identity rejections outrank matching contacts. Saved confirmed/rejected candidate decisions and manual holds are preserved before preview scoring, with the transactional save protection retained for future authorized write runs. The candidate database role remains source_name_unresolved; comparison_role identifies its correct interpretation in CLI summaries.

The queue/card shows Student comparison / STUDENT (SOURCE LABEL), with BMA identity separately unverified or administrator reviewed. A confirmed link with recorded reviewer/time is required for the latter; source labels alone do not qualify. Legacy cards keep their ambiguity labels. Existing saved role-unverified evidence is interpreted using current source metadata without overwriting stored evidence/reasons or audit history. Actions remain explicit administrator decisions; no parent/student/self relationship is inferred by display.

CLI numeric summary scores are rounded to at most four decimal places and encoded without verbose binary tails. Persisted scoring precision and thresholds remain unchanged. Output still excludes names, addresses, contact values and DOB.

## Local tests

The focused suite uses synthetic fixtures in its own localhost-only temporary database, initialized through existing migrations. In-process matcher connections reject all non-SELECT SQL during matching. Every invocation is run(true); the actual CLI subprocess always receives --dry-run. Table hashes prove people, members, links, candidates, relationships, waivers, customer associations and audits do not change during matching or review GETs. Manual review tests deliberately exercise explicit administrator decisions only in disposable fixtures.

| Check | Result |
|---|---|
| Native source student label, separate BMA identity uncertainty | PASS |
| Legacy roles, scores and ambiguity protections | PASS |
| High-confidence adult self-payer still requires student identity review | PASS |
| Parent/child and shared household contacts remain separate | PASS |
| Repeated waivers stay separate; reviewing one does not auto-link another | PASS |
| Manual confirmations, rejections, holds and audit survive dry-run reruns | PASS |
| Existing accepted student links/different-person conflicts and rejection precedence | PASS |
| Student comparison cannot use signer automation | PASS |
| Saved historical native evidence gets correct labels without rewriting history | PASS |
| Older unsnapshotted decision cannot be reversed automatically | PASS |
| Administrator-only queue, dashboard DENY and invalid CSRF rejection | PASS |
| CLI JSON precision and actual CLI dry-run read-only behavior | PASS |

Existing security.php, dashboard-access.php and cognito-compatibility.php regressions were run in the same disposable fixture database. The old default tests/matching.php scenarios invoke matcher write mode and were deliberately not run. A new explicit `--dry-run-only` entry point executes the focused native/legacy matching/review regression instead; no default write-mode command was executed. Test fixture records/config/sessions/logs and its database are removed afterward.

Final results: **183 checks passed**: 48 focused matching/review checks, 31 security checks, 28 dashboard-access checks and 76 Cognito compatibility checks. All nine new/modified PHP files passed PHP lint, and git diff --check passed. Baseline SHA-256 checks confirmed all other existing runtime, SQL, configuration and security files are unchanged. Exact comparisons against HEAD also confirmed the manual decision/history/undo implementation and matcher persistence/signer-attachment bodies are unchanged. No matcher write-mode execution occurred.

## Exact local commands (PowerShell)

PHP 8.2 requires existing PDO MySQL/mbstring and cURL/DOM for HTTP rendering assertions. Use only development credentials for an existing localhost MariaDB server; the test user needs permission to create/drop the suite's own randomly named bma_schema_test_native_match_* database. No existing database is rebuilt and no production connection is used.

```powershell
$files = @('app/Matching/WaiverSourceRole.php','app/Matching/IdentityMatcher.php','app/Matching/MatchScorer.php','app/Matching/PersonResolver.php','app/Matching/MatchReviewService.php','public/admin/matches.php','bin/match-identities.php','tests/matching.php','tests/native-student-matching.php')
foreach ($file in $files) { php -l $file; if ($LASTEXITCODE -ne 0) { throw 'PHP lint failed' } }
# Pure scorer/source-label checks only:
$env:BMA_NATIVE_MATCH_DB_TEST = '0'
php tests/matching.php --dry-run-only
# Focused disposable matching/review/authorization regressions:
$env:BMA_NATIVE_MATCH_DB_TEST = '1'
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '33317' # Substitute your existing local server port.
$env:DB_USERNAME = '<LOCAL_TEST_USER>'
$env:DB_PASSWORD = '<LOCAL_TEST_PASSWORD>'
php tests/matching.php --dry-run-only
git diff --check
```

Do not run tests/matching.php without --dry-run-only for this task. Do not run the matcher CLI without --dry-run. The CLI invocation inside the test also forces serialize_precision=17 to verify that its output remains concise. No source CSV is needed for these synthetic identity scenarios.

## Exact file inventory

Runtime PHP:

- New app/Matching/WaiverSourceRole.php â€” shared native label and identity-notice interpretation.
- Modified app/Matching/IdentityMatcher.php â€” trusted metadata, read-only manual precedence, native accepted/rejected student identity interpretation, rounded summaries.
- Modified app/Matching/MatchScorer.php â€” explicit native student evidence with mandatory identity-review guard; legacy weights unchanged.
- Modified app/Matching/PersonResolver.php â€” carry trusted metadata and prohibit student comparisons in signer eligibility.
- Modified app/Matching/MatchReviewService.php â€” queue projection only; manual decision/undo writers unchanged.
- Modified public/admin/matches.php â€” correct source labels, identity notice and historical-evidence presentation.
- Modified bin/match-identities.php â€” concise numeric JSON serialization.

Tests:

- Modified tests/matching.php â€” opt-in dry-run-only entry point; existing write-mode scenarios remain intact and unexecuted.
- New tests/native-student-matching.php â€” pure scoring, dry-run matching, explicit manual-review fixtures and real HTTP access/rendering regressions.

Documentation:

- Modified docs/identity-matching.md.
- Modified docs/match-review.md.
- New docs/native-student-matching-validation.md.

No SQL migration, schema, import mapping, importer, emergency-contact data/display, authentication/session provider, dashboard permission, configuration/path or asset files were changed. No production data was accessed. The change uses the existing native metadata columns and current candidate-role CHECK/unique constraints, so **no database migration is necessary**. Existing candidate display is corrected immediately from metadata; no write-mode refresh is required for labels.

## Pre-existing snapshot checksum limitation (not changed)

A regression initially loading sql/schema.sql hit the existing migration-history check. The snapshot records migration 011 checksum c743149523dbb3b8ca8d60435045dc2e609e6023ec52c4d902b6eb714014e041. The current Git migration's LF-normalized bytes hash to 750725bd25f83101f609ee87b990de5af22d5c3fa760447cf42253def51e4277; the Windows CRLF working copy hashes to 9e882282682904f3fd6410ff3918423f8000bb798ab96f18d8f177f4cbbf4c40. The file matches Git after line-ending normalization, so the snapshot discrepancy is pre-existing and not explained solely by CRLF.

No schema/migration/history files or existing databases were edited to bypass this. The disposable test database was instead initialized by the existing runner using its actual current migration files, which records matching fixture checksums normally. This feature needs no migration, but the snapshot metadata discrepancy should be reviewed separately before relying on that snapshot for fresh installations. Production migration history/state was not inspected and no conclusion about its checksums is claimed.

# Other Waiver Associations

Administrators with Members access can select **Find Other Associations** on
`/member.php?person=...`. The CSRF-protected request searches all imported waiver
records and opens `/admin/waiver-associations.php`. It does not run the general
matcher, change scores, allocate money, create people or enroll members.

## Evidence and review

Discovery reuses `MatchScorer`'s curated nickname/middle-initial comparison and
`Normalizers`. Originals remain unchanged. Full middle names are retained.
Person contacts and email from accepted, synchronized Stripe customer links
provide source-backed comparison evidence. The customer schema has no phone
field; no phone is invented. Waiver contacts remain explicitly unverified.
Medical information and raw waiver JSON are never queried by discovery.

- **Verified Association**: an accepted, synchronized student/signer identity,
  existing reviewed payer/parent/guardian relationship, or separately confirmed
  emergency-contact identity.
- **Strong Possible Association**: compatible name plus email, reliable phone,
  exact full address or (student only) birth date agreement, without a conflict.
  This is a proposal, never automatic verification.
- **Possible Association**: supporting name evidence without independent agreement.
- **Conflicting or Ambiguous**: different/ambiguous names, another accepted person,
  conflicting birth date or legacy source-name ambiguity. Shared household
  contacts alone do not prove identity. Emergency roles use their own contact
  phone, not the waiver's general email. Native student labels do not verify BMA
  identity; legacy name ambiguity is preserved.

Results group by accepted student person ID. Without one, grouping requires the
same comparison name (without nickname expansion) and birth date; otherwise each
waiver remains separate. Multiple roles share one submission/history entry.

Confirmation requires an explicit role, reason and acknowledgement. Student and
signer identities use `IdentityAssociations`; payer/parent/guardian decisions use
its reviewed relationship API with a separate accepted student. No membership is
created. Parentage/guardianship requires independent administrator verification;
an emergency designation or household contact is insufficient. Imported waiver
values remain read-only; only authoritative normalized identity caches can change.

Existing accepted owners, rejected identities and review holds cannot be silently
overridden. Rejections suppress that person's same waiver/role proposal on future
searches. Leave Unresolved preserves a hold. **Resume Review** explicitly and auditably
reopens only this discovery hold, refreshes its evidence and confirms no identity.
It never releases existing matching/reconciliation holds or rejections. This screen
does not implement reassignment or undo of accepted/rejected decisions. Existing
reviewed identity corrections stay in their established workflow; correcting a
rejected emergency review would require a separate audited reopening operation.

All decisions run in one identity transaction, lock the person/source/review rows,
check current evidence and student target, and audit actor, time, role, before/after
status and reason. Audit failure rolls back identity/relationship changes. Repeated
requests cannot create duplicate associations. Changed evidence requires rediscovery.
Persisted evidence contains codes, source hashes and foreign keys, not contact values.

## Directory and privacy

The administrator-only **Association Count** filter and sortable **Associations**
column operate before server-side pagination. Counts distinguish verified and
cached proposed **distinct person-role pairs**; repeated waivers never inflate
them. Multiple-person filters count distinct known BMA targets. Unverified student
targets are not counted as separate people, and unknown targets share a single
unresolved bucket per role. Role filters include identity and reviewed relationship
roles. Duplicate verified/proposed edges count once, as verified. Proposals reflect
only requested searches, not complete discovery coverage; zero cached suggestions
does not prove no association exists. Ordinary loads use aggregates over links,
relationships and the indexed cache, never a discovery scan of source text.

Admin-only HTML shows source roles, restricted contact evidence and review status.
Viewer/staff API responses omit the association column and reject its filters.
Existing emergency-contact viewing permissions, Stripe association editor,
dashboard permissions, CSP and session authentication are unchanged.

## Migration and deployment gate

`013_waiver_association_reviews.sql` adds one indexed, foreign-key-constrained table
for discovery state and emergency identities. Its SQL has no dependency on 012,
but the existing migration runner enforces consecutive migration history.
Production migration 012 is still pending and unapproved: that sequencing decision
must be resolved before installing 013 through the normal runner. Do not bypass
migration history or silently apply 012.
**Separate approval is required before applying 013 to production.** Do not use an
unrestricted installer command that would also apply unapproved pending 012.
No production schema operation is part of this release. Without the 013 migration
marker and table, discovery/confirmation are disabled; the directory remains
functional and labels proposed counts unavailable. The source migration is not
selectively uploaded as a runtime file.

## Focused validation — November 10, 2026

- PASS: migration 013 executed on the existing disposable local MariaDB restore;
  original source schema/migrations were not rewritten.
- PASS: **45** focused PHP checks: names/nicknames, household ambiguity, two
  students, repeated submissions, accepted-link protection, rejection/hold
  precedence, explicit payer/parent roles, audit rollback, changed-source guards,
  source-value preservation, explicit hold resumption without identity changes,
  no memberships and 25/50/100/250 pagination beyond 50.
- PASS: **22** authenticated Chromium checks using synthetic local waivers and
  the existing restored dataset: direct profile search, signer/emergency roles,
  grouped history, explicit emergency confirmation, safe rendering, filters,
  sorting, mobile layout, CSRF, viewer/anonymous denial, and Stripe editor preview.
  An interrupted login/browser run was retried successfully; authentication code
  was not changed.
- PASS: **4** additional authenticated pending-migration browser checks: warning,
  disabled discovery, working directory, unavailable proposed-count label.
- PASS: **3** additional authenticated browser checks for explicit discovery-hold
  resumption, using a synthetic local signer proposal.
- PASS: changed PHP lint and Git whitespace checks. Previously passed broader
  Stripe editor/security tests are reused; no unrelated regression suite run.
- Production authentication/UI actions are not claimed as browser-tested. Only
  anonymous live guards and deployed-file hashes are checked during deployment.
  No production identity decisions, source changes or migration are authorized here.

Release verification: seven runtime files were selectively deployed from commit
`547e2fa70d5f2f022ca7b016aa9c155db4a88e9d` after verifying five existing
production baselines and two new targets. Private before-file backups, staged
hashes, installed read-back hashes and PHP lint all passed. Live login, unchanged
CSP, and anonymous Members/API/waiver-review/Stripe-editor access guards passed.
Neither migration 012 nor 013 was uploaded/applied to production. Original eight
business-table fingerprints on the disposable restore remained unchanged after
fixture cleanup (328 people, 34 members, 1,282 transactions). No production
database operation or identity decision was performed.

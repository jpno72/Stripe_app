# Identity matching (Step 3)

Step 3 stores explainable Stripe-customer ↔ Cognito-waiver suggestions. It does **not** treat shared email, phone, address, or surname as a person ID. For this native-role correction, inspect a read-only preview; no migration or write-mode matcher execution is needed:

```sh
php bin/match-identities.php --dry-run
```

Migration `004_identity_match_candidates.sql` adds `identity_match_candidates`, reason/evidence columns on `identity_links`, and waiver lookup indexes. The matcher uses only the source fields marked `identity_score=true` in [config/import-mappings.php](../config/import-mappings.php). The matcher reports source **record IDs**, scores, role labels, and reason codes. It does not print names, email, phone, addresses, birth dates, or raw rows. Candidate `evidence` is a JSON list of comparison codes and conflicts, not the values compared. `identity_links` remains authoritative for accepted person associations; its cached foreign keys are synchronized only by `IdentityAssociations`.

## Roles and source limits

- A Stripe customer is a financial account/payer snapshot. A marked `is_stub` customer is excluded from matching.
- `Print Name` is the signer label. Legacy `Name` remains ambiguous. For **both** `source_format=bma2_v1` and `source_name_role=student`, source_name is an explicitly labeled student name, while the actual BMA person identity still requires administrator confirmation. The existing internal candidate value `source_name_unresolved` is retained for database compatibility; it does not mean a native student label is unknown. No student/person link is automatically assigned.
- Cognito contact email, phone, and address may belong to a signer, student, parent, or household. They provide comparison evidence only.
- `Relationship to student` is preserved as source text. `Self` does not prove two differing names are one person. `Mother`, `Father`, `Parent`, and `Guardian` are clues, not permission to create a relationship automatically.
- The original shelled CSV has no stable Cognito entry ID, Stripe customer phone, or Stripe customer birth date. Birth-date agreement cannot usually be scored for these cross-source records. We do not infer a student's DOB as the signer's DOB.

## Candidate generation and scoring

The matcher considers non-stub customers against waivers sharing an exact normalized contact email or exact source/signer name, plus previously stored candidate pairs. It uses indexed lookups and processes customers in small pages. Rechecking prior pairs lets changed evidence lower a stale score. Missing fields contribute nothing. Exact full name is worth 42 points, exact last name 10, email 24, phone 16, postal code 8, address 12, and birth date 32. Different full names subtract 10; different last names subtract 25; conflicting birth dates subtract 80. Near names can add at most 5. Scores are clamped to 0–100 and stored as 0–1 decimals.

The scorer records signal and conflict codes, such as `exact_email`, `exact_full_name`, `different_last_name`, and `conflicting_birth_date`. It records `source_name_role_unverified` only for the legacy ambiguous Name role. A native student comparison records `explicit_student_name` without adding points, and `student_identity_unverified` until an accepted student link has administrator confirmation. Source-label certainty and person-identity certainty are separate. A `Self` label with differing `Name` and `Print Name` adds `self_label_names_disagree`. Birth-date/self conflicts, legacy role ambiguity and unverified native student identity prevent automatic association regardless of numeric score. Student comparisons can never enter the signer auto-attachment path, even after manual confirmation. The evidence JSON never contains the compared source values.

`AUTO_MATCH_THRESHOLD` defaults to `0.90`; `REVIEW_THRESHOLD` defaults to `0.45`. Set both in private configuration or environment, with review below auto. A score below review is `unmatched`; review-level evidence is `possible`. An existing pair of accepted identity links to the same person is `linked` with score 1.0; native student identity additionally requires a confirmed student link with recorded reviewer/time. Existing accepted links to different student/payer people remain conflicting, and rejected native student links take precedence over contact coincidence. Accepted links to different people or a manual rejection produce `conflict`. A high score alone does not create a new person. Automatic signer attachment requires a previously accepted Stripe-customer→person link, a unique top candidate, no hard conflict, exact full name, exact email, and another independent phone/address/postal signal. It uses `IdentityAssociations` in a transaction and writes a scored authoritative link. The original shelled sample did not provide enough verified evidence for that path. The native-label change does not broaden automatic signer eligibility or authorize running write mode.

Name-only and household-contact-only suggestions never automatically merge people. The last token of a multiword name is a limited surname clue, not a cultural or legal-name parser. Address comparison is exact normalized text; a unique five-digit ZIP within waiver address text may provide postal evidence. These rules favor review over speculative matching.

## Review, relationships, and manual overrides

The matcher does not create `people` or `members`. An administrator can create separate finalized people with `People::create`, then use `PersonResolver::confirmReviewedHousehold` after checking the actual form and records. It accepts payer, signer, and student person IDs explicitly. `IdentityAssociations::decideMany` confirms all three external roles, creates the student member with `member_since = NULL`, creates the reviewed payer/parent/guardian/self edges where appropriate, and writes audit records in one transaction. For a mother/father/parent label it creates signer→student `parent` and student→signer `child`; for a distinct payer it adds payer→student `payer`. An unsupported or contradictory source relationship fails explicitly. The caller must supply an active application administrator; the existing Step 11 session and dashboard guards protect the review UI.

Manual `confirmed` and `rejected` identity links block later automated reassignment. `PersonResolver::reviewCandidate` lets an active administrator confirm a candidate only after both external roles have accepted links to the same person, or reject a false candidate when it has no contradictory accepted links. It records reviewer/time and an ID-only audit entry. `IdentityAssociations` refuses a later person-link change that would contradict a reviewed candidate; the administrator must revise the reviewed decision explicitly first. The matcher refreshes derived candidate scores but does not overwrite manually reviewed candidate rows. A rerun uses unique customer/waiver/role keys, so it updates suggestions rather than duplicating them. Rejections remain authoritative even when a later export has the same email/name.

## Duplicate detection and supplied-data result

The matcher reports groups of non-stub Stripe customers with the same normalized email and billing name, and waivers with the same approved content hash. It does not merge those groups. Step 2 already deduplicates by Stripe external IDs and Cognito locators.

On the supplied shelled data, one non-stub customer and one waiver yielded two candidate roles. Both scored `0.14` and were `unmatched`: the contact email agrees, but billing, `Name`, and signer labels do not establish one person; `Self` also conflicts with the differing waiver names. There were **zero automatic links, zero people created, and zero duplicate-source groups**. The candidate rows retain code-only evidence for later review.

A separate synthetic Jane/Johnny test confirmed Jane as Stripe payer and waiver signer, Johnny as student/member, and the parent, child, and payer relationships. They remained separate people despite sharing email and phone. A rerun preserved confirmed links, and a manual rejection stayed rejected.


## Native BMA2 student interpretation — 2026-11-02

The matcher and queue load existing source_format/source_name_role metadata. Native source names are compared as student names while BMA identity stays unverified. Native student confirmation is an accepted `student` identity link with match_status confirmed, a reviewer and review timestamp matching the normalized waiver association; a form label, interpretation flag, household contact or automatic link alone is not that confirmation. Existing human-reviewed links can be recognized without creating new links. Legacy ambiguity, scoring weights (42/10/24/16/8/12/32) and thresholds (0.90/0.45) are unchanged.

Saved confirmed/rejected candidates and manual_review_pending holds are checked before scoring both normal and dry-run summaries, and the transactional save guard remains. Dry runs therefore count manual_preserved instead of presenting a recomputed suggestion as a replacement. No manual evidence/history is rewritten. Native student rejections are read with the student role; signer rejections keep their prior protection.

CLI output keeps the legacy internal role key and adds comparison_role (`student`, `signer`, `unresolved_name`) plus student_identity_reviewed. Numeric summary scores are rounded to at most four decimal places; CLI JSON avoids binary floating-point tails without changing scoring thresholds or stored precision. No names/contact/DOB values are added to output.

The review UI derives accurate labels for existing saved candidates using current source metadata. Historical source_name_role_unverified codes remain stored unchanged and are presented as student identity review concerns for native forms, not as an unknown source role. No matcher write pass is needed to correct presentation. See [native matching validation](native-student-matching-validation.md).

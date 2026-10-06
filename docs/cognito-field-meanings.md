# Cognito source meanings and identity

## What the supplied file establishes

The actual headings are Email, Date Submitted, Name, Phone, Text Friendly, Student Birthdate, Address, Emergency Contact, Emergency contact Phone, considerations, goals, Print Name, Date, and Relationship to student. There is one populated shelled record. Its relationship label is Self. Name and Print Name contain different source text, so the sample does not justify treating them as one person. There is no form definition or genuine entry/submission ID in the export.

| Concept | Available evidence | Interpretation |
|---|---|---|
| Student/participant | Student Birthdate explicitly identifies the demographic subject; Name is ambiguous | Store birth date as a source student demographic and Name as source_name; leave person association unresolved |
| Signer | Print Name, Date | Printed-name and date snapshots; not a stored signature or proof of legal validity |
| Parent/guardian | Relationship to student free text | Preserve text; create an internal relationship only after explicit evidence/review |
| Contact owner | Email, Phone, Address | Household/contact data; do not assume student ownership |
| Text permission | Text Friendly | Preserve source preference; do not infer broader marketing consent |
| Sensitive free text/third parties | considerations, goals, Emergency Contact, Emergency contact Phone | IGNORE by default; no retained values |

Jane/Johnny handling: create separate people after review, attach Stripe customer to Jane, member to Johnny, waiver signer to Jane, and waiver student to Johnny. Preserve Mother as source text and add parent/payer edges only as explicit confirmed decisions. Shared email/phone is not identity.

## Timestamp inspection and configuration

The observed Date Submitted format is M/D/YYYY H:mm, for example a January 3, 2021 local clock reading at 16:03. It has no UTC marker or offset. It is timezone-naive. Date is a calendar date, and Student Birthdate is a calendar date.

COGNITO_SOURCE_TIMEZONE is deliberately empty/unresolved in both example configurations. America/New_York is a likely operator choice after confirmation; parsing has no business timezone default. With no setting, normalized submitted_at stays NULL and TIMEZONE_UNRESOLVED is recorded. The approved original Date Submitted value remains in filtered staging.

Explicit ISO offsets, when present in future approved timestamp values, take precedence over configuration. Normalized timestamps use UTC. Naive timestamps use the explicitly configured IANA zone. Ambiguous fall-back and nonexistent spring-forward local times remain unresolved for review. Calendar dates are never timezone-shifted.

## Exact deduplication algorithm, version 1

There is no row position or file hash in a waiver identity. source_row_number identifies a staging position for diagnostics only.

1. Require a stable, configured COGNITO_FORM_NAMESPACE. The example bma-waiver is an operator namespace, not an exported Cognito form ID. Do not change it after importing without an explicit identity migration.
2. Prefer a trusted genuine entry ID if an approved future export mapping/API supplies one. source_record_id stores that original ID; source_id_kind is external. Current exports have no such field, so source_record_id is NULL.
3. Otherwise use the tuple [namespace, canonical Date Submitted, canonical Name, canonical Print Name]. Both names are uninterpreted source labels. Canonical names are trimmed, whitespace-collapsed, and Unicode-lowercased. The timestamp is formatted YYYY-MM-DD HH:MM:SS with an instant: prefix for explicit-offset UTC timestamps or civil: for unzoned clock readings. The fallback requires all three fields; missing components produce IDENTITY_AMBIGUOUS rather than email/row-position identity.
4. source_locator is cog-v1- followed by the SHA-256 of the ordered tuple encoded as unescaped-Unicode JSON. The genuine-ID tuple is [namespace, original entry ID]. Date canonicalization is independent of the configured zone for naive values, so a later timezone resolution does not change identity.
5. content_hash is SHA-256 of the approved-field object with keys sorted bytewise, NULL normalized to an empty string, strings trimmed, and CRLF/CR normalized to LF. JSON uses unescaped Unicode and slashes. All approved fields, including absent fields as NULL, are represented. Ignored/unknown fields are excluded before hashing.
6. The database uniquely indexes (form_namespace, source_locator) and (form_namespace, source_record_id). Same locator plus same hash is a no-change submission. Same locator plus changed hash is a candidate update, subject to ambiguity checks. Different reliable locator is a candidate new submission. Reordered files/columns and changes confined to ignored values do not create a new identity.

### Necessary ambiguity safeguards for Step 2

The supplied export has minute-resolution timestamps and no true record ID. No algorithm can prove that two indistinguishable submissions are different, or recognize every edit to all identifying fields. This is a source limitation, not something hashing solves.

- Two rows sharing a fallback locator but differing in approved content within the same import require review; never let the last row silently win.
- A changed hash under an existing fallback locator may represent an edit or a distinct same-minute submission. Require review when that distinction cannot be established.
- Edits to Date Submitted, Name, or Print Name change the fallback locator. Step 2 may stage a distinct unresolved waiver because the export has no stable ID; do not create a person from it or claim those edits are automatically deduplicated. Reconcile possible duplicates during administrative identity review.
- A changed source locator needs a deliberate reconciliation workflow before it can be bound to an existing waiver. Step 2 stages the approved values and leaves person associations unresolved; it does not perform a manual merge.
- A stable Cognito entry ID is the preferred future resolution. Unknown new ID headings are still ignored until explicitly approved in the mapping.

The Step 2 importer uses the Stage 1 helper for locators and hashes. An unchanged locator and hash skips normalization. Changed content under a fallback locator is staged and flagged for review, without overwriting the normalized waiver.

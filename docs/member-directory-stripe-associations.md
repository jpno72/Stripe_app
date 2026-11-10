# People directory and reviewed Stripe associations

The Members navigation continues to use `/member.php`. Its existing People lookup now uses server-side DataTables at `/api/people.php`; the Membership analytics page remains a member-only dashboard. Both tables offer 25, 50, 100 and 250 rows with numbered, next and previous controls. Directory sorting/filtering occurs before LIMIT/OFFSET, so every matching record can be reached without loading all people into the browser.

The directory has All People / Members Only, name/BMA ID (retaining existing email/phone search), actual member status, age bounds, explicit program, customer/subscription/payment presence and subscription-status filters. Unknown DOB is excluded only when an age bound is supplied; age is calculated in the configured local calendar. Sort columns are an allowlist: BMA ID, first/last name, calculated age, member status/start, program, customer context, subscription status and latest recorded transaction date. Payer accounts and their subscriptions/payments are labelled household context, not enrollment or per-child revenue. Programs require explicit current member assignments. Reset clears all filters. Viewers retain their existing Members permission; no DOB/medical fields are returned by the directory API.

## Administrator review

An administrator with effective Members permission can open **Manage Stripe Associations** from an existing profile. `/admin/stripe-associations.php` independently enforces the application session, canonical active administrator role and dashboard permission. All POSTs, including read-only preview, require CSRF. There are no live Stripe API requests.

1. Enter an existing imported cus_/sub_/pi_/ch_ (or supported gcus_/py_) ID, choose the role and intended existing person, and record a reason. BMA selection offers bounded name/ID suggestions; the paginated directory can be opened separately.
2. Review recorded source information, ownership, current assignments and intended changes. Existing accepted owners and rejected decisions require an explicit conflict acknowledgement. Snapshot digests prevent confirmation against changed source/association state.
3. Confirm separately. The authoritative identity service performs customer ownership/payer changes transactionally. Each operation stores actor, timestamp, reason, before/after snapshots and an idempotency receipt in `audit_log`. Repeated confirmation returns the receipt without duplicate associations or audit entries.

An existing owner can be corrected only after explicit conflict confirmation. A correction contradicting a reviewed identity candidate is still blocked by the existing identity service; resolve the dependent decision in Matches first. Removing an own-account link records a rejection and clears the cached owner; it does not delete the customer. Removing a payer relationship detaches all that payer's account context from this student, preserving ownership and other students. No legal parent/guardian/self relationship is inferred. Controlled new-payer creation allocates one immutable BMA ID only on confirmation, with names explicitly entered by the administrator; it never copies contact/DOB data or creates membership. A matching existing full name requires selection or prior ambiguity review.

Subscriptions use existing `subscription_members` rows and require an existing member. The Stripe owner and billing object remain unchanged. Sharing with another member requires explicit approval. Older removed assignments close before the current local day and remain in the mapping table. Same-day corrections retain the complete old mapping, including notes/dates, in the immutable before/after audit; no Stripe or member record is deleted. Historical assignments are retained read-only.

## Direct payment allocation — requires separately approved migration 012

`stripe_transaction_attributions` holds one current reviewed recipient/role per stored transaction, independently of imported `customer_id`. A PaymentIntent lookup must resolve to exactly one source transaction, otherwise the charge identifier is required. One payment cannot be currently allocated to two students; corrections need acknowledgement and preserve audit history. A payer allocation supplies unallocated account context to that payer's verified students. A direct-person allocation applies only to the selected person. No split amounts are invented.

Profile/directory selectors combine account and explicit-payment membership with EXISTS/OR, retaining one row per transaction. Source identifiers, amounts, status, refunds, dates, subscription and customer fields never change. Aggregated financial views remain at transaction grain and are not joined to allocation rows; global revenue cannot multiply. Source-account context can still appear on its owner profile. If explicit allocations occur within a profile's financial scope, full-member revenue cards remain unavailable: account context must not be summed across family profiles. Amounts, success/failure/full-refund counts and collections-before-fees continue to follow existing profile accounting rules; missing fees stay unknown.

Until both table and migration-history entry exist, direct allocation is disabled explicitly. Customer and subscription review, directory and existing profiles continue working. No migration or association change is executed by deployment.

## Production approval, backup and rollback

**Do not apply migration 012 without separate production authorization.** Commit contains `sql/migrations/012_profile_transaction_attribution.sql` and guarded `bin/rollback-profile-attribution.php`. No previous migration or schema snapshot was rewritten.

The code-only deployment does not upload or apply the migration or rollback CLI. On separate approval, copy those reviewed files into the private project, and configure the trusted `BMA_CONFIG_FILE` for CLI use.

Before approved application: take a fresh complete private SQL backup with table data, views/triggers/routines/events; verify SHA-256 and restore it to a disposable database. Export and check current schema history. Inspect the exact pending SQL: it creates only one empty association table and its indexes/FKs/check; it changes no financial or identity source table. Verify normal application CRUD grants for the new table. Use the existing installer `php bin/install.php --check`, then `--apply` only under the separate authorization.

The restored Windows checkout revealed LF/CRLF-only checksum differences for historical migrations 006–011. Their content matches the recorded LF versions. Do not rewrite recorded checksums or replay older SQL. Use byte-exact, verified historical migration sources matching the database history; on any remaining mismatch stop for review. New migration 012 was executed alone on the disposable restore; its empty-table rollback and reapplication passed.

Rollback requires maintenance/backup and 012 to be the latest applied migration. `php bin/rollback-profile-attribution.php --apply` takes the same editor mutex, verifies its checksum/latest version, and refuses a nonempty table. For an empty table it removes only the allocation table and its history entry; financial data, identities and audit history remain. If allocations exist, stop and prepare a separately approved private export/retention plan; never silently delete them. Any interrupted DDL/history operation requires manual review. Deploying code without 012 is safe; the gate remains closed.

## Focused validation

See [validation results](member-directory-stripe-validation.md). Tests use only disposable local databases. No production association changes are part of testing or deployment.

# Import field mapping

This approved mapping is enforced by the Step 2 importer. Review it before importing live data, especially if the source exports change.

The executable contract is [config/import-mappings.php](../config/import-mappings.php). IMPORT and approved OPTIONAL values may be persisted. IGNORE and unknown values are discarded before serialization. OPTIONAL means approved but not required. IMPORT describes the core mapping, not a promise that every source row has a nonempty value.

All fields have `log_value=false`. The `identity_score=true` entries explicitly approve only the Step 3 matcher inputs: Stripe customer name, email, address line 1, and postal code; Cognito contact email, source name, contact phone, student birth date, address, signer print name, and relationship to student. The matcher treats the unresolved source name and student birth date as review evidence, never as proof of signer or student identity. All other fields, including ignored and unknown columns, are excluded from scoring. Contact coincidence alone never merges people. The sensitive flag classifies personal/financial data, not just medical information. Sensitive retained fields are restricted to authorized internal users. Reporting views use normalized approved fields; ordinary demographic reports exclude ignored waiver fields.

Inspection: customers 14 headings / 1 populated row; subscriptions 13 / 1; transactions 23 / 2; Cognito 14 / 1. Remaining records are empty. The source values were inspected but are not copied into this document.

## stripe_customers

| Source column | Action | Database destination | Purpose | Sensitive |
|---|---|---|---|---|
| id | IMPORT | `stripe_customers.stripe_customer_id` | Customer identifier | Yes |
| Description | IGNORE | None | No documented reporting purpose for customer free text | Yes |
| Email | IMPORT | `stripe_customers.email` | Contact evidence | Yes |
| Name | IMPORT | `stripe_customers.name` | Billing name snapshot | Yes |
| Created (UTC) | IMPORT | `stripe_customers.stripe_created_at` | UTC creation date | Yes |
| Card ID | IGNORE | None | Payment method identifier unnecessary for reporting | Yes |
| Address Line1 | OPTIONAL | `stripe_customers.address_line1` | Billing address | Yes |
| Address City | OPTIONAL | `stripe_customers.address_city` | Billing geography | Yes |
| Address Postal Code | OPTIONAL | `stripe_customers.address_postal_code` | Billing geography | Yes |
| Address Country | OPTIONAL | `stripe_customers.address_country` | Billing geography | Yes |
| Total Spend | IMPORT | `stripe_customers.total_spend_minor` | Summary reconciliation after currency resolution | Yes |
| Payment Count | IMPORT | `stripe_customers.payment_count` | Summary reconciliation | Yes |
| Refunded Volume | IMPORT | `stripe_customers.refunded_volume_minor` | Refund reconciliation after currency resolution | Yes |
| Dispute Losses | IMPORT | `stripe_customers.dispute_losses_minor` | Dispute reconciliation after currency resolution | Yes |

## stripe_subscriptions

| Source column | Action | Database destination | Purpose | Sensitive |
|---|---|---|---|---|
| id | IMPORT | `stripe_subscriptions.stripe_subscription_id` | Subscription identifier | Yes |
| Customer ID | IMPORT | `stripe_subscriptions.customer_id` | Join by Stripe customer ID | Yes |
| Customer Description | IGNORE | None | Customer free text unnecessary for reporting | Yes |
| Customer Email | OPTIONAL | `stripe_subscriptions.customer_email_snapshot` | Contact snapshot | Yes |
| Plan | IMPORT | `stripe_subscription_items.price_id -> stripe_prices.stripe_price_id` | Price reference | No, alone |
| Product | IMPORT | `stripe_subscription_items.product_name_snapshot` | Product label, not product ID | Yes |
| Interval | IMPORT | `stripe_subscription_items.billing_interval` | Billing frequency | Yes |
| Amount | IMPORT | `stripe_subscription_items.amount_minor` | CSV reported amount, unresolved until currency known | Yes |
| Status | IMPORT | `stripe_subscriptions.status` | Subscription lifecycle | Yes |
| Created (UTC) | IMPORT | `stripe_subscriptions.stripe_created_at` | Creation date, not membership start | Yes |
| Coupon | OPTIONAL | `stripe_subscriptions.coupon_reference` | Discount reference | Yes |
| Canceled At (UTC) | OPTIONAL | `stripe_subscriptions.canceled_at` | Cancellation date | Yes |
| Customer Name | OPTIONAL | `stripe_subscriptions.customer_name_snapshot` | Billing name snapshot | Yes |

## stripe_transactions

| Source column | Action | Database destination | Purpose | Sensitive |
|---|---|---|---|---|
| id | IMPORT | `stripe_transactions.stripe_charge_id` | Charge identifier | Yes |
| Created date (UTC) | IMPORT | `stripe_transactions.stripe_created_at` | Payment chronology | Yes |
| Amount | IMPORT | `stripe_transactions.amount_minor` | Charged amount | Yes |
| Amount Refunded | IMPORT | `stripe_transactions.amount_refunded_minor` | Refund total | Yes |
| Currency | IMPORT | `stripe_transactions.currency` | Original currency | No, alone |
| Captured | IMPORT | `stripe_transactions.captured` | Capture status | Yes |
| Converted Amount | OPTIONAL | `stripe_transactions.converted_amount_minor` | Converted amount | Yes |
| Converted Amount Refunded | OPTIONAL | `stripe_transactions.converted_amount_refunded_minor` | Converted refund | Yes |
| Converted Currency | OPTIONAL | `stripe_transactions.converted_currency` | Converted currency | No, alone |
| Decline Reason | OPTIONAL | `stripe_transactions.decline_reason` | Payment diagnostics | Yes |
| Description | OPTIONAL | `stripe_transactions.description` | Payment context | Yes |
| Fee | IMPORT | `stripe_transactions.fee_minor` | Source fee, unresolved until fee currency known | Yes |
| Refunded date (UTC) | OPTIONAL | `stripe_transactions.refunded_at` | Refund chronology | Yes |
| Statement Descriptor | OPTIONAL | `stripe_transactions.statement_descriptor` | Statement reconciliation | Yes |
| Status | IMPORT | `stripe_transactions.status` | Payment outcome | Yes |
| Seller Message | OPTIONAL | `stripe_transactions.seller_message` | Payment diagnostics | Yes |
| Taxes On Fee | OPTIONAL | `stripe_transactions.taxes_on_fee_minor` | Fee tax | Yes |
| Card ID | IGNORE | None | Payment method identifier unnecessary for reporting | Yes |
| Customer ID | IMPORT | `stripe_transactions.customer_id` | Join only by customer ID | Yes |
| Customer Description | IGNORE | None | Customer free text unnecessary for reporting | Yes |
| Customer Email | OPTIONAL | `stripe_transactions.customer_email_snapshot` | Contact snapshot | Yes |
| Invoice ID | OPTIONAL | `stripe_transactions.stripe_invoice_id` | Invoice reconciliation | Yes |
| Transfer | OPTIONAL | `stripe_transactions.transfer_reference` | Opaque payout/transfer reference | Yes |

## cognito_waivers

| Source column | Action | Database destination | Purpose | Sensitive |
|---|---|---|---|---|
| Email | IMPORT | `cognito_waivers.contact_email` | Contact owner unresolved | Yes |
| Date Submitted | IMPORT | `cognito_waivers.submitted_at` | Submission time; configurable timezone | Yes |
| Name | IMPORT | `cognito_waivers.source_name` | Role unresolved; no student assumption | Yes |
| Phone | IMPORT | `cognito_waivers.contact_phone` | Contact owner unresolved | Yes |
| Text Friendly | OPTIONAL | `cognito_waivers.text_friendly` | Contact preference; not general consent | Yes |
| Student Birthdate | OPTIONAL | `cognito_waivers.student_birth_date` | Explicit student demographic | Yes |
| Address | OPTIONAL | `cognito_waivers.address_text` | Address owner unresolved | Yes |
| Emergency Contact | IGNORE | None | Excluded: unnecessary third-party information | Yes |
| Emergency contact Phone | IGNORE | None | Excluded: unnecessary third-party information | Yes |
| considerations | IGNORE | None | Excluded: potential medical or special-consideration information | Yes |
| goals | IGNORE | None | Excluded: unnecessary sensitive free text | Yes |
| Print Name | IMPORT | `cognito_waivers.signer_print_name` | Printed name snapshot; association needs evidence | Yes |
| Date | IMPORT | `cognito_waivers.signed_date` | Source calendar date | Yes |
| Relationship to student | IMPORT | `cognito_waivers.relationship_to_student` | Original relationship label; no inferred person edges | Yes |

## Retention details

- Original strings of approved fields are kept in raw_values, including whitespace. Missing approved fields are NULL. The content hash uses trimmed strings and normalized line endings without changing retained values.
- Unknown column names may be reported as bounded, escaped header metadata. Their cell values are never retained. Unknown is always IGNORE, including future exports.
- ApprovedRecord constructs a new object directly from approved headings. It never serializes the complete row. All four database raw tables additionally reject JSON objects with nonapproved keys.
- No raw_record column remains in the current schema. There is one filtered serialization, raw_values. import_errors has no raw_values column.
- Changing a whitelist requires both a reviewed mapping change and a migration updating the corresponding database JSON-key CHECK. Tests exercise both layers.
- Stripe descriptions and diagnostic messages are explicitly approved as source snapshots but never logged or used for identity scoring. Their contents must be escaped when displayed.
- Stripe Card ID in customer and transaction exports, customer Description in the customer export, and Customer Description in subscription and transaction exports are IGNORE. Their values have no documented reporting purpose and are excluded from normalized and staging tables.
- Cognito considerations, goals, Emergency Contact, and Emergency contact Phone are IGNORE by default. Their normalized columns were removed. Enabling any later requires an explicit BMA decision, schema/mapping changes, role controls, and review of reporting exposure.
- Cognito Name is stored as source_name, with unresolved meaning. Email/phone/address ownership is unresolved. No source heading or value alone creates parent/student/person relationships.
- No fixed waiver retention period is established. The temporary-upload cleanup timeout is operational and does not define retention of normalized records.


## Actual native Cognito export contract — 2026-10-15

The original tables above describe the shelled legacy files. The 23-column BloomsburgMartialArtsAcademy2 export now uses the explicit internal cognito_waivers_bma2_v1 mapping. Sixteen approved original keys are retained separately from derived canonical Name/Address values; seven sensitive/third-party/workflow fields are ignored. Legacy mapping hashes and JSON keys remain unchanged. The complete source-column/action/destination/purpose table is in [cognito-import-compatibility.md](cognito-import-compatibility.md). Unknown columns still default to IGNORE, and only approved cells can be transcoded or serialized.

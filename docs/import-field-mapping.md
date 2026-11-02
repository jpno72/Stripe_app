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

Migration 011 expands only the subscription whitelist. The complete executable mapping is below (38 supplied headings; 21 approved and 17 ignored). All entries have log_value=false and identity_score=false. `Amount` is retained as integer minor units only with currency, with `amount_basis=csv_unresolved`; no unit-price or whole-item assumption is made. Legacy plans are stored separately without fabricated price IDs.

| Source column | Action | Destination | Purpose | Sensitive |
|---|---|---|---|---|
| `id` | IMPORT | `stripe_subscriptions.stripe_subscription_id` | Subscription identifier | Yes |
| `Customer ID` | IMPORT | `stripe_subscriptions.customer_id` | Join by Stripe customer ID | Yes |
| `Customer Description` | IGNORE | None | Customer free text unnecessary for reporting | Yes |
| `Customer Email` | OPTIONAL | `stripe_subscriptions.customer_email_snapshot` | Contact snapshot | Yes |
| `Plan` | IMPORT | `stripe_subscription_items.price_id OR stripe_subscription_items.legacy_plan_id` | Modern price or separate legacy plan reference | No, alone |
| `Product` | IMPORT | `stripe_subscription_items.product_name_snapshot` | Product label, not product ID | Yes |
| `Interval` | IMPORT | `stripe_subscription_items.billing_interval` | Billing frequency | Yes |
| `Amount` | IMPORT | `stripe_subscription_items.amount_minor` | Source-reported amount; currency and unit/total semantics remain distinct | Yes |
| `Status` | IMPORT | `stripe_subscriptions.status` | Subscription lifecycle | Yes |
| `Created (UTC)` | IMPORT | `stripe_subscriptions.stripe_created_at` | Creation date, not membership start | Yes |
| `Coupon` | OPTIONAL | `stripe_subscriptions.coupon_reference` | Discount reference | Yes |
| `Canceled At (UTC)` | OPTIONAL | `stripe_subscriptions.canceled_at` | Cancellation date | Yes |
| `Customer Name` | OPTIONAL | `stripe_subscriptions.customer_name_snapshot` | Billing name snapshot | Yes |
| `Product ID` | OPTIONAL | `stripe_subscription_items.product_id` | Exact product reference; not a program assignment | Yes |
| `Quantity` | OPTIONAL | `stripe_subscription_items.quantity` | Positive source item quantity; not a payment count | Yes |
| `Currency` | OPTIONAL | `stripe_subscription_items.currency` | Explicit source currency | Yes |
| `Start (UTC)` | OPTIONAL | `stripe_subscriptions.csv_start_at` | Distinct source Start clock; not an alias for Start Date | Yes |
| `Start Date (UTC)` | OPTIONAL | `stripe_subscriptions.stripe_subscription_started_at` | Source subscription start date, never member enrollment | Yes |
| `Current Period Start (UTC)` | OPTIONAL | `stripe_subscriptions.current_period_start` | Recorded billing period start | Yes |
| `Current Period End (UTC)` | OPTIONAL | `stripe_subscriptions.current_period_end` | Recorded billing period end, not a promised payment date | Yes |
| `Cancel At Period End` | OPTIONAL | `stripe_subscriptions.cancel_at_period_end` | Source scheduled cancellation flag | Yes |
| `Ended At (UTC)` | OPTIONAL | `stripe_subscriptions.ended_at` | Explicit subscription end instant | Yes |
| `Cancellation Reason` | IGNORE | None | Not required for subscription item reporting | Yes |
| `Cancellation Feedback` | IGNORE | None | Not required for subscription item reporting | Yes |
| `Trial Start (UTC)` | IGNORE | None | Not required for subscription item reporting | Yes |
| `Trial End (UTC)` | IGNORE | None | Not required for subscription item reporting | Yes |
| `Application Fee Percent` | IGNORE | None | Not required for subscription item reporting | Yes |
| `Tax Percent` | IGNORE | None | Not required for subscription item reporting | Yes |
| `Exclusive Tax Rate` | IGNORE | None | Not required for subscription item reporting | Yes |
| `Inclusive Tax Rate` | IGNORE | None | Not required for subscription item reporting | Yes |
| `Automatic Tax Enabled` | IGNORE | None | Not required for subscription item reporting | Yes |
| `Tax Location Recognized` | IGNORE | None | Not required for subscription item reporting | Yes |
| `mmposCustID (metadata)` | IGNORE | None | Not required for subscription item reporting | Yes |
| `mmposCurrency (metadata)` | IGNORE | None | Not required for subscription item reporting | Yes |
| `mmposCreatedBy (metadata)` | IGNORE | None | Not required for subscription item reporting | Yes |
| `createdDate (metadata)` | IGNORE | None | Not required for subscription item reporting | Yes |
| `details (metadata)` | IGNORE | None | Not required for subscription item reporting | Yes |
| `user (metadata)` | IGNORE | None | Not required for subscription item reporting | Yes |

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
- Cognito considerations/goals and legacy Emergency Contact/Emergency contact Phone headings remain IGNORE. After explicit approval in migration 010, only native EmergencyContact and Phone2 are OPTIONAL emergency data; no identity scoring or value logging. They remain absent from ordinary reporting projections.
- Cognito Name is stored as source_name, with unresolved meaning. Email/phone/address ownership is unresolved. No source heading or value alone creates parent/student/person relationships.
- No fixed waiver retention period is established. The temporary-upload cleanup timeout is operational and does not define retention of normalized records.


## Actual native Cognito export contract — 2026-10-15

The original tables above describe the shelled legacy files. The 23-column BloomsburgMartialArtsAcademy2 export now uses the explicit internal cognito_waivers_bma2_v1 mapping. After migration 010, eighteen approved original keys are retained separately from derived canonical Name/Address values; five medical/free-form/workflow fields remain ignored. Legacy mapping hashes and JSON keys remain unchanged. The complete source-column/action/destination/purpose table is in [cognito-import-compatibility.md](cognito-import-compatibility.md). Unknown columns still default to IGNORE, and only approved cells can be transcoded or serialized.


## Unified Payments fee evidence clarification (2026-10-31)

The approved field map is unchanged. `Fee` and `Taxes On Fee` retain their exact original approved strings in staging. Their currency and tax treatment are not established by the presence of `Currency` and `Converted Currency`; they do not directly populate normalized financial fees. `FEE_CURRENCY_UNRESOLVED` identifies this specific uncertainty. A separately verified balance transaction may supply integer total fee/currency and, with a complete breakdown, a tax component, with protected audit provenance. See [repair evidence contract](stripe-payment24-import-repair.md).

Blank-ID canceled attempts retain only approved staging fields and a fixed skip diagnostic. Their amounts do not create normalized transactions. No ignored/unknown field, payment method identifier or metadata value is newly allowed. Inspection of `unified_payments(24).csv` found 42 headers: the 23 standard transaction fields plus 19 ignored metadata headers. No source cell values are reproduced here.


## Native emergency field approval — 2026-11-01

| Native source field | Action | Normalized destination | Purpose | Sensitive | Identity scoring | Log value |
|---|---|---|---|---|---|---|
| EmergencyContact | OPTIONAL | cognito_waivers.emergency_contact_name VARCHAR(255) NULL | Emergency contact name only | Yes | No | No |
| Phone2 | OPTIONAL | cognito_waivers.emergency_contact_phone VARCHAR(100) NULL | Emergency phone, formatting/extensions preserved | Yes | No | No |

New raw snapshots retain the exact approved UTF-8 source strings after lossless Windows-1252 conversion; normalized contacts trim outer whitespace and map blanks to NULL. Overlength or control-character contact text is rejected, never truncated. Neither field enters canonical student/signer identity inputs, person/member creation, relationships, demographic charts, search or match scoring. No other native/legacy key is newly approved. Historical raw snapshots remain unchanged; see [recovery and compatibility rules](cognito-emergency-contacts.md).


## Subscription snapshot evidence

`Start (UTC)` and `Start Date (UTC)` are separate approved clocks, stored in csv_start_at and stripe_subscription_started_at respectively. They differ on 21 supplied rows; neither becomes member_since. Trial dates, cancellation free text, fee/tax settings and metadata remain ignored. Raw JSON keys are enforced by migration 011 as well as ApprovedRecord. No customer/transaction/Cognito whitelist changed. See [subscription-csv.md](subscription-csv.md) for source limitations and reviewed-update policy.

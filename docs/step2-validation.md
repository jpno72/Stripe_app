# Step 2 lightweight validation

Validated on 2026-09-30 using the existing disposable MariaDB database `bma_schema_test_installer_7bdd63ff`. This review reused the completed four-file import and duplicate-file results; it did not rerun the broad Step 1 or Step 2 suites. No production data was imported.

| Check | Result | Evidence |
|---|---|---|
| Normalized records and stub | PASS | 1 imported Stripe customer, 1 Stripe customer stub, 1 subscription, 2 transactions, 1 Cognito waiver. |
| Customerless transaction | PASS | The charge whose source Customer ID is blank has `customer_id = NULL`. |
| Stub minimization | PASS | Stub has only its Stripe customer ID and stub marker; person, name, email, address, dates, and summary fields are NULL. |
| Subscription association | PASS | The joined customer's Stripe ID equals the approved source `Customer ID`; no email/name join was used. |
| Reordered reimport | PASS | Reversed customer columns in a new test stream; batch 9 read 47 records, skipped all 47, and created no normalized duplicate. It was a new file hash, not a whole-file duplicate shortcut. |
| Unknown value exclusion | PASS | Added unknown column `Unexpected Review Column` with `DO_NOT_STORE_12345`. Its value was absent from all 136 database text columns scanned, including raw JSON, normalized tables, import errors, and audit data; it was absent from the private importer log. The unknown header name alone appears in batch metadata. |
| Ignored Cognito fields | PASS | `Emergency Contact`, `Emergency contact Phone`, `considerations`, and `goals` are absent from staged JSON keys and normalized waiver columns. |
| Stripe IDs and foreign keys | PASS | No duplicate external IDs across customer, subscription, charge, price, product, or item identifiers; no orphan records across 30 foreign keys. |
| Batch and row states | PASS | No pending/running/failed batches, count imbalances, or unexpected raw processing statuses. |
| Unresolved source facts | PASS | Customer summary and subscription amounts remain without guessed currency; transaction fees remain without guessed fee currency. The Cognito submission time and source timezone remain NULL. |
| Temporary uploads | PASS | No generated `.upload` files remained after the test. |

The reordered variant was generated in memory from the supplied customer export and processed through the same private temporary-upload lifecycle as CLI imports. Its only new cell value was the unknown-column sentinel above. No schema change or Step 3 identity matching was performed.

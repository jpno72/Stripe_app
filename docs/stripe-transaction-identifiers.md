# Stripe transaction CSV identifiers

## Root cause and correction

The transaction importer previously called `Normalizers::stripeId($id, 'ch_', true)`, rejecting otherwise valid Unified Payments records beginning `py_` as INVALID_IDENTIFIER.

Stripe's official support page identifies charges using either prefix: [Getting started through a third-party platform](https://support.stripe.com/questions/getting-started-with-stripe-through-a-third-party-platform?locale=en-GB). This supports retaining both as exported payment/charge identifiers. A prefix alone does not prove a payment method, customer, original platform charge, or cross-account relationship. No replacement identifier or account association is inferred.

`Normalizers::stripeTransactionId()` accepts only `ch_` or `py_` followed by the existing ASCII identifier character set (letters, digits, underscore), capped at 255 characters. Missing, malformed, NUL-containing and unsupported identifiers still raise INVALID_IDENTIFIER. Complete valid identifiers retain their original prefix. General customer/subscription/invoice ID validation is unchanged.

## Storage and downstream compatibility

The existing `stripe_transactions.stripe_charge_id` is an ASCII-binary VARCHAR(255), with a unique index on the complete string. Its legacy name now explicitly includes exported ch_/py_ payment/charge identifiers, not a promise that every value begins ch_. Distinct prefixes with the same suffix remain distinct keys. Existing prepared lookups, row/file duplicate detection and updates use the full ID.

Customer and invoice references still use cus_/in_ validation and actual Stripe IDs. Optional missing customers remain unlinked. Reporting views determine paid eligibility from status and capture, not identifier prefix; amount/refund/fee completeness and separate currencies are unchanged. Revenue search and member transaction history were tested with py_. No schema constraint or downstream production prefix filter requires modification. No migration or historical ID rewrite is needed.

Payment currency, converted currency and fee currency stay separate. USD payments and refunds are normalized to integer minor units. CSV fee/tax source strings remain approved raw values, while normalized fee currency/amount remain NULL until reliable fee currency is available. CURRENCY_UNRESOLVED warnings do not reject an otherwise valid payment. Net remains unresolved where costs are unresolved. Financial reporting formulas were not changed.

## Validation — 2026-10-10

**80 focused checks passed** with `php tests/stripe-transaction-identifiers.php` using an existing guarded disposable database and unique temporary fixtures. PHP lint passed for all three affected PHP files; git diff --check passed.

Coverage includes valid ch_/py_, maximum length, missing/malformed/unsupported IDs, strict other-resource validators, duplicate rows for both prefixes, safe coexistence, renamed/reordered reimports, updates, invalid overwrite prevention, USD amounts/refunds, converted currency, fee warnings, optional customer, exact customer/invoice references, dry-run versus actual imports, production account/environment attestation, conflict rejection, paid/failed eligibility, Revenue/member lookup and ignored-value/log exclusion. Test-created transactions/batches/staging/errors were removed, source account identity was restored and temporary diagnostics deleted. No production database or Stripe API writes occurred.

Both actual supplied exports were independently inspected and dry-run in the disposable database:

| Export | Rows | ch_ | py_ | Dry-run skipped | Errors | Currency warnings |
|---|---:|---:|---:|---:|---:|---:|
| unified_payments(22).csv | 39 | 21 | 18 | 39 | 0 | 39 |
| unified_payments(23).csv | 36 | 24 | 12 | 36 | 0 | 36 |

They are different exports. Both have explicit usd payment currencies; (22) contains 37 Paid/2 Failed rows, (23) contains 31 Paid/5 Failed rows. Validation accepts failed records for ledger storage; failed payments do not qualify as paid revenue. Dry-run records count as skipped, with no normalized payment inserts.

Field mappings, authentication, authorization, CSRF, source identity verification, private uploads, error logging, row transactions, financial reporting, public bootstrap, .htaccess and all SQL/migration files were unchanged, verified against the task's initial file hashes.

## Files changed and minimal deployment

Production PHP:

1. `app/Import/Normalizers.php`
2. `app/Import/StripeTransactionImporter.php`

Supporting files:

3. `tests/stripe-transaction-identifiers.php`
4. `docs/stripe-transaction-identifiers.md`

Manually upload only the two production PHP files at their relative locations beneath `/home/bbmmacom/repositories/Stripe_app/`. Upload them together; the importer needs the new helper. No public files, bootstrap, .htaccess, private credentials, database migration, Composer changes, git pull or server reset are required. Production files were not deployed automatically.

After upload, dry-run the intended CSV again. For actual imports, provide verified acct_ account identity and explicit Live/Test attestation as before. Previously rejected rows are not automatically imported by deploying code. An operator must deliberately reimport; existing full-ID checks preserve previously successful transactions. Do not mix different accounts/environments.

## Remaining limitations

Fee currency remains unresolved from these exports, so fee/net warnings and unavailable metrics are expected. Supporting ch_/py_ does not accept arbitrary Stripe object types such as pi_, tr_ or payout IDs. Prefix alone does not establish semantic equivalence between two different IDs, so no cross-prefix deduplication/merge is attempted. Any future API synchronization must use the exact ID, verified account/environment and the supported API resource semantics; that work was not implemented or tested here.

## Reproducing focused checks

Unit checks require only PHP. Optional database checks require runtime BMA_PAYMENT_TEST_DATABASE with the bma_schema_test_ prefix, DB_HOST/DB_PORT/DB_USERNAME/DB_PASSWORD, existing reporting views and an accepted payer/member fixture. Optional BMA_PAYMENT_EXPORT_22 and BMA_PAYMENT_EXPORT_23 paths enable the actual-export dry runs. Tests intentionally modify only unique disposable fixture records and restore their account context; do not point them at production.

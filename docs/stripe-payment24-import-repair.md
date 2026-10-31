# Unified Payments import correction and fee-only repair

Validated locally on 2026-10-31. Production was not accessed, deployed to, or repaired. No push was performed. Live server modifications remain authoritative and must be compared before deployment.

## Findings from the actual uploaded CSV

The actual `unified_payments(24).csv` was streamed through the importer in a disposable local MariaDB database. No original CSV or personal/payment metadata is committed.

| Measure | Actual fresh-import result |
|---|---:|
| Headers | 42 (23 standard transaction columns, 19 ignored metadata columns) |
| Source rows | 50 |
| Valid external IDs | 47 (37 `ch_`, 10 `py_`) |
| Inserted transactions | 47 |
| Intentionally skipped canceled attempts | 3 |
| Errors | 0 |
| Paid / failed | 46 / 1 |
| Gross paid, USD minor units | 383950 ($3,839.50) |
| Recorded paid refunds, USD minor units | 0 |
| Normalized fees on fresh import | NULL, unverified |
| Fee-specific warnings | 47 |

Data rows 10, 27 and 43 have blank IDs and canceled status, without capture or settlement evidence. The strict transaction identifier validator correctly rejects blank ledger IDs; the defect was sending these non-ledger attempts into it without classification. The importer now classifies this narrow category before looking up/creating customers or transactions. It accepts canceled/cancelled spelling, requires a valid original currency/nonnegative amount, validates approved references/dates, and refuses captured evidence, nonzero refunds, or populated fee/converted/refund-date/transfer fields. Paid/succeeded/contradictory missing-ID rows still error. No ID substitution or email ownership matching occurs.

Approved staging retains the source values and fixed `CANCELED_WITHOUT_TRANSACTION_ID` diagnostic. It does not create ledger rows, count canceled amounts as revenue, or erase historical import errors. Identical-file reimports skip existing transactions and repeat the safe canceled diagnostic. Repeated canceled rows use the existing duplicate-source diagnostic. A renamed identical CSV created zero duplicate transactions.

The old 47 `CURRENCY_UNRESOLVED` warnings did not mean original payment currency was missing. All 50 original currencies are `usd`; 47 converted currencies are `usd`. Fees/taxes were deliberately not normalized because their currency/treatment was unproven. The new diagnostic is `FEE_CURRENCY_UNRESOLVED`. Missing converted currency with converted amounts still uses `CURRENCY_UNRESOLVED`. Approved source values are unchanged.

## Fee evidence and financial limits

The source paid-fee sum is 12668 minor units ($126.68 if USD), with no nonzero fee-tax strings. Neither coincident currency columns nor this total establishes the fee column's settlement currency or whether tax is included. No authoritative exact Unified Payments `Fee`/`Taxes On Fee` column contract was established. Consequently those CSV strings remain staging evidence and do not become factual normalized fees.

Stripe documents integer balance-transaction `amount`, `fee`, `net`, `currency`, and `source`; `net = amount - fee`. See [balance transaction object](https://docs.stripe.com/api/balance_transactions/object?lang=php). [Payment expansion guidance](https://docs.stripe.com/expand/use-cases) describes retrieving payment fees through the balance transaction and warns that IC+ pricing may require the Payment Fees report instead. [Exporting payment data](https://support.stripe.com/questions/exporting-payment-data?locale=en-GB) recommends balance reporting for reconciliation; [exporting fees](https://support.stripe.com/questions/exporting-fees-paid?locale=en-GB) describes fee reporting. [Currency guidance](https://docs.stripe.com/currencies) distinguishes presentment and settlement currencies. These sources support using verified balance evidence; they do not prove the exact CSV fee columns' semantics.

For IC+, unavailable/incomplete API fee information must not be relabeled a verified zero. The repair blocks zero balance fees against positive CSV cost evidence. A separate reviewed fee-report adapter would be needed when balance evidence is insufficient; this change does not implement one. Connected-account `py_` sources must match the actual relevant account and payment, never a guessed originating `ch_`.

Verified balance total fee may populate the existing normalized cache. A complete supported fee breakdown must sum exactly to total fee; its tax is an included component, never subtracted twice. Missing or empty nonzero breakdown leaves tax NULL. Foreign-currency fees remain excluded from original-currency fee/net reporting. Original and converted amounts remain separate. The failed transaction is retained but excluded from paid totals. No views, revenue calculations, timezone behavior or schema were changed.

## Repair design and safeguards

A separate batch-scoped command was chosen over automatic re-normalization: it does not re-run financial upserts, change the raw hash, revisit unrelated imports or silently trust inferred currency. It is default dry-run, works with unchanged approved hashes, and reports counters/intended columns before application. It requires:

- A completed non-dry-run Stripe transaction batch, its exact SHA-256 and an active administrator ID.
- Batch account/environment matching the established database identity; missing identity is blocked, not guessed.
- Historical approved content matching current raw content and normalized amount, currency, refund, status, capture, UTC date, customer, invoice and converted values. Drift blocks repair.
- Complete existing balance fields or an operator-verified balance evidence document. All provided evidence must have matching account/environment, exact source ID, unique balance ID, supported type, integer amounts and valid reconciliation. Foreign-currency balance amount must equal an explicitly recorded converted amount.

Only previously NULL `fee_minor`, `fee_currency` and supported `taxes_on_fee_minor` are filled. Any pre-existing fee correction is preserved; conflicting evidence blocks repair. Balance fields, amounts, dates, statuses, customer/invoice associations and raw pointers are never overwritten. Existing balance authority wins. Repeat application makes no new changes/audit rows. The import advisory lock prevents concurrent imports/repairs; apply locks the selected records and commits changes plus audit atomically. Do not run alongside migrations or other manual database maintenance.

Audit records contain actor/time, internal transaction ID, reason/version, source batch/hash, balance ID, evidence origin/hash and before/after fee fields. These financial audit values are protected change history. Application diagnostic logs contain codes/batch/row only, with no source rows, personal data, payment amounts or credentials. Existing history is preserved. Failed/dry-run repairs write no repair audit.

Evidence documents are bounded at 10 MiB / 5,000 objects; source batches at 5,000 rows / 16 MiB approved JSON. This intentionally targets the 50-row batch, not a general historical backfill. Operator-owned evidence is read privately and not automatically archived or deleted. The original importer retains its existing streaming, temporary-upload deletion and field filtering behavior.

## Evidence document contract

This example is fictional, not evidence for the actual CSV:

```json
{
  "stripe_account_id": "acct_EXAMPLE",
  "stripe_livemode": 1,
  "balance_transactions": [
    {
      "id": "txn_EXAMPLE",
      "object": "balance_transaction",
      "type": "charge",
      "source": "ch_EXAMPLE",
      "amount": 1234,
      "currency": "usd",
      "fee": 50,
      "net": 1184,
      "fee_details": [{"type": "stripe_fee", "amount": 50, "currency": "usd"}]
    }
  ]
}
```

Use actual independently retrieved Stripe balance objects for the established account/mode. Supported payment types are `charge` and `payment`. Do not construct evidence by assigning a currency to CSV fees or copying the synthetic test fixtures. The CLI validates structure/reconciliation/account consistency, not cryptographic origin; the operator must verify its Stripe origin and fee completeness. `--confirm-stripe-evidence` explicitly acknowledges that responsibility when applying an external document. No new API calls or Step 12 synchronization are implemented. Complete previously verified stored balance fields can be used without a document.

## Focused validation

| Check | Result |
|---|---|
| Actual CSV fresh import, paid count/gross/refunds, failed exclusion | PASS |
| Identical/renamed repeat; canceled diagnostic traceability | PASS |
| Missing-ID paid/captured/contradictory cases remain errors | PASS |
| `ch_` / `py_` compatibility and preserved customer/invoice references | PASS |
| Unchanged-hash dry-run/apply, audit preservation and repeat no-op | PASS |
| Existing manual fees / higher-authority balance values preserved | PASS |
| Tax component, zero/missing fee, precision, overflow and multi-currency | PASS |
| Source drift, wrong account/mode/hash/inactive actor blocked | PASS |
| CLI dry-run default and explicit external-proof confirmation | PASS |
| Approved staging only; ignored sentinel absent; code-only logs | PASS |
| No people/members automatically created by these corrections | PASS |

`tests/payment24-import-repair.php`: 67 checks passed against disposable MariaDB, using the actual uploaded file plus explicitly synthetic cases. `tests/stripe-transaction-identifiers.php`: 78 focused checks passed, including existing compatibility/idempotency/privacy cases. The newly created disposable database and fixtures were removed; existing test data was reused for the legacy checks. No production or full regression suite ran. All eight changed/new PHP files passed lint; `git diff --check` and reverse patch validation passed. Baseline hashes confirm only the three listed existing runtime files changed. The targeted credential-pattern scan found no credential-shaped keys in changed/new files.

**Synthetic only:** 46 fabricated, explicitly USD balance proofs derived from fixture fee numbers exercised backfill of 12668 total fees and 371282 net, matching the RevenueRepository result. That validates repair/reporting arithmetic, not the real fees' currency. The actual source's real fee/net totals remain unresolved until independently verified proof is supplied. The one failed record may legitimately have no fee evidence and remain unresolved without affecting paid totals.

## Exact change inventory and migration requirement

Existing runtime files:

1. `app/Import/StripeTransactionImporter.php` — canceled classifier, precise fee warning, preserve existing fees.
2. `app/Import/ImportService.php` — retain blank-ID classification/diagnostics on identical-file retries.
3. `src/Retention.php` — two fixed diagnostic codes/messages; ApprovedRecord/allowlists unchanged.

New runtime files:

4. `app/Import/BalanceFeeEvidence.php` — strict evidence validation.
5. `app/Import/TransactionFeeRepair.php` — transactional, batch-scoped fee-only repair.
6. `bin/repair-transaction-fees.php` — private CLI, dry-run default.

Tests: new `tests/payment24-import-repair.php`; updated `tests/stripe-transaction-identifiers.php` (fee-specific diagnostic expectation).

Documentation: `docs/imports.md`, `docs/import-field-mapping.md`, `docs/reporting-definitions.md`, this document, and `docs/stripe-payment24-deployment.patch`.

**No database migration is required.** Existing fee, balance, staging, source identity and audit columns suffice. Migrations 001–009, schema snapshot, SQL views, authentication/authorization, public files, configuration/allowlists, other importers and business-time handling are unchanged. No dependency, PHP handler, Ampache or PHP 8.5 changes are included.

## Deployment: manual review required

Local baseline and GitHub remote HEAD were both `53dd68ce558007dcca41a4526fed5f3d53e0dfef` when checked. The [runtime-only patch](stripe-payment24-deployment.patch) is against that baseline, **not a comparison to production**. Server-only differences are unknown because no live access was available.

1. On the host, privately compare the six runtime paths above against the baseline and patch. Identify manual server changes first. Do not `git pull`, reset, overwrite the whole project or upload local private configuration. Back up affected PHP files outside the public root. Before any data repair, take a private MariaDB backup and confirm no imports/migrations are running.
2. Confirm BMA's actual PHP 8.2 CLI binary. If the following usual cPanel path exists and reports PHP 8.2, use it; otherwise substitute the host's verified PHP 8.2 binary. Do not change any web PHP handler.

```sh
BMA_PHP82=/opt/cpanel/ea-php82/root/usr/bin/php
"$BMA_PHP82" -v
cd /home/bbmmacom/repositories/Stripe_app
# Read-only patch check; a conflict requires manual merging, never force application.
git apply --check /home/bbmmacom/bma-private/stripe-payment24-deployment.patch
```

3. Review the patch, then manually merge just the three existing runtime changes and add the three new private runtime files. If and only if the live files match the reviewed baseline and the check passes, the reviewed patch can be applied with `git apply /home/bbmmacom/bma-private/stripe-payment24-deployment.patch`. Keep server-only changes. No file is copied to the public document root.

```sh
for BMA_FILE in app/Import/StripeTransactionImporter.php app/Import/ImportService.php src/Retention.php app/Import/BalanceFeeEvidence.php app/Import/TransactionFeeRepair.php bin/repair-transaction-fees.php; do
  "$BMA_PHP82" -l "$BMA_FILE" || exit 1
done
# Read-only status check. This correction supplies no migration to apply.
"$BMA_PHP82" bin/install.php --check --config=/home/bbmmacom/bma-private/config.php
```

Do not run schema.sql or apply pending unrelated migrations as part of this correction. If the live schema lacks existing prerequisites, stop for review. Review protected Imports/transaction reports after the merge; verify the application retains its existing login/authorization and timezone behavior. Run a validation/dry-run of the file before intentionally reimporting. Existing 47 transactions should not duplicate; new successful import history can show 50 skipped and zero errors, with three canceled diagnostics. Original historical error records remain unchanged.

4. Obtain the original batch ID/hash in an authorized database session without exporting source contents:

```sql
SELECT id, source_type, file_hash, status, row_count,
       inserted_count, updated_count, skipped_count, error_count
FROM import_batches
WHERE source_type = 'stripe_transactions'
ORDER BY id DESC LIMIT 10;
```

5. Supply verified private evidence only if available. Replace angle-bracket placeholders with the real original batch, stored lowercase SHA-256 and your active administrator ID. Never use fixture account IDs, users or fee numbers.

```sh
"$BMA_PHP82" bin/repair-transaction-fees.php \
  --config=/home/bbmmacom/bma-private/config.php \
  --batch=<ORIGINAL_BATCH_ID> --file-hash=<EXACT_64_LOWERCASE_SHA256> \
  --actor-user=<ACTIVE_ADMIN_ID> --dry-run \
  --balance-evidence=/home/bbmmacom/bma-private/verified-balances.json
```

Without a document, omit its option; only already complete verified balance fields can then qualify. Missing evidence results in blocked records and no invented fee. Review selected/eligible/blocked counts and currencies. Unexpected source drift/conflicts require investigation, not force options. The 47 original transactions can include one failed record without sufficient balance proof.

6. Only after reviewing the dry-run and evidence, an authorized operator may rerun the same command replacing `--dry-run` with `--apply --confirm-stripe-evidence`. Confirmation is required only for external proof. Then rerun dry-run: no repeat changes should be eligible. Gross paid must remain 383950 minor USD / 46 payments for this dataset; canceled attempts add nothing. Compare verified fees/net only within matching currency and confirm audit actor/before/after history. Do not require 12668 as a real fee target until independent evidence verifies it. Other production transactions mean whole-database totals can differ.

## Rollback

Before repair, restore only backed-up affected PHP files/remove the three new private files to revert code; do not reset the repository or touch unrelated deployments. Pause imports if restoring the old importer: old changed-row upserts can clear cached fees.

After an applied repair, code rollback does not undo data. Review `transaction_fee_repaired` audit records and subsequent changes first. A separately reviewed compensating transaction can restore only those audited cache values that still equal the repair's new values to their audited previous NULLs, with a new rollback audit entry. Preserve balance fields, associations and all original history; do not delete audit rows or blindly restore the entire database over later activity. No automatic destructive rollback is supplied.

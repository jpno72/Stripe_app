<?php
declare(strict_types=1);

final class StripeTransactionImporter extends AbstractImporter
{
    public function import(PDO $db, ApprovedRecord $record, int $rawId, bool $dryRun, array $options): array
    {
        $v = $record->values();
        if (self::blankId($v['id']) && self::canceledWithoutTransaction($v)) {
            return ['skipped', ['CANCELED_WITHOUT_TRANSACTION_ID']];
        }
        $external = Normalizers::stripeTransactionId($v['id']);
        $existing = $this->existing($db, 'stripe_transactions', 'stripe_charge_id', $external);
        if ($existing && $this->sameRaw($db, $existing['latest_raw_id'] === null ? null : (int)$existing['latest_raw_id'], 'raw_stripe_transactions', $record->contentHash())) { return ['skipped', []]; }
        $customerExternal = Normalizers::stripeId($v['Customer ID'], 'cus_');
        $customerId = $customerExternal === null ? null : $this->customer($db, $customerExternal, $dryRun);
        if ($existing && $customerId !== null && $existing['customer_id'] !== null && (int)$existing['customer_id'] !== $customerId) { throw new RuntimeException('STRIPE_REFERENCE_CONFLICT'); }
        $invoice = Normalizers::stripeId($v['Invoice ID'], 'in_');
        if ($existing && $invoice !== null && $existing['stripe_invoice_id'] !== null && $existing['stripe_invoice_id'] !== $invoice) { throw new RuntimeException('STRIPE_REFERENCE_CONFLICT'); }
        $currency = Normalizers::currency($v['Currency']);
        if ($currency === null) { throw new RuntimeException('INVALID_CURRENCY'); }
        $amount = Normalizers::amount($v['Amount'], $currency);
        if ($amount === null) { throw new RuntimeException('INVALID_AMOUNT'); }
        $convertedCurrency = Normalizers::currency($v['Converted Currency']);
        $convertedPresent = Normalizers::text($v['Converted Amount']) !== null || Normalizers::text($v['Converted Amount Refunded']) !== null;
        Normalizers::validateDecimal($v['Fee']);
        Normalizers::validateDecimal($v['Taxes On Fee']);
        if ($convertedCurrency === null) { Normalizers::validateDecimal($v['Converted Amount']); Normalizers::validateDecimal($v['Converted Amount Refunded']); }
        $status = Normalizers::text($v['Status']);
        if ($status === null) { throw new RuntimeException('INVALID_STATUS'); }
        $values = [
            'stripe_charge_id'=>$external, 'stripe_invoice_id'=>$invoice, 'customer_id'=>$customerId ?? ($existing['customer_id'] ?? null),
            'stripe_created_at'=>Normalizers::utc($v['Created date (UTC)']), 'amount_minor'=>$amount,
            'amount_refunded_minor'=>Normalizers::amount($v['Amount Refunded'], $currency), 'currency'=>$currency,
            'captured'=>Normalizers::boolean($v['Captured']),
            'converted_amount_minor'=>$convertedCurrency === null ? null : Normalizers::amount($v['Converted Amount'], $convertedCurrency),
            'converted_amount_refunded_minor'=>$convertedCurrency === null ? null : Normalizers::amount($v['Converted Amount Refunded'], $convertedCurrency),
            'converted_currency'=>$convertedCurrency,
            // CSV fee currency is unproven. Never erase a previously verified or corrected fee.
            'fee_minor'=>$existing['fee_minor'] ?? null, 'fee_currency'=>$existing['fee_currency'] ?? null,
            'taxes_on_fee_minor'=>$existing['taxes_on_fee_minor'] ?? null,
            'decline_reason'=>Normalizers::text($v['Decline Reason']), 'description'=>Normalizers::text($v['Description']),
            'refunded_at'=>Normalizers::utc($v['Refunded date (UTC)']), 'statement_descriptor'=>Normalizers::text($v['Statement Descriptor']),
            'status'=>$status, 'seller_message'=>Normalizers::text($v['Seller Message']),
            'customer_email_snapshot'=>Normalizers::text($v['Customer Email']), 'transfer_reference'=>Normalizers::text($v['Transfer']),
            'latest_raw_id'=>$rawId,
        ];
        if (!$dryRun) { $this->write($db, 'stripe_transactions', $values, $existing === null ? null : (int)$existing['id']); }
        $warnings = [];
        if (Normalizers::text($v['Fee']) !== null || Normalizers::text($v['Taxes On Fee']) !== null) { $warnings[] = 'FEE_CURRENCY_UNRESOLVED'; }
        if ($convertedPresent && $convertedCurrency === null) { $warnings[] = 'CURRENCY_UNRESOLVED'; }
        return [$this->status($existing, false, $dryRun), $warnings];
    }

    public static function blankId(?string $value): bool
    {
        return $value === null || preg_match('/\A[ \t\r\n\v\f]*\z/D', $value) === 1;
    }

    /** Only the verified export category: canceled, unidentified and no success/settlement evidence. */
    private static function canceledWithoutTransaction(array $v): bool
    {
        if (!in_array(strtolower(Normalizers::text($v['Status']) ?? ''), ['canceled','cancelled'], true)) return false;
        if (Normalizers::boolean($v['Captured']) === 1) return false;
        foreach (['Fee','Taxes On Fee','Converted Currency','Converted Amount','Converted Amount Refunded','Refunded date (UTC)','Transfer'] as $key) {
            if (Normalizers::text($v[$key]) !== null) return false;
        }
        $currency = Normalizers::currency($v['Currency']);
        if ($currency === null) throw new RuntimeException('INVALID_CURRENCY');
        $amount = Normalizers::amount($v['Amount'], $currency);
        if ($amount === null || $amount < 0) throw new RuntimeException('INVALID_AMOUNT');
        $refund = Normalizers::amount($v['Amount Refunded'], $currency);
        if ($refund !== null && $refund !== 0) return false;
        Normalizers::utc($v['Created date (UTC)']);
        Normalizers::stripeId($v['Customer ID'], 'cus_');
        Normalizers::stripeId($v['Invoice ID'], 'in_');
        return true;
    }

}

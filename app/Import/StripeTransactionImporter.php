<?php
declare(strict_types=1);

final class StripeTransactionImporter extends AbstractImporter
{
    public function import(PDO $db, ApprovedRecord $record, int $rawId, bool $dryRun, array $options): array
    {
        $v = $record->values();
        $external = Normalizers::stripeId($v['id'], 'ch_', true);
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
            'converted_currency'=>$convertedCurrency, 'fee_minor'=>null, 'fee_currency'=>null, 'taxes_on_fee_minor'=>null,
            'decline_reason'=>Normalizers::text($v['Decline Reason']), 'description'=>Normalizers::text($v['Description']),
            'refunded_at'=>Normalizers::utc($v['Refunded date (UTC)']), 'statement_descriptor'=>Normalizers::text($v['Statement Descriptor']),
            'status'=>$status, 'seller_message'=>Normalizers::text($v['Seller Message']),
            'customer_email_snapshot'=>Normalizers::text($v['Customer Email']), 'transfer_reference'=>Normalizers::text($v['Transfer']),
            'latest_raw_id'=>$rawId,
        ];
        if (!$dryRun) { $this->write($db, 'stripe_transactions', $values, $existing === null ? null : (int)$existing['id']); }
        $warnings = [];
        if (Normalizers::text($v['Fee']) !== null || Normalizers::text($v['Taxes On Fee']) !== null || ($convertedPresent && $convertedCurrency === null)) { $warnings[] = 'CURRENCY_UNRESOLVED'; }
        return [$this->status($existing, false, $dryRun), $warnings];
    }
}

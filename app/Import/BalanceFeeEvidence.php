<?php
declare(strict_types=1);

/** Verified balance evidence only. Converted Currency by itself is never fee evidence. */
final class BalanceFeeEvidence
{
    public static function manifest(array $document, string $account, int $live): array
    {
        if (($document['stripe_account_id'] ?? null) !== $account
            || !in_array($document['stripe_livemode'] ?? null, [true,false,0,1], true)
            || (int)$document['stripe_livemode'] !== $live
            || !is_array($document['balance_transactions'] ?? null)
            || !array_is_list($document['balance_transactions']) || count($document['balance_transactions']) > 5000) {
            throw new RuntimeException('INVALID_BALANCE_EVIDENCE');
        }
        $out = []; $ids = [];
        foreach ($document['balance_transactions'] as $item) {
            if (!is_array($item)) throw new RuntimeException('INVALID_BALANCE_EVIDENCE');
            $proof = self::object($item);
            if (isset($out[$proof['source']]) || isset($ids[$proof['id']])) throw new RuntimeException('INVALID_BALANCE_EVIDENCE');
            $out[$proof['source']] = $proof; $ids[$proof['id']] = true;
        }
        return $out;
    }

    public static function object(array $item, bool $requirePaymentType = true): array
    {
        $source = $item['source'] ?? null; $id = $item['id'] ?? null;
        if (($requirePaymentType && !in_array($item['type'] ?? null, ['charge','payment'], true))
            || ($item['object'] ?? null) !== 'balance_transaction' || !is_string($source) || !is_string($id)
            || Normalizers::stripeTransactionId($source) !== $source || Normalizers::stripeId($id, 'txn_', true) !== $id
            || !is_string($item['currency'] ?? null) || !preg_match('/\A[a-z]{3}\z/D', $item['currency'])
            || !is_int($item['amount'] ?? null) || !is_int($item['fee'] ?? null) || !is_int($item['net'] ?? null)
            || $item['amount'] < 0 || $item['fee'] < 0 || $item['amount'] - $item['fee'] !== $item['net']) {
            throw new RuntimeException('INVALID_BALANCE_EVIDENCE');
        }
        $tax = null;
        if (array_key_exists('fee_details', $item)) {
            if (!is_array($item['fee_details']) || !array_is_list($item['fee_details']) || count($item['fee_details']) > 100) throw new RuntimeException('INVALID_BALANCE_EVIDENCE');
            // Some API versions return an empty breakdown despite a nonzero total. Do not invent zero tax.
            if ($item['fee_details'] || $item['fee'] === 0) {
                $sum = 0; $tax = 0;
                foreach ($item['fee_details'] as $detail) {
                    if (!is_array($detail) || !is_int($detail['amount'] ?? null) || $detail['amount'] < 0
                        || ($detail['currency'] ?? null) !== $item['currency']
                        || !in_array($detail['type'] ?? null, ['stripe_fee','tax','application_fee','payment_method_passthrough_fee'], true)
                        || $sum > PHP_INT_MAX - $detail['amount']) throw new RuntimeException('INVALID_BALANCE_EVIDENCE');
                    $sum += $detail['amount']; if ($detail['type'] === 'tax') $tax += $detail['amount'];
                }
                if ($sum !== $item['fee']) throw new RuntimeException('INVALID_BALANCE_EVIDENCE');
            }
        }
        // No descriptions, personal metadata or complete API payloads cross into the audit/persistence layer.
        return ['id'=>$id,'source'=>$source,'currency'=>$item['currency'],'amount'=>$item['amount'],'fee'=>$item['fee'],'net'=>$item['net'],'tax'=>$tax];
    }

    public static function stored(array $row): ?array
    {
        $keys = ['stripe_balance_transaction_id','balance_amount_minor','balance_fee_minor','balance_net_minor','balance_currency'];
        $present = 0; foreach ($keys as $key) $present += $row[$key] !== null ? 1 : 0;
        if ($present === 0) return null;
        if ($present !== count($keys)) throw new RuntimeException('INVALID_BALANCE_EVIDENCE');
        $integers = [];
        foreach (['amount','fee','net'] as $key) {
            $value = filter_var($row['balance_'.$key.'_minor'], FILTER_VALIDATE_INT);
            if ($value === false) throw new RuntimeException('INVALID_BALANCE_EVIDENCE');
            $integers[$key] = $value;
        }
        return self::object(['object'=>'balance_transaction','id'=>$row['stripe_balance_transaction_id'],
            'source'=>$row['stripe_charge_id'],'currency'=>$row['balance_currency']] + $integers, false);
    }
}

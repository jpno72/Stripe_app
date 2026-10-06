<?php
declare(strict_types=1);

final class StripeCustomerImporter extends AbstractImporter
{
    public function import(PDO $db, ApprovedRecord $record, int $rawId, bool $dryRun, array $options): array
    {
        $v = $record->values();
        $external = Normalizers::stripeId($v['id'], 'cus_', true);
        $existing = $this->existing($db, 'stripe_customers', 'stripe_customer_id', $external);
        $same = $existing && !$existing['is_stub'] && $this->sameRaw($db, $existing['latest_raw_id'] === null ? null : (int)$existing['latest_raw_id'], 'raw_stripe_customers', $record->contentHash());
        if ($same) { return ['skipped', []]; }
        $count = Normalizers::text($v['Payment Count']);
        if ($count !== null && (!ctype_digit($count) || strlen($count) > 18)) { throw new RuntimeException('INVALID_COUNT'); }
        $hasMoney = false;
        foreach (['Total Spend','Refunded Volume','Dispute Losses'] as $key) { Normalizers::validateDecimal($v[$key]); $hasMoney = $hasMoney || Normalizers::text($v[$key]) !== null; }
        $values = [
            'stripe_customer_id'=>$external, 'name'=>Normalizers::text($v['Name']),
            'email'=>Normalizers::text($v['Email']), 'email_normalized'=>Normalizers::email($v['Email']),
            'address_line1'=>Normalizers::text($v['Address Line1']), 'address_city'=>Normalizers::text($v['Address City']),
            'address_postal_code'=>Normalizers::postal($v['Address Postal Code']), 'address_country'=>Normalizers::text($v['Address Country']),
            'stripe_created_at'=>Normalizers::utc($v['Created (UTC)']), 'payment_count'=>$count,
            'total_spend_minor'=>null, 'refunded_volume_minor'=>null, 'dispute_losses_minor'=>null, 'summary_currency'=>null,
            'is_stub'=>0, 'latest_raw_id'=>$rawId,
        ];
        if (!$dryRun) { $this->write($db, 'stripe_customers', $values, $existing === null ? null : (int)$existing['id']); }
        return [$this->status($existing, false, $dryRun), $hasMoney ? ['CURRENCY_UNRESOLVED'] : []];
    }
}

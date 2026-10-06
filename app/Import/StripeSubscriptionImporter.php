<?php
declare(strict_types=1);

final class StripeSubscriptionImporter extends AbstractImporter
{
    public function import(PDO $db, ApprovedRecord $record, int $rawId, bool $dryRun, array $options): array
    {
        $v = $record->values();
        $external = Normalizers::stripeId($v['id'], 'sub_', true);
        $customerExternal = Normalizers::stripeId($v['Customer ID'], 'cus_', true);
        $priceExternal = Normalizers::stripeId($v['Plan'], 'price_');
        $existing = $this->existing($db, 'stripe_subscriptions', 'stripe_subscription_id', $external);
        if ($existing && $this->sameRaw($db, $existing['latest_raw_id'] === null ? null : (int)$existing['latest_raw_id'], 'raw_stripe_subscriptions', $record->contentHash())) { return ['skipped', []]; }
        $customerId = $this->customer($db, $customerExternal, $dryRun);
        if ($existing && $customerId !== null && (int)$existing['customer_id'] !== $customerId) { throw new RuntimeException('STRIPE_REFERENCE_CONFLICT'); }
        $status = Normalizers::text($v['Status']);
        if ($status === null) { throw new RuntimeException('INVALID_STATUS'); }
        $interval = Normalizers::text($v['Interval']);
        $created = Normalizers::utc($v['Created (UTC)']);
        $canceled = Normalizers::utc($v['Canceled At (UTC)']);
        $amountPresent = Normalizers::text($v['Amount']) !== null;
        Normalizers::validateDecimal($v['Amount']);
        $values = ['stripe_subscription_id'=>$external, 'customer_id'=>$customerId ?? ($existing['customer_id'] ?? null),
            'customer_name_snapshot'=>Normalizers::text($v['Customer Name']), 'customer_email_snapshot'=>Normalizers::text($v['Customer Email']),
            'amount_minor'=>null, 'currency'=>null, 'status'=>$status, 'coupon_reference'=>Normalizers::text($v['Coupon']),
            'stripe_created_at'=>$created, 'canceled_at'=>$canceled, 'latest_raw_id'=>$rawId];
        if (!$dryRun) {
            $this->write($db, 'stripe_subscriptions', $values, $existing === null ? null : (int)$existing['id']);
            $subscriptionId = $existing === null ? (int)$db->lastInsertId() : (int)$existing['id'];
            $priceId = null;
            if ($priceExternal !== null) {
                $q = $db->prepare('SELECT id FROM stripe_prices WHERE stripe_price_id = ?'); $q->execute([$priceExternal]);
                $priceId = $q->fetchColumn();
                if ($priceId === false) { $db->prepare('INSERT INTO stripe_prices (stripe_price_id) VALUES (?)')->execute([$priceExternal]); $priceId = $db->lastInsertId(); }
            }
            $q = $db->prepare('SELECT id, price_id FROM stripe_subscription_items WHERE subscription_id = ? AND source_locator = ?'); $q->execute([$subscriptionId, 'csv-single-item']);
            $item = $q->fetch();
            $this->write($db, 'stripe_subscription_items', ['subscription_id'=>$subscriptionId, 'source_locator'=>'csv-single-item',
                'price_id'=>$priceId, 'product_name_snapshot'=>Normalizers::text($v['Product']), 'quantity'=>null,
                'amount_minor'=>null, 'currency'=>null, 'billing_interval'=>$interval, 'billing_interval_count'=>null,
                'latest_raw_id'=>$rawId], $item ? (int)$item['id'] : null);
        }
        return [$this->status($existing, false, $dryRun), $amountPresent ? ['CURRENCY_UNRESOLVED'] : []];
    }
}

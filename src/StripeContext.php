<?php
declare(strict_types=1);

final class StripeContext
{
    public static function establish(PDO $db, string $account, bool $live): void
    {
        if (!preg_match('/\Aacct_[A-Za-z0-9]+\z/', $account) || $db->inTransaction()) { throw new RuntimeException('INVALID_STRIPE_CONTEXT'); }
        $db->beginTransaction();
        try {
            $current = $db->query('SELECT * FROM stripe_source_identity WHERE id = 1 FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
            if (!$current) { throw new RuntimeException('STRIPE_CONTEXT_MISSING'); }
            if ($current['stripe_account_id'] !== null && ($current['stripe_account_id'] !== $account || (bool)$current['stripe_livemode'] !== $live)) { throw new RuntimeException('STRIPE_CONTEXT_CONFLICT'); }
            if ($current['stripe_account_id'] === null) {
                $q = $db->prepare('UPDATE stripe_source_identity SET stripe_account_id = ?, stripe_livemode = ?, established_at = UTC_TIMESTAMP(6) WHERE id = 1');
                $q->execute([$account, (int)$live]);
            }
            $db->commit();
        } catch (Throwable $e) { $db->rollBack(); throw $e; }
    }

    public static function requireSameId(?string $source, ?string $resolved): void
    {
        if ($source !== null && $source !== '' && $source !== $resolved) { throw new RuntimeException('STRIPE_REFERENCE_CONFLICT'); }
    }

    public static function currency(?string $value): ?string
    {
        if ($value === null || trim($value) === '') { return null; }
        $value = strtolower(trim($value));
        if (!preg_match('/\A[a-z]{3}\z/', $value)) { throw new RuntimeException('INVALID_CURRENCY'); }
        return $value;
    }
}

<?php
declare(strict_types=1);
final class StripeAssociationRepository
{
    public static function attributionReady(PDO $db): bool
    {
        return (bool)$db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stripe_transaction_attributions' AND EXISTS(SELECT 1 FROM schema_migrations WHERE version='012_profile_transaction_attribution')")->fetchColumn();
    }
}

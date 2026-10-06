<?php
declare(strict_types=1);

abstract class AbstractImporter
{
    abstract public function import(PDO $db, ApprovedRecord $record, int $rawId, bool $dryRun, array $options): array;

    protected function existing(PDO $db, string $table, string $key, string $value): ?array
    {
        $allowed = ['stripe_customers'=>['stripe_customer_id'], 'stripe_subscriptions'=>['stripe_subscription_id'], 'stripe_transactions'=>['stripe_charge_id'], 'cognito_waivers'=>['source_locator']];
        if (!in_array($key, $allowed[$table] ?? [], true)) { throw new RuntimeException('INVALID_LOOKUP'); }
        $q = $db->prepare("SELECT * FROM $table WHERE $key = ?"); $q->execute([$value]);
        return $q->fetch() ?: null;
    }

    protected function sameRaw(PDO $db, ?int $oldRawId, string $table, string $hash): bool
    {
        if ($oldRawId === null) { return false; }
        $q = $db->prepare("SELECT row_hash FROM $table WHERE id = ?"); $q->execute([$oldRawId]);
        return hash_equals((string)$q->fetchColumn(), $hash);
    }

    protected function write(PDO $db, string $table, array $values, ?int $id): void
    {
        $columns = array_keys($values);
        // Column names come exclusively from fixed code below, never CSV headings.
        foreach ($columns as $column) { if (!preg_match('/\A[a-z][a-z0-9_]*\z/D', $column)) { throw new RuntimeException('INVALID_COLUMN'); } }
        if ($id === null) {
            $sql = 'INSERT INTO ' . $table . ' (`' . implode('`,`', $columns) . '`) VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')';
            $params = array_values($values);
        } else {
            $sql = 'UPDATE ' . $table . ' SET ' . implode(',', array_map(fn($c) => '`' . $c . '` = ?', $columns)) . ' WHERE id = ?';
            $params = [...array_values($values), $id];
        }
        $db->prepare($sql)->execute($params);
    }

    protected function customer(PDO $db, string $externalId, bool $dryRun): ?int
    {
        $q = $db->prepare('SELECT id FROM stripe_customers WHERE stripe_customer_id = ?'); $q->execute([$externalId]);
        $id = $q->fetchColumn();
        if ($id !== false) { return (int)$id; }
        if ($dryRun) { return null; }
        $db->prepare('INSERT INTO stripe_customers (stripe_customer_id, is_stub) VALUES (?, 1)')->execute([$externalId]);
        return (int)$db->lastInsertId();
    }

    protected function status(?array $existing, bool $same, bool $dryRun): string
    {
        if ($same) { return 'skipped'; }
        return $dryRun ? 'skipped' : ($existing === null ? 'inserted' : 'updated');
    }
}

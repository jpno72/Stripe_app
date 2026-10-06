<?php
declare(strict_types=1);

final class People
{
    public function __construct(private PDO $db) {}

    /** Entire insert/finalization is one transaction. AUTO_INCREMENT is the only allocator. */
    public function create(?string $firstName = null, ?string $lastName = null): array
    {
        if ($this->db->inTransaction()) { throw new RuntimeException('Person creation owns its transaction.'); }
        $this->db->beginTransaction();
        try {
            $result = $this->createInTransaction($firstName, $lastName);
            $this->db->commit();
            return $result;
        } catch (Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    /** Called by a reviewed association while it owns the transaction. */
    public function createInTransaction(?string $firstName = null, ?string $lastName = null): array
    {
        if (!$this->db->inTransaction()) { throw new RuntimeException('Person creation requires a transaction.'); }
        foreach ([$firstName,$lastName] as $part) {
            if ($part !== null && (trim($part) === '' || mb_strlen($part, 'UTF-8') > 100)) { throw new RuntimeException('INVALID_PERSON_NAME'); }
        }
        $q = $this->db->prepare('INSERT INTO people (first_name, last_name) VALUES (?, ?)'); $q->execute([$firstName, $lastName]);
        $id = $this->db->lastInsertId();
        $public = 'BMA-' . str_pad($id, 6, '0', STR_PAD_LEFT);
        $q = $this->db->prepare('UPDATE people SET bma_person_id = ? WHERE id = ?'); $q->execute([$public, $id]);
        return ['id'=>$id, 'bma_person_id'=>$public];
    }

    public function rejectIdentifierChange(int $personId, int $actor): never
    {
        if ($this->db->inTransaction()) { throw new RuntimeException('Security audit requires its own committed operation.'); }
        $q = $this->db->prepare("INSERT INTO audit_log (actor_user_id, action, entity_type, entity_id) VALUES (?, 'immutable_id_change_rejected', 'people', ?)");
        $q->execute([$actor, (string)$personId]);
        throw new RuntimeException('BMA person ID is immutable');
    }
}

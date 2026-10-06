<?php
declare(strict_types=1);

/** Sole application writer for accepted identity links and cached person foreign keys. */
final class IdentityAssociations
{
    private bool $batchMode = false;
    public function __construct(private PDO $db) {}

    /** Apply related reviewed role decisions and relationships atomically. */
    public function decideMany(array $decisions): void
    {
        if ($decisions === []) { throw new RuntimeException('INVALID_IDENTITY_BATCH'); }
        $this->transaction(function (self $identity) use ($decisions): void {
            foreach ($decisions as $args) {
                if (!is_array($args) || !array_is_list($args)) { throw new RuntimeException('INVALID_IDENTITY_BATCH'); }
                $identity->decide(...$args);
            }
        });
    }

    /** One transaction for a reviewed decision, person allocation, links, and audit. */
    public function transaction(callable $work): mixed
    {
        if ($this->db->inTransaction() || $this->batchMode) { throw new RuntimeException('INVALID_IDENTITY_BATCH'); }
        $this->db->beginTransaction(); $this->batchMode = true;
        try {
            $result = $work($this);
            $this->db->commit();
            return $result;
        } catch (Throwable $e) { $this->db->rollBack(); throw $e; }
        finally { $this->batchMode = false; }
    }

    /** Reopen only a link created by the decision being undone; retain its evidence row. */
    public function reopenAccepted(string $system, string $type, string $external, string $role, int $person, int $actor): void
    {
        if (!$this->batchMode) { throw new RuntimeException('REVIEW_TRANSACTION_REQUIRED'); }
        $targets = [
            'stripe/customer/customer'=>['stripe_customers','stripe_customer_id','person_id'],
            'cognito/waiver/signer'=>['cognito_waivers','source_locator','signer_person_id'],
            'cognito/waiver/student'=>['cognito_waivers','source_locator','student_person_id'],
        ];
        [$table,$key,$column] = $targets[$system . '/' . $type . '/' . $role] ?? throw new RuntimeException('INVALID_IDENTITY_ROLE');
        $q = $this->db->prepare("SELECT id,$column FROM $table WHERE $key = ? FOR UPDATE"); $q->execute([$external]);
        $record = $q->fetch();
        if (!$record || (int)$record[$column] !== $person) { throw new RuntimeException('REVIEW_STATE_CHANGED'); }
        $q = $this->db->prepare("SELECT id FROM identity_links WHERE source_system = ? AND source_type = ? AND external_id = ? AND source_role = ? AND person_id = ? AND match_status = 'confirmed' FOR UPDATE");
        $q->execute([$system,$type,$external,$role,$person]);
        if (!$q->fetchColumn()) { throw new RuntimeException('REVIEW_STATE_CHANGED'); }
        $q = $this->db->prepare("UPDATE identity_links SET match_status = 'possible', reviewed_by = ?, reviewed_at = UTC_TIMESTAMP(6), match_reason = 'manually_reopened' WHERE source_system = ? AND source_type = ? AND external_id = ? AND source_role = ? AND person_id = ? AND match_status = 'confirmed'");
        $q->execute([$actor,$system,$type,$external,$role,$person]);
        $this->db->prepare("UPDATE $table SET $column = NULL WHERE id = ?")->execute([$record['id']]);
        $this->audit($actor,'identity_reopened',$table,(string)$record['id'],['person_id'=>$person,'role'=>$role]);
    }

    /** Add reviewed membership/edges to an already accepted role without changing that link. */
    public function supplement(string $system, string $type, string $external, string $role, int $person, int $actor, bool $makeMember, array $relationships): void
    {
        if (!$this->batchMode || (!$makeMember && $relationships === [])) { throw new RuntimeException('REVIEW_TRANSACTION_REQUIRED'); }
        $q = $this->db->prepare("SELECT 1 FROM identity_links WHERE source_system=? AND source_type=? AND external_id=? AND source_role=? AND person_id=? AND match_status IN ('automatic','confirmed') LIMIT 1");
        $q->execute([$system,$type,$external,$role,$person]);
        if ($q->fetchColumn() === false) { throw new RuntimeException('REVIEW_STATE_CHANGED'); }
        if ($makeMember) {
            if ($system !== 'cognito' || $role !== 'student') { throw new RuntimeException('INVALID_MEMBER_ROLE'); }
            $this->db->prepare('INSERT INTO members(person_id) VALUES (?) ON DUPLICATE KEY UPDATE person_id=VALUES(person_id)')->execute([$person]);
            $this->audit($actor,'member_confirmed','members',null,['person_id'=>$person]);
        }
        foreach ($relationships as $r) {
            if (!is_array($r) || count($r) !== 3) { throw new RuntimeException('INVALID_RELATIONSHIP'); }
            [$from,$to,$kind] = $r;
            if (!in_array($person,[$from,$to],true) || !in_array($kind,['parent','guardian','payer','child','spouse','self'],true)) { throw new RuntimeException('INVALID_RELATIONSHIP'); }
            $this->db->prepare('INSERT INTO person_relationships(person_id,related_person_id,relationship_type) VALUES (?,?,?) ON DUPLICATE KEY UPDATE relationship_type=VALUES(relationship_type)')->execute($r);
            $this->audit($actor,'relationship_confirmed','person_relationships',null,['from_person_id'=>$from,'to_person_id'=>$to,'relationship_type'=>$kind]);
        }
    }

    public function removeReviewedRelationship(array $relationship, int $actor): void
    {
        if (!$this->batchMode || count($relationship) !== 3) { throw new RuntimeException('REVIEW_TRANSACTION_REQUIRED'); }
        $q = $this->db->prepare('DELETE FROM person_relationships WHERE person_id=? AND related_person_id=? AND relationship_type=?');
        $q->execute($relationship);
        if ($q->rowCount() !== 1) { throw new RuntimeException('REVIEW_STATE_CHANGED'); }
        $this->audit($actor,'relationship_reopened','person_relationships',null,['from_person_id'=>$relationship[0],'to_person_id'=>$relationship[1],'relationship_type'=>$relationship[2]]);
    }

    public function removeReviewedMember(int $person, int $actor): void
    {
        if (!$this->batchMode) { throw new RuntimeException('REVIEW_TRANSACTION_REQUIRED'); }
        $q = $this->db->prepare('SELECT 1 FROM cognito_waivers WHERE student_person_id=? LIMIT 1'); $q->execute([$person]);
        if ($q->fetchColumn() !== false) { return; }
        $q = $this->db->prepare('DELETE FROM members WHERE person_id=? AND member_since IS NULL'); $q->execute([$person]);
        if ($q->rowCount() > 0) { $this->audit($actor,'member_reopened','members',null,['person_id'=>$person]); }
    }

    public function decide(string $system, string $type, string $external, string $role, int $person, string $decision, ?int $actor = null, bool $makeMember = false, array $relationships = [], ?float $score = null, ?string $method = null, ?string $reason = null, array $evidence = []): void
    {
        $targets = [
            'stripe/customer/customer'=>['stripe_customers','stripe_customer_id','person_id'],
            'cognito/waiver/student'=>['cognito_waivers','source_locator','student_person_id'],
            'cognito/waiver/signer'=>['cognito_waivers','source_locator','signer_person_id'],
        ];
        $target = $targets[$system . '/' . $type . '/' . $role] ?? null;
        if ($target === null || !in_array($decision, ['automatic','confirmed','rejected'], true) || $person < 1 || ($this->db->inTransaction() && !$this->batchMode)) { throw new RuntimeException('INVALID_IDENTITY_DECISION'); }
        $manual = $decision !== 'automatic';
        if (!$manual && ($actor !== null || $makeMember || $relationships !== [])) { throw new RuntimeException('AUTOMATION_CANNOT_CREATE_BUSINESS_RELATIONSHIPS'); }
        if ($makeMember && ($role !== 'student' || $decision !== 'confirmed')) { throw new RuntimeException('MEMBER_REQUIRES_CONFIRMED_STUDENT'); }
        if ($relationships !== [] && $decision !== 'confirmed') { throw new RuntimeException('RELATIONSHIP_REQUIRES_CONFIRMATION'); }
        if ($score !== null && ($score < 0 || $score > 1)) { throw new RuntimeException('INVALID_MATCH_SCORE'); }
        foreach ([$method, $reason] as $code) { if ($code !== null && !preg_match('/\A[a-z0-9_]{1,100}\z/D', $code)) { throw new RuntimeException('INVALID_MATCH_CODE'); } }
        $this->validateEvidence($evidence);
        [$table, $key, $column] = $target;
        if (!$this->batchMode) { $this->db->beginTransaction(); }
        try {
            if ($manual) {
                $q = $this->db->prepare("SELECT id FROM app_users WHERE id = ? AND role = 'admin' AND is_active = 1 FOR UPDATE"); $q->execute([$actor]);
                if (!$q->fetchColumn()) { throw new RuntimeException('ADMIN_REVIEW_REQUIRED'); }
            }
            // Serialize all role decisions on the external record before inspecting candidates.
            $q = $this->db->prepare("SELECT id FROM $table WHERE $key = ? FOR UPDATE"); $q->execute([$external]);
            $recordId = $q->fetchColumn();
            if (!$recordId) { throw new RuntimeException('EXTERNAL_RECORD_NOT_FOUND'); }
            $q = $this->db->prepare('SELECT bma_person_id FROM people WHERE id = ?'); $q->execute([$person]);
            if (!$q->fetchColumn()) { throw new RuntimeException('FINALIZED_PERSON_REQUIRED'); }
            $scope = [$system, $type, $external, $role];
            if (!$manual) {
                $q = $this->db->prepare("SELECT COUNT(*) FROM identity_links WHERE source_system = ? AND source_type = ? AND external_id = ? AND source_role = ? AND match_status IN ('confirmed','rejected')"); $q->execute($scope);
                if ((int)$q->fetchColumn() > 0) { throw new RuntimeException('MANUAL_DECISION_OVERRIDES_AUTOMATION'); }
                if ($system === 'cognito') {
                    $candidateRole = $role === 'signer' ? 'signer' : 'source_name_unresolved';
                    $q = $this->db->prepare("SELECT 1 FROM identity_match_candidates m JOIN stripe_customers c ON c.id = m.stripe_customer_id WHERE m.cognito_waiver_id = ? AND m.waiver_role = ? AND m.match_status = 'rejected' AND c.person_id = ? LIMIT 1");
                    $q->execute([$recordId,$candidateRole,$person]);
                    if ($q->fetchColumn() !== false) { throw new RuntimeException('MANUAL_DECISION_OVERRIDES_AUTOMATION'); }
                    $q = $this->db->prepare("SELECT 1 FROM identity_match_candidates WHERE cognito_waiver_id = ? AND waiver_role = ? AND match_reason = 'manual_review_pending' LIMIT 1");
                    $q->execute([$recordId,$candidateRole]);
                    if ($q->fetchColumn() !== false) { throw new RuntimeException('MANUAL_REVIEW_PENDING'); }
                } elseif ($system === 'stripe') {
                    $q = $this->db->prepare("SELECT 1 FROM identity_match_candidates m JOIN cognito_waivers w ON w.id = m.cognito_waiver_id WHERE m.stripe_customer_id = ? AND m.match_status = 'rejected' AND ((m.waiver_role = 'signer' AND w.signer_person_id = ?) OR (m.waiver_role = 'source_name_unresolved' AND w.student_person_id = ?)) LIMIT 1");
                    $q->execute([$recordId,$person,$person]);
                    if ($q->fetchColumn() !== false) { throw new RuntimeException('MANUAL_DECISION_OVERRIDES_AUTOMATION'); }
                }
            }
            if ($decision !== 'rejected') {
                $q = $this->db->prepare("UPDATE identity_links SET match_status = ?, reviewed_by = ?, reviewed_at = ?, updated_at = UTC_TIMESTAMP(6) WHERE source_system = ? AND source_type = ? AND external_id = ? AND source_role = ? AND person_id <> ? AND match_status IN ('automatic','confirmed')");
                $q->execute([$manual ? 'rejected' : 'possible', $actor, $manual ? gmdate('Y-m-d H:i:s') : null, ...$scope, $person]);
            }
            $q = $this->db->prepare("DELETE FROM identity_links WHERE source_system = ? AND source_type = ? AND external_id = ? AND source_role = ? AND match_status = 'unmatched'"); $q->execute($scope);
            $q = $this->db->prepare('INSERT INTO identity_links (person_id, source_system, source_type, external_id, source_role, match_method, match_score, match_reason, match_evidence, match_status, reviewed_by, reviewed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE match_method = VALUES(match_method), match_score = VALUES(match_score), match_reason = VALUES(match_reason), match_evidence = VALUES(match_evidence), match_status = VALUES(match_status), reviewed_by = VALUES(reviewed_by), reviewed_at = VALUES(reviewed_at)');
            $q->execute([$person, ...$scope, $manual ? 'manual_review' : ($method ?? 'automated_evidence'), $score,
                $manual ? 'manual_' . $decision : ($reason ?? 'automated_evidence'), json_encode($evidence, JSON_THROW_ON_ERROR),
                $decision, $actor, $manual ? gmdate('Y-m-d H:i:s') : null]);
            $q = $this->db->prepare("SELECT person_id FROM identity_links WHERE source_system = ? AND source_type = ? AND external_id = ? AND source_role = ? AND match_status IN ('automatic','confirmed')"); $q->execute($scope);
            $accepted = $q->fetchColumn();
            $q = $this->db->prepare("UPDATE $table SET $column = ? WHERE id = ?"); $q->execute([$accepted === false ? null : $accepted, $recordId]);
            $this->checkReviewedCandidates($system, (int)$recordId);
            if ($makeMember) {
                $q = $this->db->prepare('INSERT INTO members (person_id) VALUES (?) ON DUPLICATE KEY UPDATE person_id = VALUES(person_id)'); $q->execute([$person]);
            }
            foreach ($relationships as $relationship) {
                if (!is_array($relationship) || count($relationship) !== 3) { throw new RuntimeException('INVALID_RELATIONSHIP'); }
                [$from, $to, $kind] = $relationship;
                if (!is_int($from) || !is_int($to) || !in_array($person, [$from, $to], true) || !in_array($kind, ['parent','guardian','payer','child','spouse','self'], true)) { throw new RuntimeException('INVALID_RELATIONSHIP'); }
                $q = $this->db->prepare('INSERT INTO person_relationships (person_id, related_person_id, relationship_type) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE relationship_type = VALUES(relationship_type)'); $q->execute([$from, $to, $kind]);
                $this->audit($actor, 'relationship_confirmed', 'person_relationships', null, ['from_person_id'=>$from,'to_person_id'=>$to,'relationship_type'=>$kind]);
            }
            $this->audit($actor, 'identity_' . $decision, $table, (string)$recordId, ['person_id'=>$person,'role'=>$role,'member_confirmed'=>$makeMember]);
            if (!$this->batchMode) { $this->db->commit(); }
        } catch (Throwable $e) { if (!$this->batchMode) { $this->db->rollBack(); } throw $e; }
    }

    private function checkReviewedCandidates(string $system, int $recordId): void
    {
        $where = $system === 'stripe' ? 'm.stripe_customer_id = ?' : 'm.cognito_waiver_id = ?';
        $q = $this->db->prepare("SELECT m.match_status,m.waiver_role,c.person_id AS payer_person,w.signer_person_id,w.student_person_id FROM identity_match_candidates m JOIN stripe_customers c ON c.id = m.stripe_customer_id JOIN cognito_waivers w ON w.id = m.cognito_waiver_id WHERE $where AND m.match_status IN ('confirmed','rejected')");
        $q->execute([$recordId]);
        foreach ($q as $candidate) {
            $payer = $candidate['payer_person'];
            $other = $candidate['waiver_role'] === 'signer' ? $candidate['signer_person_id'] : $candidate['student_person_id'];
            if ($payer === null || $other === null) { continue; }
            $same = (int)$payer === (int)$other;
            if (($candidate['match_status'] === 'confirmed' && !$same) || ($candidate['match_status'] === 'rejected' && $same)) {
                throw new RuntimeException('REVIEWED_CANDIDATE_CONFLICT');
            }
        }
    }

    private function validateEvidence(array $evidence): void
    {
        if ($evidence === []) { return; }
        if (array_diff(array_keys($evidence), ['signals','conflicts','contact_owner_verified','role']) !== []) { throw new RuntimeException('INVALID_MATCH_EVIDENCE'); }
        $allowed = ['exact_full_name','exact_last_name','similar_name_only','exact_email','exact_phone','exact_postal','exact_address','exact_birth_date',
            'possible_parent_student_structure','different_last_name','different_full_name','conflicting_birth_date','source_name_role_unverified','self_label_names_disagree'];
        foreach (['signals','conflicts'] as $key) {
            if (isset($evidence[$key]) && (!is_array($evidence[$key]) || array_diff($evidence[$key], $allowed) !== [])) { throw new RuntimeException('INVALID_MATCH_EVIDENCE'); }
        }
        if (isset($evidence['contact_owner_verified']) && !is_bool($evidence['contact_owner_verified'])) { throw new RuntimeException('INVALID_MATCH_EVIDENCE'); }
        if (isset($evidence['role']) && !in_array($evidence['role'], ['signer','source_name_unresolved'], true)) { throw new RuntimeException('INVALID_MATCH_EVIDENCE'); }
    }

    private function audit(?int $actor, string $action, string $type, ?string $id, array $metadata): void
    {
        $q = $this->db->prepare('INSERT INTO audit_log (actor_user_id, action, entity_type, entity_id, metadata) VALUES (?, ?, ?, ?, ?)');
        $q->execute([$actor, $action, $type, $id, json_encode($metadata, JSON_THROW_ON_ERROR)]);
    }
}

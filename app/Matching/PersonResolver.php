<?php
declare(strict_types=1);

/** Role interpretation and authoritative-link checks; never merges by household contact. */
final class PersonResolver
{
    public function __construct(private readonly PDO $db) {}

    public function payerProfile(array $customer): array
    {
        return ['name'=>$customer['name'], 'email'=>$customer['email_normalized'], 'phone'=>null,
            'postal'=>$customer['address_postal_code'] === null ? null : mb_strtolower(trim($customer['address_postal_code']), 'UTF-8'),
            'address'=>$this->text($customer['address_line1']), 'birth_date'=>null];
    }

    public function waiverProfile(array $waiver, string $role): array
    {
        if (!in_array($role, ['signer','source_name_unresolved'], true)) { throw new RuntimeException('INVALID_MATCH_ROLE'); }
        $address = $this->text($waiver['address_text']);
        $postal = null;
        if ($address !== null && preg_match_all('/\b\d{5}(?:-\d{4})?\b/', $address, $matches) === 1) { $postal = strtolower($matches[0][0]); }
        return ['name'=>$role === 'signer' ? $waiver['signer_print_name'] : $waiver['source_name'],
            'email'=>$waiver['contact_email'], 'phone'=>$waiver['contact_phone'], 'postal'=>$postal,
            'address'=>$address, 'birth_date'=>$role === 'source_name_unresolved' ? $waiver['student_birth_date'] : null,
            'relationship'=>$waiver['relationship_to_student'], 'source_name'=>$waiver['source_name'],
            'signer_name'=>$waiver['signer_print_name']];
    }

    public function acceptedPerson(string $system, string $type, string $external, string $role): ?int
    {
        $q = $this->db->prepare("SELECT person_id FROM identity_links WHERE source_system = ? AND source_type = ? AND external_id = ? AND source_role = ? AND match_status IN ('automatic','confirmed') LIMIT 1");
        $q->execute([$system,$type,$external,$role]);
        $id = $q->fetchColumn(); return $id === false ? null : (int)$id;
    }

    public function rejectedFor(string $system, string $type, string $external, string $role, int $person): bool
    {
        $q = $this->db->prepare("SELECT 1 FROM identity_links WHERE source_system = ? AND source_type = ? AND external_id = ? AND source_role = ? AND person_id = ? AND match_status = 'rejected' LIMIT 1");
        $q->execute([$system,$type,$external,$role,$person]);
        return $q->fetchColumn() !== false;
    }

    public function canAutoAttachSigner(array $score, ?int $payerPerson, ?int $signerPerson, float $threshold, bool $uniqueWinner): bool
    {
        if ($payerPerson === null || $signerPerson !== null || !$uniqueWinner || $score['score'] < $threshold || $score['hard_conflict']) { return false; }
        $signals = $score['evidence']['signals'];
        // Contact owner is unresolved. Require name + email + an independent location or phone signal.
        return in_array('exact_full_name', $signals, true) && in_array('exact_email', $signals, true)
            && (in_array('exact_phone', $signals, true) || in_array('exact_address', $signals, true) || in_array('exact_postal', $signals, true));
    }

    /** Admin-reviewed role assignments; source Name is never treated as student without this explicit decision. */
    public function confirmReviewedHousehold(string $stripeCustomerId, string $waiverLocator, int $payerPerson, int $signerPerson, int $studentPerson, int $adminUserId, bool $confirmSourceRelationship): void
    {
        if ($payerPerson < 1 || $signerPerson < 1 || $studentPerson < 1 || $adminUserId < 1) { throw new RuntimeException('INVALID_REVIEWED_HOUSEHOLD'); }
        $q = $this->db->prepare('SELECT id FROM stripe_customers WHERE stripe_customer_id = ?'); $q->execute([$stripeCustomerId]);
        if (!$q->fetchColumn()) { throw new RuntimeException('EXTERNAL_RECORD_NOT_FOUND'); }
        $q = $this->db->prepare('SELECT id, relationship_to_student FROM cognito_waivers WHERE source_locator = ?'); $q->execute([$waiverLocator]);
        $waiver = $q->fetch(); if (!$waiver) { throw new RuntimeException('EXTERNAL_RECORD_NOT_FOUND'); }
        $relationships = [];
        if ($payerPerson !== $studentPerson) { $relationships[] = [$payerPerson,$studentPerson,'payer']; }
        if ($confirmSourceRelationship) {
            $label = mb_strtolower(trim((string)$waiver['relationship_to_student']), 'UTF-8');
            if (in_array($label, ['mother','father','parent'], true)) {
                if ($signerPerson === $studentPerson) { throw new RuntimeException('SOURCE_RELATION_CONFLICT'); }
                $relationships[] = [$signerPerson,$studentPerson,'parent'];
                $relationships[] = [$studentPerson,$signerPerson,'child'];
            } elseif (in_array($label, ['guardian','legal guardian'], true)) {
                if ($signerPerson === $studentPerson) { throw new RuntimeException('SOURCE_RELATION_CONFLICT'); }
                $relationships[] = [$signerPerson,$studentPerson,'guardian'];
            } elseif ($label === 'self') {
                if ($signerPerson !== $studentPerson) { throw new RuntimeException('SOURCE_RELATION_CONFLICT'); }
                $relationships[] = [$studentPerson,$studentPerson,'self'];
            } else { throw new RuntimeException('SOURCE_RELATION_UNSUPPORTED'); }
        }
        (new IdentityAssociations($this->db))->decideMany([
            ['stripe','customer',$stripeCustomerId,'customer',$payerPerson,'confirmed',$adminUserId],
            ['cognito','waiver',$waiverLocator,'signer',$signerPerson,'confirmed',$adminUserId],
            ['cognito','waiver',$waiverLocator,'student',$studentPerson,'confirmed',$adminUserId,true,$relationships],
        ]);
    }

    public function reviewCandidate(int $candidateId, string $decision, int $adminUserId): void
    {
        if ($candidateId < 1 || $adminUserId < 1 || !in_array($decision, ['confirmed','rejected'], true) || $this->db->inTransaction()) { throw new RuntimeException('INVALID_CANDIDATE_REVIEW'); }
        $this->db->beginTransaction();
        try {
            $q = $this->db->prepare("SELECT id FROM app_users WHERE id = ? AND role = 'admin' AND is_active = 1 FOR UPDATE"); $q->execute([$adminUserId]);
            if (!$q->fetchColumn()) { throw new RuntimeException('ADMIN_REVIEW_REQUIRED'); }
            $q = $this->db->prepare('SELECT m.waiver_role,c.stripe_customer_id,w.source_locator FROM identity_match_candidates m JOIN stripe_customers c ON c.id = m.stripe_customer_id JOIN cognito_waivers w ON w.id = m.cognito_waiver_id WHERE m.id = ? FOR UPDATE');
            $q->execute([$candidateId]); $candidate = $q->fetch();
            if (!$candidate) { throw new RuntimeException('MATCH_CANDIDATE_NOT_FOUND'); }
            $payer = $this->acceptedPerson('stripe','customer',$candidate['stripe_customer_id'],'customer');
            $waiverRole = $candidate['waiver_role'] === 'signer' ? 'signer' : 'student';
            $other = $this->acceptedPerson('cognito','waiver',$candidate['source_locator'],$waiverRole);
            if ($decision === 'confirmed' && ($payer === null || $payer !== $other)) { throw new RuntimeException('CONFIRMED_LINKS_REQUIRED'); }
            if ($decision === 'rejected' && $payer !== null && $payer === $other) { throw new RuntimeException('ACCEPTED_LINKS_REQUIRE_REVIEW'); }
            $q = $this->db->prepare('UPDATE identity_match_candidates SET match_status = ?, reviewed_by = ?, reviewed_at = UTC_TIMESTAMP(6) WHERE id = ?');
            $q->execute([$decision,$adminUserId,$candidateId]);
            $q = $this->db->prepare('INSERT INTO audit_log (actor_user_id,action,entity_type,entity_id,metadata) VALUES (?,?,?,?,?)');
            $q->execute([$adminUserId,'match_candidate_' . $decision,'identity_match_candidates',(string)$candidateId,json_encode(['role'=>$candidate['waiver_role']], JSON_THROW_ON_ERROR)]);
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    private function text(?string $value): ?string
    {
        if ($value === null) { return null; }
        $value = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value)), 'UTF-8');
        return $value === '' ? null : $value;
    }
}

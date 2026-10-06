<?php
declare(strict_types=1);
ini_set('zend.exception_ignore_args', '1');

final class IdentityMatcher
{
    private readonly MatchScorer $scorer;
    private readonly PersonResolver $resolver;
    private readonly float $autoThreshold;
    private readonly float $reviewThreshold;

    public function __construct(private readonly PDO $db, array $config)
    {
        $this->scorer = new MatchScorer(); $this->resolver = new PersonResolver($db);
        $this->autoThreshold = $this->threshold($config['AUTO_MATCH_THRESHOLD'] ?? '0.90');
        $this->reviewThreshold = $this->threshold($config['REVIEW_THRESHOLD'] ?? '0.45');
        if ($this->reviewThreshold >= $this->autoThreshold) { throw new RuntimeException('INVALID_MATCH_THRESHOLDS'); }
    }

    public function run(bool $dryRun = false): array
    {
        if ($this->db->inTransaction()) { throw new RuntimeException('MATCHER_REQUIRES_OWN_CONNECTION'); }
        $locked = (int)$this->db->query("SELECT GET_LOCK('bma_identity_match', 0)")->fetchColumn();
        if ($locked !== 1) { throw new RuntimeException('MATCHER_ALREADY_RUNNING'); }
        $result = ['pairs_evaluated'=>0,'possible'=>0,'unmatched'=>0,'linked'=>0,'conflict'=>0,'manual_preserved'=>0,'automatic_links'=>0,'candidate_summaries'=>[]];
        try {
            $last = 0;
            while (true) {
                $q = $this->db->prepare('SELECT id, stripe_customer_id, person_id, name, email_normalized, address_line1, address_postal_code FROM stripe_customers WHERE id > ? AND is_stub = 0 ORDER BY id LIMIT 100');
                $q->execute([$last]); $customers = $q->fetchAll();
                if ($customers === []) { break; }
                foreach ($customers as $customer) {
                    $last = (int)$customer['id'];
                    foreach ($this->waiverIds($customer) as $waiverId) {
                        $q = $this->db->prepare('SELECT id, source_locator, source_name, signer_print_name, contact_email, contact_phone, address_text, student_birth_date, relationship_to_student FROM cognito_waivers WHERE id = ?');
                        $q->execute([$waiverId]); $waiver = $q->fetch();
                        if (!$waiver) { continue; }
                        foreach (['signer','source_name_unresolved'] as $role) {
                            if ($role === 'signer' && $waiver['signer_print_name'] === null) { continue; }
                            if ($role === 'source_name_unresolved' && $waiver['source_name'] === null) { continue; }
                            $candidate = $this->evaluate($customer, $waiver, $role);
                            $result['pairs_evaluated']++;
                            if (!$dryRun) {
                                $saved = $this->save($customer, $waiver, $role, $candidate);
                                if (!$saved) { $result['manual_preserved']++; continue; }
                            }
                            $result[$candidate['status']]++;
                            if (count($result['candidate_summaries']) < 25) {
                                $result['candidate_summaries'][] = ['customer_record_id'=>(int)$customer['id'], 'waiver_record_id'=>(int)$waiver['id'],
                                    'role'=>$role, 'score'=>$candidate['score'], 'status'=>$candidate['status'], 'reason'=>$candidate['reason']];
                            }
                        }
                    }
                }
            }
            if (!$dryRun) {
                $attached = $this->attachEligibleSigners();
                $result['automatic_links'] = count($attached);
                $result['possible'] -= count($attached); $result['linked'] += count($attached);
                foreach ($result['candidate_summaries'] as &$summary) {
                    foreach ($attached as $pair) {
                        if ($summary['customer_record_id'] === $pair[0] && $summary['waiver_record_id'] === $pair[1] && $summary['role'] === 'signer') {
                            $summary['status'] = 'linked'; $summary['reason'] = 'automatic_signer_link';
                        }
                    }
                }
                unset($summary);
            }
            $result['potential_duplicate_customers'] = (int)$this->db->query("SELECT COUNT(*) FROM (SELECT email_normalized, name FROM stripe_customers WHERE is_stub = 0 AND email_normalized IS NOT NULL AND name IS NOT NULL GROUP BY email_normalized, name HAVING COUNT(*) > 1) d")->fetchColumn();
            $result['potential_duplicate_waivers'] = (int)$this->db->query("SELECT COUNT(*) FROM (SELECT content_hash FROM cognito_waivers GROUP BY content_hash HAVING COUNT(*) > 1) d")->fetchColumn();
            return $result;
        } finally { $this->db->query("SELECT RELEASE_LOCK('bma_identity_match')"); }
    }

    private function waiverIds(array $customer): array
    {
        $ids = [];
        $queries = [
            ['contact_email', $customer['email_normalized']],
            ['signer_print_name', $customer['name']],
            ['source_name', $customer['name']],
        ];
        foreach ($queries as [$column, $value]) {
            if ($value === null || trim($value) === '') { continue; }
            $q = $this->db->prepare("SELECT id FROM cognito_waivers WHERE $column = ?"); $q->execute([$value]);
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) { $ids[(int)$id] = true; }
        }
        // Reassess prior suggestions even if a contact/name later changes.
        $q = $this->db->prepare('SELECT cognito_waiver_id FROM identity_match_candidates WHERE stripe_customer_id = ?'); $q->execute([$customer['id']]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) { $ids[(int)$id] = true; }
        return array_keys($ids);
    }

    private function evaluate(array $customer, array $waiver, string $role): array
    {
        $score = $this->scorer->score($this->resolver->payerProfile($customer), $this->resolver->waiverProfile($waiver, $role), $role);
        $payerPerson = $this->resolver->acceptedPerson('stripe','customer',$customer['stripe_customer_id'],'customer');
        $waiverPerson = $role === 'signer' ? $this->resolver->acceptedPerson('cognito','waiver',$waiver['source_locator'],'signer') : null;
        $status = $score['score'] >= $this->reviewThreshold ? 'possible' : 'unmatched';
        $reason = $score['reason'];
        if ($payerPerson !== null && $waiverPerson !== null) {
            if ($payerPerson === $waiverPerson) { $status = 'linked'; $reason = 'existing_links_same_person'; $score['score'] = 1.0; $score['method'] = 'existing_identity_links'; }
            else { $status = 'conflict'; $reason = 'existing_links_different_people'; $score['hard_conflict'] = true; }
        } elseif ($payerPerson !== null && $role === 'signer' && $this->resolver->rejectedFor('cognito','waiver',$waiver['source_locator'],'signer',$payerPerson)) {
            $status = 'conflict'; $reason = 'manual_rejection'; $score['hard_conflict'] = true;
        }
        return ['score'=>$score['score'], 'method'=>$score['method'], 'reason'=>$reason, 'hard_conflict'=>$score['hard_conflict'],
            'evidence'=>$score['evidence'], 'status'=>$status];
    }

    private function save(array $customer, array $waiver, string $role, array $candidate): bool
    {
        $this->db->beginTransaction();
        try {
            $q = $this->db->prepare('SELECT id, match_status, match_reason FROM identity_match_candidates WHERE stripe_customer_id = ? AND cognito_waiver_id = ? AND waiver_role = ? FOR UPDATE');
            $q->execute([$customer['id'],$waiver['id'],$role]); $old = $q->fetch();
            if ($old && (in_array($old['match_status'], ['confirmed','rejected'], true) || $old['match_reason'] === 'manual_review_pending')) { $this->db->commit(); return false; }
            $evidence = json_encode($candidate['evidence'], JSON_THROW_ON_ERROR);
            if ($old) {
                $q = $this->db->prepare('UPDATE identity_match_candidates SET match_score = ?, match_method = ?, match_reason = ?, match_status = ?, evidence = ?, hard_conflict = ? WHERE id = ?');
                $q->execute([$candidate['score'],$candidate['method'],$candidate['reason'],$candidate['status'],$evidence,(int)$candidate['hard_conflict'],$old['id']]);
            } else {
                $q = $this->db->prepare('INSERT INTO identity_match_candidates (stripe_customer_id, cognito_waiver_id, waiver_role, match_score, match_method, match_reason, match_status, evidence, hard_conflict) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $q->execute([$customer['id'],$waiver['id'],$role,$candidate['score'],$candidate['method'],$candidate['reason'],$candidate['status'],$evidence,(int)$candidate['hard_conflict']]);
            }
            $this->db->commit(); return true;
        } catch (Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    private function attachEligibleSigners(): array
    {
        $attached = [];
        $q = $this->db->prepare("SELECT m.id, m.stripe_customer_id AS customer_record_id, m.cognito_waiver_id, m.match_score, m.match_method, m.match_reason, m.evidence, m.hard_conflict, c.stripe_customer_id AS customer_external_id, w.source_locator FROM identity_match_candidates m JOIN stripe_customers c ON c.id = m.stripe_customer_id JOIN cognito_waivers w ON w.id = m.cognito_waiver_id WHERE c.is_stub = 0 AND m.waiver_role = 'signer' AND m.match_status = 'possible' AND m.match_reason <> 'manual_review_pending' AND m.match_score >= ? AND m.hard_conflict = 0 ORDER BY m.id");
        $q->execute([$this->autoThreshold]); $rows = $q->fetchAll();
        foreach ($rows as $row) {
            $person = $this->resolver->acceptedPerson('stripe','customer',$row['customer_external_id'],'customer');
            $signer = $this->resolver->acceptedPerson('cognito','waiver',$row['source_locator'],'signer');
            $evidence = json_decode($row['evidence'], true, 512, JSON_THROW_ON_ERROR);
            $score = ['score'=>(float)$row['match_score'], 'hard_conflict'=>(bool)$row['hard_conflict'], 'evidence'=>$evidence];
            $unique = (int)scalarForMatch($this->db, "SELECT COUNT(*) FROM identity_match_candidates WHERE cognito_waiver_id = (SELECT cognito_waiver_id FROM identity_match_candidates WHERE id = ?) AND waiver_role = 'signer' AND id <> ? AND match_score >= ? AND match_status IN ('possible','linked')", [$row['id'],$row['id'],max(0,(float)$row['match_score'] - 0.15)]) === 0;
            if (!$this->resolver->canAutoAttachSigner($score, $person, $signer, $this->autoThreshold, $unique)) { continue; }
            try {
                (new IdentityAssociations($this->db))->decide('cognito','waiver',$row['source_locator'],'signer',$person,'automatic',null,false,[],
                    (float)$row['match_score'],$row['match_method'],$row['match_reason'],$evidence);
                $this->db->prepare("UPDATE identity_match_candidates SET match_status = 'linked', match_reason = 'automatic_signer_link' WHERE id = ? AND match_status = 'possible'")->execute([$row['id']]);
                $attached[] = [(int)$row['customer_record_id'],(int)$row['cognito_waiver_id']];
            } catch (RuntimeException $e) {
                if (!in_array($e->getMessage(), ['MANUAL_DECISION_OVERRIDES_AUTOMATION','EXTERNAL_RECORD_NOT_FOUND'], true)) { throw $e; }
            }
        }
        return $attached;
    }

    private function threshold(mixed $value): float
    {
        if (!is_numeric($value)) { throw new RuntimeException('INVALID_MATCH_THRESHOLD'); }
        $number = (float)$value;
        if ($number < 0 || $number > 1) { throw new RuntimeException('INVALID_MATCH_THRESHOLD'); }
        return $number;
    }
}

function scalarForMatch(PDO $db, string $sql, array $params): mixed
{
    $q = $db->prepare($sql); $q->execute($params); return $q->fetchColumn();
}

<?php
declare(strict_types=1);
ini_set('zend.exception_ignore_args', '1');

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/People.php';

/** Manual candidate decisions. All writes, including audit, share one transaction. */
final class MatchReviewService
{
    public function __construct(private readonly PDO $db) {}

    public function queue(string $search = '', string $status = 'open', int $page = 1): array
    {
        $page = max(1, min($page, 10000));
        $statuses = [
            'open'=>['possible','unmatched','conflict'], 'possible'=>['possible'],
            'unmatched'=>['unmatched'], 'conflict'=>['conflict'],
            'confirmed'=>['confirmed','linked'], 'rejected'=>['rejected'], 'all'=>[],
        ];
        if (!array_key_exists($status, $statuses)) { throw new RuntimeException('INVALID_QUEUE_STATUS'); }
        $where = []; $params = [];
        if ($statuses[$status] !== []) {
            $where[] = 'm.match_status IN (' . implode(',', array_fill(0, count($statuses[$status]), '?')) . ')';
            array_push($params, ...$statuses[$status]);
        }
        $search = trim($search);
        if ($search !== '') {
            if (mb_strlen($search, 'UTF-8') > 100) { throw new RuntimeException('SEARCH_TOO_LONG'); }
            $term = '%' . str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $search) . '%';
            $where[] = '(c.name LIKE ? OR c.email_normalized LIKE ? OR c.stripe_customer_id LIKE ? OR w.source_name LIKE ? OR w.signer_print_name LIKE ? OR w.contact_email LIKE ? OR w.contact_phone LIKE ? OR pp.bma_person_id LIKE ? OR sp.bma_person_id LIKE ? OR mp.bma_person_id LIKE ?)';
            array_push($params, ...array_fill(0, 10, $term));
        }
        $filter = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $from = ' FROM identity_match_candidates m JOIN stripe_customers c ON c.id = m.stripe_customer_id JOIN cognito_waivers w ON w.id = m.cognito_waiver_id LEFT JOIN people pp ON pp.id = c.person_id LEFT JOIN people sp ON sp.id = w.signer_person_id LEFT JOIN people mp ON mp.id = w.student_person_id';
        $q = $this->db->prepare('SELECT COUNT(*)' . $from . $filter); $q->execute($params);
        $total = (int)$q->fetchColumn();
        $sql = 'SELECT m.id,m.waiver_role,m.match_score,m.match_method,m.match_reason,m.match_status,m.evidence,m.hard_conflict,m.reviewed_by,m.reviewed_at,m.updated_at,c.stripe_customer_id,c.person_id AS payer_person_id,c.name AS stripe_name,c.email_normalized AS stripe_email,c.address_line1,c.address_city,c.address_postal_code,w.source_locator,w.source_name,w.signer_print_name,w.contact_email,w.contact_phone,w.address_text,w.relationship_to_student,w.signer_person_id,w.student_person_id,pp.bma_person_id AS payer_bma,sp.bma_person_id AS signer_bma,mp.bma_person_id AS student_bma' . $from . $filter . ' ORDER BY CASE m.match_status WHEN \'conflict\' THEN 0 WHEN \'possible\' THEN 1 WHEN \'unmatched\' THEN 2 ELSE 3 END,m.match_score DESC,m.id DESC LIMIT 25 OFFSET ' . (($page - 1) * 25);
        $q = $this->db->prepare($sql); $q->execute($params);
        return ['rows'=>$q->fetchAll(),'total'=>$total,'page'=>$page];
    }

    public function history(int $candidateId): array
    {
        $q = $this->db->prepare("SELECT a.action,a.metadata,a.created_at,u.display_name FROM audit_log a LEFT JOIN app_users u ON u.id=a.actor_user_id WHERE a.entity_type='identity_match_candidates' AND a.entity_id=? AND a.action LIKE 'match_review_%' ORDER BY a.id DESC LIMIT 20");
        $q->execute([(string)$candidateId]); return $q->fetchAll();
    }

    public function decide(int $candidateId, string $action, int $actor, array $input): void
    {
        $allowed = ['confirm_same','confirm_relationship','link_existing','create_separate','reject','later','reopen'];
        if ($candidateId < 1 || $actor < 1 || !in_array($action,$allowed,true)) { throw new RuntimeException('INVALID_REVIEW_ACTION'); }
        $notes = trim((string)($input['notes'] ?? ''));
        if (mb_strlen($notes,'UTF-8') > 2000) { throw new RuntimeException('NOTES_TOO_LONG'); }
        (new IdentityAssociations($this->db))->transaction(function (IdentityAssociations $identity) use ($candidateId,$action,$actor,$input,$notes): void {
            $q = $this->db->prepare("SELECT id FROM app_users WHERE id = ? AND role = 'admin' AND is_active = 1 FOR UPDATE");
            $q->execute([$actor]); if (!$q->fetchColumn()) { throw new RuntimeException('ADMIN_REVIEW_REQUIRED'); }
            $candidate = $this->candidate($candidateId);
            $before = ['status'=>$candidate['match_status'],'reason'=>$candidate['match_reason'],'payer_person_id'=>$candidate['payer_person_id'],'signer_person_id'=>$candidate['signer_person_id'],'student_person_id'=>$candidate['student_person_id']];
            $created = ['scopes'=>[],'relationships'=>[],'members'=>[],'people'=>[],'scope_refs'=>[],'relationship_refs'=>[]];
            if ($action === 'reopen') {
                $this->reopen($candidate,$identity,$actor,$created);
                $status = 'possible';
            } else {
                if (in_array($candidate['match_status'],['confirmed','rejected'],true)) { throw new RuntimeException('REOPEN_PRIOR_DECISION_FIRST'); }
                $status = match ($action) {
                    'confirm_same' => $this->confirmSame($candidate,$identity,$actor,$input,$created),
                    'confirm_relationship' => $this->confirmRelationship($candidate,$identity,$actor,$input,$created),
                    'link_existing' => $this->linkExisting($candidate,$identity,$actor,$input,$created),
                    'create_separate' => $this->createSeparate($candidate,$identity,$actor,$created),
                    'reject' => $this->reject($candidate),
                    'later' => 'possible',
                    default => throw new RuntimeException('INVALID_REVIEW_ACTION'),
                };
            }
            $reason = in_array($action,['later','link_existing','reopen'],true) ? 'manual_review_pending' : 'manual_' . $action;
            $q = $this->db->prepare('UPDATE identity_match_candidates SET match_status = ?, match_reason = ?, reviewed_by = ?, reviewed_at = UTC_TIMESTAMP(6) WHERE id = ?');
            $q->execute([$status,$reason,$actor,$candidateId]);
            $after = $this->candidate($candidateId);
            $metadata = [
                'action'=>$action,'before'=>$before,
                'after'=>['status'=>$after['match_status'],'reason'=>$after['match_reason'],'payer_person_id'=>$after['payer_person_id'],'signer_person_id'=>$after['signer_person_id'],'student_person_id'=>$after['student_person_id']],
                'created'=>$created,'notes'=>$notes,
            ];
            $q = $this->db->prepare('INSERT INTO audit_log (actor_user_id,action,entity_type,entity_id,metadata) VALUES (?,?,?,?,?)');
            $q->execute([$actor,'match_review_' . $action,'identity_match_candidates',(string)$candidateId,json_encode($metadata,JSON_THROW_ON_ERROR)]);
        });
    }

    private function candidate(int $id): array
    {
        $q = $this->db->prepare('SELECT m.id,m.waiver_role,m.match_status,m.match_reason,m.stripe_customer_id,m.cognito_waiver_id,c.stripe_customer_id AS customer_external,c.name AS stripe_name,c.person_id AS payer_person_id,w.source_locator,w.source_name,w.signer_print_name,w.relationship_to_student,w.signer_person_id,w.student_person_id FROM identity_match_candidates m JOIN stripe_customers c ON c.id=m.stripe_customer_id JOIN cognito_waivers w ON w.id=m.cognito_waiver_id WHERE m.id=? FOR UPDATE');
        $q->execute([$id]); return $q->fetch() ?: throw new RuntimeException('MATCH_CANDIDATE_NOT_FOUND');
    }

    private function personId(string $bma): int
    {
        if (!preg_match('/\ABMA-[0-9]{6,}\z/D',$bma)) { throw new RuntimeException('BMA_PERSON_ID_REQUIRED'); }
        $q = $this->db->prepare('SELECT id FROM people WHERE bma_person_id=?'); $q->execute([$bma]);
        return (int)($q->fetchColumn() ?: throw new RuntimeException('PERSON_NOT_FOUND'));
    }

    private function newPerson(?string $name, array &$created): int
    {
        $parts = preg_split('/\s+/u', trim((string)$name));
        if (!$parts || $parts[0] === '') { throw new RuntimeException('NAME_REQUIRED_FOR_NEW_PERSON'); }
        $first = array_shift($parts); $last = $parts === [] ? null : implode(' ',$parts);
        $person = (new People($this->db))->createInTransaction($first,$last);
        $created['people'][] = (int)$person['id'];
        return (int)$person['id'];
    }

    private function associate(IdentityAssociations $identity,array $candidate,string $side,int $person,int $actor,array &$created,bool $makeMember=false,array $relationships=[]): void
    {
        [$system,$type,$external,$role,$current] = $side === 'stripe'
            ? ['stripe','customer',$candidate['customer_external'],'customer',$candidate['payer_person_id']]
            : ['cognito','waiver',$candidate['source_locator'],$side,$side === 'signer' ? $candidate['signer_person_id'] : $candidate['student_person_id']];
        if ($current !== null && (int)$current !== $person) { throw new RuntimeException('EXISTING_PERSON_ASSOCIATION_CONFLICT'); }
        $created['scope_refs'][] = [$system,$type,$external,$role,$person];
        if ($current === null) {
            $identity->decide($system,$type,$external,$role,$person,'confirmed',$actor,$makeMember,$relationships);
            $created['scopes'][] = [$system,$type,$external,$role,$person];
            if ($makeMember) { $created['members'][] = $person; }
            foreach ($relationships as $r) { $created['relationships'][] = $r; }
        } elseif ($makeMember || $relationships !== []) {
            $identity->supplement($system,$type,$external,$role,$person,$actor,$makeMember,$relationships);
            if ($makeMember) { $created['members'][] = $person; }
            foreach ($relationships as $r) { $created['relationships'][] = $r; }
        }
    }

    private function role(array $candidate): string { return $candidate['waiver_role'] === 'signer' ? 'signer' : 'student'; }

    private function confirmSame(array $candidate,IdentityAssociations $identity,int $actor,array $input,array &$created): string
    {
        $selected = trim((string)($input['person_bma'] ?? ''));
        $person = $selected !== '' ? $this->personId($selected) : ($candidate['payer_person_id'] === null ? $this->newPerson($candidate['stripe_name'],$created) : (int)$candidate['payer_person_id']);
        $this->associate($identity,$candidate,'stripe',$person,$actor,$created);
        $role = $this->role($candidate);
        $memberExists = $this->memberExists($person);
        $selfLabel = strtolower(trim((string)$candidate['relationship_to_student'])) === 'self';
        if ($role === 'student' && $selfLabel && $candidate['signer_person_id'] !== null && (int)$candidate['signer_person_id'] !== $person) {
            throw new RuntimeException('SOURCE_RELATION_UNSUPPORTED');
        }
        $relations = $role === 'student' && $selfLabel ? [[$person,$person,'self']] : [];
        array_push($created['relationship_refs'], ...$relations);
        $relations = $this->newRelationships($relations);
        $this->associate($identity,$candidate,$role,$person,$actor,$created,$role === 'student' && !$memberExists,$relations);
        return 'confirmed';
    }

    private function confirmRelationship(array $candidate,IdentityAssociations $identity,int $actor,array $input,array &$created): string
    {
        $payer = $this->personId(trim((string)($input['payer_bma'] ?? '')));
        $student = $this->personId(trim((string)($input['student_bma'] ?? '')));
        $signer = $this->personId(trim((string)($input['signer_bma'] ?? '')));
        if ($payer === $student) { throw new RuntimeException('SEPARATE_PAYER_STUDENT_REQUIRED'); }
        $this->associate($identity,$candidate,'stripe',$payer,$actor,$created);
        $this->associate($identity,$candidate,'signer',$signer,$actor,$created);
        $relationships = [[$payer,$student,'payer']];
        if (!empty($input['confirm_source_relationship'])) {
            $label = strtolower(trim((string)$candidate['relationship_to_student']));
            if (in_array($label,['mother','father','parent'],true) && $signer !== $student) {
                $relationships[] = [$signer,$student,'parent']; $relationships[] = [$student,$signer,'child'];
            } elseif (in_array($label,['guardian','legal guardian'],true) && $signer !== $student) {
                $relationships[] = [$signer,$student,'guardian'];
            } else { throw new RuntimeException('SOURCE_RELATION_UNSUPPORTED'); }
        }
        array_push($created['relationship_refs'], ...$relationships);
        $freshRelationships = $this->newRelationships($relationships);
        $this->associate($identity,$candidate,'student',$student,$actor,$created,!$this->memberExists($student),$freshRelationships);
        return $this->role($candidate) === 'signer' && $payer === $signer ? 'confirmed' : 'rejected';
    }

    private function linkExisting(array $candidate,IdentityAssociations $identity,int $actor,array $input,array &$created): string
    {
        $person = $this->personId(trim((string)($input['person_bma'] ?? '')));
        $side = (string)($input['side'] ?? '');
        if (!in_array($side,['stripe','signer','student'],true)) { throw new RuntimeException('INVALID_LINK_SIDE'); }
        $this->associate($identity,$candidate,$side,$person,$actor,$created,$side === 'student' && !$this->memberExists($person));
        return 'possible';
    }

    private function createSeparate(array $candidate,IdentityAssociations $identity,int $actor,array &$created): string
    {
        $payer = $candidate['payer_person_id'] === null ? $this->newPerson($candidate['stripe_name'],$created) : (int)$candidate['payer_person_id'];
        $name = $this->role($candidate) === 'signer' ? $candidate['signer_print_name'] : $candidate['source_name'];
        $other = $this->newPerson($name,$created);
        $this->associate($identity,$candidate,'stripe',$payer,$actor,$created);
        $this->associate($identity,$candidate,$this->role($candidate),$other,$actor,$created,$this->role($candidate) === 'student');
        return 'rejected';
    }

    private function reject(array $candidate): string
    {
        $other = $this->role($candidate) === 'signer' ? $candidate['signer_person_id'] : $candidate['student_person_id'];
        if ($candidate['payer_person_id'] !== null && $other !== null && (int)$candidate['payer_person_id'] === (int)$other) { throw new RuntimeException('ACCEPTED_LINKS_REQUIRE_REVIEW'); }
        return 'rejected';
    }

    private function memberExists(int $person): bool
    {
        $lock = $this->db->prepare('SELECT id FROM people WHERE id=? FOR UPDATE'); $lock->execute([$person]);
        if ($lock->fetchColumn() === false) { throw new RuntimeException('PERSON_NOT_FOUND'); }
        $q = $this->db->prepare('SELECT 1 FROM members WHERE person_id=?'); $q->execute([$person]); return $q->fetchColumn() !== false;
    }

    private function newRelationships(array $relationships): array
    {
        $people = [];
        foreach ($relationships as $r) { $people[(int)$r[0]] = true; $people[(int)$r[1]] = true; }
        $ids = array_keys($people); sort($ids, SORT_NUMERIC);
        if ($ids !== []) {
            $locks = $this->db->prepare('SELECT id FROM people WHERE id IN (' . implode(',',array_fill(0,count($ids),'?')) . ') ORDER BY id FOR UPDATE');
            $locks->execute($ids);
            if (count($locks->fetchAll(PDO::FETCH_COLUMN)) !== count($ids)) { throw new RuntimeException('PERSON_NOT_FOUND'); }
        }
        $fresh = [];
        $q = $this->db->prepare('SELECT 1 FROM person_relationships WHERE person_id=? AND related_person_id=? AND relationship_type=?');
        foreach ($relationships as $r) { $q->execute($r); if ($q->fetchColumn() === false) { $fresh[] = $r; } }
        return $fresh;
    }

    private function reopen(array $candidate,IdentityAssociations $identity,int $actor,array &$created): void
    {
        $q = $this->db->prepare("SELECT id,action,metadata FROM audit_log WHERE entity_type='identity_match_candidates' AND entity_id=? AND action LIKE 'match_review_%' ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $q->execute([(string)$candidate['id']]); $prior = $q->fetch();
        if (!$prior || $prior['action'] === 'match_review_reopen') { throw new RuntimeException('NO_REVERSIBLE_REVIEW_DECISION'); }
        $meta = json_decode((string)$prior['metadata'],true,512,JSON_THROW_ON_ERROR);
        if (($meta['after']['status'] ?? null) !== $candidate['match_status']) { throw new RuntimeException('REVIEW_STATE_CHANGED'); }
        $old = $meta['created'] ?? [];
        $this->checkLaterDependencies((int)$prior['id'],(int)$candidate['id'],$old);
        foreach (array_reverse($old['relationships'] ?? []) as $r) {
            $identity->removeReviewedRelationship($r,$actor);
        }
        foreach (array_reverse($old['scopes'] ?? []) as $scope) { $identity->reopenAccepted(...[...$scope,$actor]); }
        foreach ($old['members'] ?? [] as $person) {
            $identity->removeReviewedMember((int)$person,$actor);
        }
        $created['reopened_audit_id'] = (int)$prior['id'];
    }

    private function checkLaterDependencies(int $priorAuditId,int $candidateId,array $old): void
    {
        if (($old['scopes'] ?? []) === [] && ($old['relationships'] ?? []) === []) { return; }
        $q = $this->db->prepare("SELECT id,entity_id,action,metadata FROM audit_log WHERE id>? AND entity_type='identity_match_candidates' AND action LIKE 'match_review_%' ORDER BY id");
        $q->execute([$priorAuditId]);
        $latest = [];
        $dependent = [];
        foreach ($q as $event) {
            $otherId = (int)$event['entity_id'];
            if ($otherId === $candidateId) { continue; }
            $latest[$otherId] = $event['action'];
            $meta = json_decode((string)$event['metadata'],true) ?: [];
            $refs = $meta['created'] ?? [];
            foreach ($old['scopes'] ?? [] as $scope) {
                if (in_array($scope,$refs['scope_refs'] ?? [],true)) { $dependent[$otherId] = true; }
            }
            foreach ($old['relationships'] ?? [] as $relationship) {
                if (in_array($relationship,$refs['relationship_refs'] ?? [],true)) { $dependent[$otherId] = true; }
            }
        }
        foreach ($dependent as $otherId=>$_) {
            if (($latest[$otherId] ?? '') !== 'match_review_reopen') { throw new RuntimeException('REVIEW_DEPENDENCY_EXISTS'); }
        }
    }
}

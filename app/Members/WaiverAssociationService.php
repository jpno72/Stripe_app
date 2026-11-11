<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/Matching/MatchScorer.php';
require_once dirname(__DIR__).'/Import/Normalizers.php';
require_once dirname(__DIR__,2).'/src/IdentityAssociations.php';
require_once dirname(__DIR__).'/Security/DashboardPermissionService.php';

/** On-demand discovery, never an automatic identity matcher. Evidence contains codes, not source text. */
final class WaiverAssociationService
{
    public function __construct(private PDO $db) {}
    private function rows(string $sql,array $p=[]):array {$q=$this->db->prepare($sql);$q->execute($p);return $q->fetchAll();}
    public static function ready(PDO $db):bool
    {
        return (bool)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='waiver_association_reviews'")->fetchColumn()
            && (bool)$db->query("SELECT COUNT(*) FROM schema_migrations WHERE version='013_waiver_association_reviews'")->fetchColumn();
    }
    private function actor(int $id):void
    {
        $u=$this->rows('SELECT id,role,is_active FROM app_users WHERE id=?',[$id])[0]??null;
        if(!$u||!$u['is_active']||$u['role']!=='admin'||!(new DashboardPermissionService($this->db))->canViewDashboard($u,'members'))throw new RuntimeException('ADMIN_MEMBERS_PERMISSION_REQUIRED');
    }
    private function person(int $id):array
    {
        return $this->rows('SELECT id,bma_person_id,first_name,middle_name,last_name,email,phone,birth_date,street_address,city,state,postal_code FROM people WHERE id=?',[$id])[0]??throw new RuntimeException('PERSON_NOT_FOUND');
    }
    public function subject(int $actor,int $person):array {$this->actor($actor);return $this->person($person);}
    public function profileSummary(int $actor,int $person):array
    {
        $this->actor($actor);$this->person($person);$sql=self::directorySql($this->db);
        $row=$this->rows('SELECT verified,proposed FROM '.$sql['summary'].' a WHERE person_id=?',[$person])[0]??['verified'=>0,'proposed'=>0];
        return ['verified'=>(int)$row['verified'],'proposed'=>(int)$row['proposed'],'ready'=>self::ready($this->db)];
    }
    private function contacts(array $p):array
    {
        $emails=[];$phones=[];
        $sources=[$p,...$this->rows("SELECT c.email,NULL phone FROM stripe_customers c JOIN identity_links l ON l.person_id=c.person_id AND l.external_id=c.stripe_customer_id AND l.source_system='stripe' AND l.source_type='customer' AND l.source_role='customer' AND l.match_status IN ('confirmed','automatic') WHERE c.person_id=?",[$p['id']])];
        foreach($sources as $s){if($v=Normalizers::email($s['email']))$emails[]=$v;if(($v=Normalizers::phone($s['phone']))&&strlen($v)>=10)$phones[]=$v;}
        return [array_unique($emails),array_unique($phones)];
    }
    private function waiver(int $id):array
    {
        return $this->rows('SELECT '.self::waiverColumns().' FROM cognito_waivers WHERE id=?',[$id])[0]??throw new RuntimeException('WAIVER_NOT_FOUND');
    }
    private static function waiverColumns():string {return 'id,source_locator,source_record_id,content_hash,source_format,source_name_role,source_name,source_student_first_name,source_student_last_name,student_birth_date,student_person_id,signer_person_id,signer_print_name,contact_email,contact_phone,address_text,emergency_contact_name,emergency_contact_phone,relationship_to_student,submitted_at,source_timezone';}
    public static function studentName(array $w):string
    {
        return trim(($w['source_student_first_name']??'').' '.($w['source_student_last_name']??'')) ?: (string)($w['source_name']??'');
    }
    public static function evidence(array $p,array $w,string $role,array $emails,array $phones):?array
    {
        $name=trim(implode(' ',array_filter([$p['first_name'],$p['middle_name']??null,$p['last_name']])));
        $other=match($role){'student'=>self::studentName($w),'signer'=>$w['signer_print_name']??'','emergency'=>$w['emergency_contact_name']??'',default=>''};
        $same=MatchScorer::namesCompatible($name,$other);$variant=MatchScorer::namesCompatible($name,$other,true);
        $email=$role!=='emergency'&&($v=Normalizers::email($w['contact_email']??null))&&in_array($v,$emails,true);
        $phone=Normalizers::phone($role==='emergency'?($w['emergency_contact_phone']??null):($w['contact_phone']??null));
        $phone=$phone!==null&&strlen($phone)>=10&&in_array($phone,$phones,true);
        $address=mb_strtolower((string)Normalizers::text(implode(' ',array_filter([$p['street_address']??null,$p['city']??null,$p['state']??null,$p['postal_code']??null]))));
        $address=$role!=='emergency'&&$address!==''&&$address===mb_strtolower((string)Normalizers::text($w['address_text']??null));
        $accepted=$role==='student'?($w['student_person_id']??null):($role==='signer'?($w['signer_person_id']??null):null);
        if(trim($other)===''&&$accepted===null)return null;
        if(!$same&&!$variant&&!$email&&!$phone&&!$address&&(int)$accepted!==(int)$p['id'])return null;
        $e=[];if($same)$e[]='compatible_name';elseif($variant)$e[]='ambiguous_nickname';if($email)$e[]='shared_source_email';if($phone)$e[]='matching_phone';
        if($address)$e[]='shared_source_address';
        $dob=$role==='student'&&!empty($p['birth_date'])&&$p['birth_date']===($w['student_birth_date']??null);if($dob)$e[]='matching_birth_date';
        if($other!==''&&!$same&&!$variant)$e[]='different_name';
        if($accepted!==null&&(int)$accepted!==(int)$p['id'])$e[]='different_accepted_person';
        if($role==='student'&&($w['source_name_role']??'')!=='student')$e[]='legacy_name_role_unresolved';
        if($role==='student'&&!empty($p['birth_date'])&&!empty($w['student_birth_date'])&&$p['birth_date']!==$w['student_birth_date'])$e[]='conflicting_birth_date';
        if($role!=='emergency'&&!empty($w['relationship_to_student']))$e[]='relationship_source_requires_review';
        $conflict=array_intersect($e,['different_name','different_accepted_person','legacy_name_role_unresolved','conflicting_birth_date','ambiguous_nickname']);
        $class=$conflict?'ambiguous':($same&&($phone||$email||$address||$dob)?'strong':'possible');
        // Accepted identity must be verified against authoritative links by the caller.
        return ['classification'=>$class,'evidence'=>$e];
    }
    private function accepted(array $w,string $role,int $person):bool
    {
        if(!in_array($role,['student','signer'],true))return false;
        if((int)$w[$role==='student'?'student_person_id':'signer_person_id']!==$person)return false;
        return $this->rows("SELECT id FROM identity_links WHERE source_system='cognito' AND source_type='waiver' AND external_id=? AND source_role=? AND person_id=? AND match_status IN ('automatic','confirmed')",[$w['source_locator'],$role,$person])!==[];
    }
    private function digest(array $p,array $w):string
    {
        // Signer confirmation changes its cache. Keep the student target in the
        // digest so a payer/guardian confirmation cannot silently target another child.
        unset($w['signer_person_id']);
        return hash('sha256',serialize([$p,$w]));
    }
    /** Keyset scan only on explicit request. No medical/raw JSON selected. */
    public function discover(int $actor,int $person):int
    {
        $this->actor($actor);if(!self::ready($this->db))throw new RuntimeException('MIGRATION_013_APPROVAL_REQUIRED');
        $p=$this->person($person);[$emails,$phones]=$this->contacts($p);$last=0;$n=0;$accepted=[];$edges=[];
        foreach($this->rows("SELECT external_id,source_role FROM identity_links WHERE person_id=? AND source_system='cognito' AND source_type='waiver' AND source_role IN ('student','signer') AND match_status IN ('automatic','confirmed')",[$person]) as $link)$accepted[$link['external_id']][$link['source_role']]=true;
        foreach($this->rows("SELECT related_person_id,relationship_type FROM person_relationships WHERE person_id=? AND relationship_type IN ('payer','parent','guardian')",[$person]) as $edge)$edges[$edge['related_person_id']][]=$edge['relationship_type'];
        while($batch=$this->rows('SELECT '.self::waiverColumns().' FROM cognito_waivers WHERE id>? ORDER BY id LIMIT 200',[$last])){
            foreach($batch as $w){$last=(int)$w['id'];$related=$edges[$w['student_person_id']]??[];
                foreach(['student','signer','emergency',...$related] as $role){$e=in_array($role,$related,true)?['classification'=>'verified','evidence'=>['accepted_relationship']]:self::evidence($p,$w,$role,$emails,$phones);if(isset($accepted[$w['source_locator']][$role])&&(int)$w[$role==='student'?'student_person_id':'signer_person_id']===$person)$e=['classification'=>'verified','evidence'=>['accepted_identity_link']];if($e===null)continue;
                    $q=$this->db->prepare("INSERT INTO waiver_association_reviews(person_id,waiver_id,source_role,classification,evidence,source_digest,source_hash) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE classification=IF(review_status='possible',VALUES(classification),classification),evidence=IF(review_status='possible',VALUES(evidence),evidence),source_digest=IF(review_status='possible',VALUES(source_digest),source_digest),source_hash=IF(review_status='possible',VALUES(source_hash),source_hash)");$q->execute([$person,$w['id'],$role,$e['classification'],json_encode($e['evidence'],JSON_THROW_ON_ERROR),$this->digest($p,$w),$w['content_hash']]);++$n;
                }
            }
        }
        return $n;
    }
    /** All cache reads are admin-authorized. Rejected proposals never reappear in ordinary searches. */
    public function groups(int $actor,int $person):array
    {
        $this->actor($actor);$this->person($person);if(!self::ready($this->db))return [];
        $rows=$this->rows("SELECT r.*,w.source_record_id,w.source_locator,w.source_name,w.source_student_first_name,w.source_student_last_name,w.student_birth_date,w.student_person_id,w.signer_print_name,w.emergency_contact_name,w.relationship_to_student,w.submitted_at,w.source_timezone,p.bma_person_id,TRIM(CONCAT_WS(' ',p.first_name,p.middle_name,p.last_name)) student_name FROM waiver_association_reviews r JOIN cognito_waivers w ON w.id=r.waiver_id LEFT JOIN people p ON p.id=w.student_person_id WHERE r.person_id=? AND r.review_status<>'rejected' ORDER BY w.student_person_id,w.submitted_at DESC,w.id DESC,r.source_role",[$person]);
        $subject=$this->person($person);$out=[];foreach($rows as $r){$w=$this->waiver((int)$r['waiver_id']);$r['source_contact']=$r['source_role']==='emergency'?($w['emergency_contact_phone']??null):trim(($w['contact_email']??'').' / '.($w['contact_phone']??''));$r['current_digest']=$this->digest($subject,$w);$r['stale']=$r['current_digest']!==$r['source_digest'];$r['accepted']=$this->accepted($w,$r['source_role'],$person)||($r['review_status']==='confirmed'&&!$r['stale']&&$r['source_role']==='emergency');
            $r['verified_relationships']=$w['student_person_id']===null?[]:$this->rows("SELECT relationship_type FROM person_relationships WHERE person_id=? AND related_person_id=? AND relationship_type IN ('payer','parent','guardian')",[$person,$w['student_person_id']]);
            if(in_array($r['source_role'],array_column($r['verified_relationships'],'relationship_type'),true))$r['accepted']=true;
            $label=$r['student_name']?:self::studentName($r);$key=$r['student_person_id']!==null?'p:'.$r['student_person_id']:(!empty($r['student_birth_date'])?'s:'.hash('sha256',MatchScorer::comparisonName(self::studentName($r),false).'|'.$r['student_birth_date']):'w:'.$r['waiver_id']);
            if(!isset($out[$key]))$out[$key]=['student'=>$label,'bma'=>$r['bma_person_id'],'waivers'=>[]];
            $out[$key]['waivers'][$r['waiver_id']][]=$r;
        }return array_values($out);
    }
    public function decide(int $actor,int $person,int $review,string $action,string $role,string $digest,string $note):void
    {
        $this->actor($actor);if(!self::ready($this->db))throw new RuntimeException('MIGRATION_013_APPROVAL_REQUIRED');
        if(!in_array($action,['confirm','reject','hold','resume'],true)||!in_array($role,['student','signer','emergency','payer','parent','guardian'],true)||strlen($note)<3||mb_strlen($note)>1000||!preg_match('/\A[a-f0-9]{64}\z/D',$digest))throw new InvalidArgumentException('INVALID_REVIEW');
        (new IdentityAssociations($this->db))->transaction(function(IdentityAssociations $identity)use($actor,$person,$review,$action,$role,$digest,$note):void{
            $this->actor($actor);$p=$this->rows('SELECT id FROM people WHERE id=? FOR UPDATE',[$person]);if(!$p)throw new RuntimeException('PERSON_NOT_FOUND');
            $before=$this->rows('SELECT * FROM waiver_association_reviews WHERE id=? AND person_id=? FOR UPDATE',[$review,$person])[0]??throw new RuntimeException('REVIEW_NOT_FOUND');
            $this->rows('SELECT id FROM cognito_waivers WHERE id=? FOR UPDATE',[$before['waiver_id']]);$p=$this->person($person);$w=$this->waiver((int)$before['waiver_id']);
            if(!hash_equals($this->digest($p,$w),$digest)||($action!=='resume'&&!hash_equals($before['source_digest'],$digest)))throw new RuntimeException('SOURCE_CHANGED_REDISCOVER_REQUIRED');
            $status=['confirm'=>'confirmed','reject'=>'rejected','hold'=>'held','resume'=>'possible'][$action];
            // Persist the requested role separately; emergency identities never become signer/student links.
            if(!in_array($role,['payer','parent','guardian'],true)&&$role!==$before['source_role'])throw new RuntimeException('ROLE_MISMATCH');
            $previous=$this->rows('SELECT * FROM waiver_association_reviews WHERE person_id=? AND waiver_id=? AND source_role=? FOR UPDATE',[$person,$w['id'],$role])[0]??null;
            if($action==='resume'){
                if($role!==$before['source_role']||$before['review_status']!=='held')throw new RuntimeException('ONLY_DISCOVERY_HOLD_CAN_BE_RESUMED');
                [$emails,$phones]=$this->contacts($p);$e=self::evidence($p,$w,$role,$emails,$phones)??['classification'=>'ambiguous','evidence'=>['evidence_changed_requires_review']];
                $q=$this->db->prepare("UPDATE waiver_association_reviews SET review_status='possible',classification=?,evidence=?,source_digest=?,source_hash=?,reviewed_by=?,reviewed_at=UTC_TIMESTAMP(6) WHERE id=?");$q->execute([$e['classification'],json_encode($e['evidence'],JSON_THROW_ON_ERROR),$digest,$w['content_hash'],$actor,$review]);
                $q=$this->db->prepare("INSERT INTO audit_log(actor_user_id,action,entity_type,entity_id,metadata) VALUES (?,'waiver_association_resumed','waiver_association_reviews',?,?)");$q->execute([$actor,(string)$review,json_encode(['previous_status'=>'held','new_status'=>'possible','note'=>$note],JSON_THROW_ON_ERROR)]);return;
            }
            if($previous&&$previous['review_status']===$status)return;
            if(($previous&&in_array($previous['review_status'],['confirmed','rejected','held'],true))||in_array($before['review_status'],['rejected','held'],true))throw new RuntimeException('EXISTING_DECISION_REQUIRES_REVIEW');
            if($action!=='confirm'&&$before['source_role']!==$role)throw new RuntimeException('ROLE_MISMATCH');
            if($action==='confirm'){
                $blocked=$this->rows("SELECT id FROM identity_match_candidates WHERE cognito_waiver_id=? AND (match_reason='manual_review_pending' OR match_status='rejected') LIMIT 1 FOR UPDATE",[$w['id']]);if($blocked)throw new RuntimeException('EXISTING_REVIEW_HOLD_OR_REJECTION');
                if(in_array($role,['student','signer'],true)){
                    $links=$this->rows("SELECT person_id,match_status FROM identity_links WHERE source_system='cognito' AND source_type='waiver' AND external_id=? AND source_role=? FOR UPDATE",[$w['source_locator'],$role]);
                    foreach($links as $l)if((in_array($l['match_status'],['automatic','confirmed'],true)&&(int)$l['person_id']!==$person)||($l['match_status']==='rejected'&&(int)$l['person_id']===$person))throw new RuntimeException('EXISTING_IDENTITY_CONFLICT');
                    $column=$role==='student'?'student_person_id':'signer_person_id';if($w[$column]!==null&&(int)$w[$column]!==$person)throw new RuntimeException('EXISTING_IDENTITY_CONFLICT');
                    if($role==='student'&&$p['birth_date']!==null&&$w['student_birth_date']!==null&&$p['birth_date']!==$w['student_birth_date'])throw new RuntimeException('CONFLICTING_BIRTH_DATE');
                    $identity->decide('cognito','waiver',$w['source_locator'],$role,$person,'confirmed',$actor,false);
                }elseif($role==='emergency'){
                    if(trim((string)$w['emergency_contact_name'])==='')throw new RuntimeException('NO_EMERGENCY_CONTACT_SOURCE');
                    if($this->rows("SELECT id FROM waiver_association_reviews WHERE waiver_id=? AND source_role='emergency' AND review_status='confirmed' AND person_id<>? FOR UPDATE",[$w['id'],$person]))throw new RuntimeException('EXISTING_EMERGENCY_IDENTITY_CONFLICT');
                }else{
                    $student=(int)($w['student_person_id']??0);if($student<1||$student===$person||!$this->accepted($w,'student',$student))throw new RuntimeException('SEPARATE_ACCEPTED_STUDENT_REQUIRED');
                    $identity->supplement('cognito','waiver',$w['source_locator'],'student',$student,$actor,false,[[$person,$student,$role]]);
                }
            }elseif($this->accepted($w,$role,$person)||$before['review_status']==='confirmed')throw new RuntimeException('ACCEPTED_ASSOCIATION_CANNOT_BE_REJECTED_HERE');
            $savedDigest=$this->digest($p,$this->waiver((int)$w['id']));
            $q=$this->db->prepare("INSERT INTO waiver_association_reviews(person_id,waiver_id,source_role,classification,review_status,evidence,source_digest,source_hash,reviewed_by,reviewed_at) VALUES (?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE review_status=VALUES(review_status),source_digest=VALUES(source_digest),reviewed_by=VALUES(reviewed_by),reviewed_at=VALUES(reviewed_at)");$q->execute([$person,$w['id'],$role,$before['classification'],$status,$before['evidence'],$savedDigest,$w['content_hash'],$actor]);
            $metadata=['person_id'=>$person,'waiver_id'=>(int)$w['id'],'role'=>$role,'previous_status'=>$previous['review_status']??null,'new_status'=>$status,'source_digest'=>$digest,'note'=>$note];
            $q=$this->db->prepare("INSERT INTO audit_log(actor_user_id,action,entity_type,entity_id,metadata) VALUES (?,'waiver_association_review','cognito_waivers',?,?)");$q->execute([$actor,(string)$w['id'],json_encode($metadata,JSON_THROW_ON_ERROR)]);
        });
    }
    /** Indexed aggregates only: directory loads never discover or scan waiver source fields. */
    public static function directorySql(PDO $db):array
    {
        $accepted="SELECT l.person_id,w.student_person_id target,l.source_role role FROM identity_links l JOIN cognito_waivers w ON w.source_locator=l.external_id WHERE l.source_system='cognito' AND l.source_type='waiver' AND l.source_role IN ('student','signer') AND l.match_status IN ('automatic','confirmed') AND ((l.source_role='student' AND w.student_person_id=l.person_id) OR (l.source_role='signer' AND w.signer_person_id=l.person_id))";
        $edges="SELECT person_id,related_person_id target,relationship_type role FROM person_relationships WHERE relationship_type IN ('payer','parent','guardian')";
        $verified="$accepted UNION ALL $edges";
        $proposed="SELECT NULL person_id,NULL target,NULL role WHERE 1=0";
        if(self::ready($db)){
            $verified.=" UNION ALL SELECT r.person_id,w.student_person_id target,r.source_role role FROM waiver_association_reviews r JOIN cognito_waivers w ON w.id=r.waiver_id AND w.content_hash=r.source_hash WHERE r.source_role='emergency' AND r.review_status='confirmed'";
            $proposed="SELECT r.person_id,w.student_person_id target,r.source_role role FROM waiver_association_reviews r JOIN cognito_waivers w ON w.id=r.waiver_id AND w.content_hash=r.source_hash WHERE r.review_status IN ('possible','held') AND r.classification<>'verified'";
        }
        $combined="SELECT person_id,target,role,MAX(verified) verified FROM (SELECT v.*,1 verified FROM ($verified) v UNION ALL SELECT v.*,0 verified FROM ($proposed) v) all_edges GROUP BY person_id,target,role";
        return ['summary'=>"(SELECT person_id,COUNT(DISTINCT target) people,COUNT(DISTINCT role) roles,COUNT(*) units,SUM(verified) verified,SUM(1-verified) proposed FROM ($combined) deduplicated GROUP BY person_id)"];
    }
}

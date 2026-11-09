<?php
declare(strict_types=1);
require_once __DIR__.'/MatchReviewService.php';
require_once __DIR__.'/BulkScoreMatchGroups.php';

/** Explicit administrator bulk review. No fuzzy scoring, background matching or schema changes. */
final class BulkExactMatchService
{
    private const LIMITS = ['stripe_customers'=>10000,'cognito_waivers'=>10000,'identity_match_candidates'=>1000,'identity_links'=>30000,'people'=>10000,'members'=>10000,'person_relationships'=>30000];
    public const EXACT='exact_name_email_v1';
    public const SCORE='score_above_half_v1';
    private array $scoreNames=[];
    public function __construct(private readonly PDO $db,private readonly string $policy=self::EXACT)
    {
        if(!in_array($policy,[self::EXACT,self::SCORE],true))throw new RuntimeException('INVALID_BULK_POLICY');
    }
    private function scoreMode(): bool { return $this->policy===self::SCORE; }
    public static function name(?string $value): string
    {
        return mb_strtolower(trim(preg_replace('/[\s\p{Z}]+/u',' ',(string)$value)??''),'UTF-8');
    }
    public static function personName(?string $value): string { return MatchScorer::comparisonName($value); }
    public static function email(?string $value): string
    {
        return mb_strtolower(preg_replace('/\A[\s\p{Z}]+|[\s\p{Z}]+\z/u','',(string)$value)??'','UTF-8');
    }
    private static function key(?string $name,?string $email): ?string
    {
        $n=self::personName($name);$e=self::email($email);
        return $n===''||$e===''||!filter_var($e,FILTER_VALIDATE_EMAIL)?null:hash('sha256',json_encode([$n,$e],JSON_THROW_ON_ERROR));
    }
    private function admin(int $actor,bool $lock=false): void
    {
        $q=$this->db->prepare("SELECT id FROM app_users WHERE id=? AND role='admin' AND is_active=1".($lock?' FOR UPDATE':''));$q->execute([$actor]);
        if(!$q->fetchColumn())throw new RuntimeException('ADMIN_REVIEW_REQUIRED');
    }
    /** SELECT-only consistent preview; even the plan fingerprint is not persisted in the database. */
    public function preview(int $actor,?array $selection=null): array
    {
        return $this->reviewState($actor,$selection)['plan'];
    }
    /** Approved identity fields for read-only grouped presentation; no raw source JSON. */
    public function reviewState(int $actor,?array $selection=null): array
    {
        if($this->db->inTransaction())throw new RuntimeException('BULK_TRANSACTION_REQUIRED');
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->db->beginTransaction();
        try{$this->admin($actor);$data=$this->load(false);$plan=$this->plan($data,$selection);$this->db->commit();return ['data'=>$data,'plan'=>$plan];}
        catch(Throwable $e){$this->db->rollBack();throw $e;}
    }
    /** Global per-database mutex plus ordered InnoDB row/gap locks protect concurrent bulk/import/manual changes. */
    public function confirm(int $actor,string $operation,string $expectedDigest,?array $selection=null): array
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$operation)||!preg_match('/\A[a-f0-9]{64}\z/D',$expectedDigest)||$this->db->inTransaction())throw new RuntimeException('INVALID_BULK_PREVIEW');
        $selection=$this->normalizeSelection($selection);
        return $this->reviewTransaction($actor,function(IdentityAssociations $identity)use($actor,$operation,$expectedDigest,$selection):array{
                $q=$this->db->prepare("SELECT actor_user_id,metadata FROM audit_log WHERE entity_type='identity_bulk_operations' AND entity_id=? AND action='identity_bulk_confirmed' ORDER BY id LIMIT 1 FOR UPDATE");$q->execute([$operation]);$receipt=$q->fetch();
                if($receipt){$meta=json_decode($receipt['metadata'],true,512,JSON_THROW_ON_ERROR);if(($meta['selection']??null)!==$selection||(int)$receipt['actor_user_id']!==$actor||($meta['policy']??self::EXACT)!==$this->policy||!hash_equals($meta['preview_digest'],$expectedDigest))throw new RuntimeException('INVALID_BULK_PREVIEW');return $meta['summary']+['replayed'=>true];}
                $plan=$this->plan($this->load(true),$selection);
                if(!hash_equals($expectedDigest,$plan['digest']))throw new RuntimeException('BULK_PREVIEW_STALE');
                if($plan['summary']['eligible_candidates']===0)throw new RuntimeException('BULK_NO_ELIGIBLE');
                $identity->setBulkContext($operation,$this->policy);
                $review=new MatchReviewService($this->db);
                foreach($plan['groups'] as $key=>$group){
                    $bma=$group['person_bma'];
                    foreach($group['decisions'] as $decision){
                        $review->decideInTransaction($identity,$decision['id'],'confirm_same',$actor,['person_bma'=>$bma??'','notes'=>$this->scoreMode()?'Administrator-approved persisted score above 0.50 bulk review.':'Administrator-approved exact name/email bulk review.'],['operation_id'=>$operation,'rule'=>$decision['rule'],'identity_group'=>$key,'preview_digest'=>$expectedDigest,'policy'=>$this->policy,'stored_score'=>$decision['score']??null]);
                        if($bma===null){$q=$this->db->prepare('SELECT p.bma_person_id FROM identity_match_candidates m JOIN stripe_customers c ON c.id=m.stripe_customer_id JOIN people p ON p.id=c.person_id WHERE m.id=?');$q->execute([$decision['id']]);$bma=$q->fetchColumn();if(!$bma)throw new RuntimeException('BULK_PERSON_RESOLUTION_FAILED');}
                    }
                }
                $q=$this->db->prepare('INSERT INTO audit_log(actor_user_id,action,entity_type,entity_id,metadata) VALUES (?,?,?,?,?)');
                $q->execute([$actor,'identity_bulk_confirmed','identity_bulk_operations',$operation,json_encode(['preview_digest'=>$expectedDigest,'summary'=>$plan['summary'],'policy'=>$this->policy,'selection'=>$selection],JSON_THROW_ON_ERROR)]);
                return $plan['summary']+['replayed'=>false];
        });
    }
    /** Shared mutex/transaction boundary for bulk and person-centered administrative decisions. */
    public function reviewTransaction(int $actor,callable $work): mixed
    {
        if($this->db->inTransaction())throw new RuntimeException('BULK_TRANSACTION_REQUIRED');
        $lockName='bma_bulk_exact_'.substr(hash('sha256',(string)$this->db->query('SELECT DATABASE()')->fetchColumn()),0,40);
        $q=$this->db->prepare('SELECT GET_LOCK(?,0)');$q->execute([$lockName]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('BULK_BUSY');
        try{$this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');return(new IdentityAssociations($this->db))->transaction(function(IdentityAssociations $identity)use($actor,$work){$this->admin($actor,true);return $work($identity);});}
        finally{$q=$this->db->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lockName]);}
    }
    public function lockedReviewState(): array
    {
        if(!$this->db->inTransaction())throw new RuntimeException('REVIEW_TRANSACTION_REQUIRED');
        $data=$this->load(true);return ['data'=>$data,'plan'=>$this->plan($data)];
    }
    public static function identityKey(?string $name,?string $email): ?string { return self::key($name,$email); }
    private function normalizeSelection(?array $selection): ?array
    {
        if($selection===null)return null;
        if(!array_is_list($selection)||count($selection)>1000)throw new RuntimeException('INVALID_BULK_SELECTION');
        foreach($selection as $id)if(!is_int($id)||$id<1)throw new RuntimeException('INVALID_BULK_SELECTION');
        $selection=array_values(array_unique($selection));sort($selection,SORT_NUMERIC);return $selection;
    }
    private function load(bool $lock): array
    {
        $fields=[
            'stripe_customers'=>'id,stripe_customer_id,name,email,email_normalized,address_line1,address_city,address_postal_code,person_id,updated_at',
            'cognito_waivers'=>'id,source_record_id,submitted_at,contact_phone,address_text,source_locator,source_name,signer_print_name,contact_email,source_format,source_name_role,relationship_to_student,student_birth_date,signer_person_id,student_person_id,updated_at',
            'identity_match_candidates'=>'id,stripe_customer_id,cognito_waiver_id,waiver_role,match_score,(match_score > 0.50) AS score_above_half,match_status,match_method,match_reason,evidence,hard_conflict,reviewed_by,reviewed_at,updated_at',
            'identity_links'=>'id,person_id,source_system,source_type,external_id,source_role,match_status,match_method,match_score,match_reason,match_evidence,reviewed_by,reviewed_at,updated_at',
            'people'=>'id,bma_person_id,first_name,middle_name,last_name,email,email_normalized,birth_date,phone,phone_normalized,street_address,address_line2,city,state,postal_code,country,updated_at',
            'members'=>'id,person_id,member_status,member_since,member_until,updated_at',
            'person_relationships'=>'id,person_id,related_person_id,relationship_type',
        ];$data=[];
        foreach($fields as $table=>$columns){$rows=$this->db->query('SELECT '.$columns.' FROM '.$table.' ORDER BY id LIMIT '.(self::LIMITS[$table]+1).($lock?' FOR UPDATE':''))->fetchAll();if(count($rows)>self::LIMITS[$table])throw new RuntimeException('BULK_SCOPE_TOO_LARGE');$data[$table]=$rows;}
        return $data;
    }
    private static function selfStudent(array $waiver,string $key): bool
    {
        return WaiverSourceRole::isStudent($waiver)&&self::name($waiver['relationship_to_student'])==='self'&&self::key($waiver['source_name'],$waiver['contact_email'])===$key;
    }
    private function selfStudentInGroup(array $waiver,string $key): bool
    {
        if(!$this->scoreMode())return self::selfStudent($waiver,$key);
        $n=$this->scoreNames[$key]??'';
        return $n!==''&&WaiverSourceRole::isStudent($waiver)&&self::name($waiver['relationship_to_student'])==='self'&&self::personName($waiver['source_name'])===$n&&self::personName($waiver['signer_print_name'])===$n;
    }
    /** Internal pure plan over a previously validated complete snapshot. */
    public function planForState(array $data,?array $selection=null): array { return $this->plan($data,$selection); }
    private function plan(array $data,?array $selection=null): array
    {
        $index=static fn(array $rows):array=>array_column($rows,null,'id');
        $customers=$index($data['stripe_customers']);$waivers=$index($data['cognito_waivers']);$people=$index($data['people']);$members=array_column($data['members'],'id','person_id');
        $customerKeys=[];$waiverKeys=[];$customerGroups=[];$waiverGroups=[];$personGroups=[];$links=[];
        foreach($customers as $id=>$c){$key=self::key($c['name'],$c['email_normalized']??$c['email']);$customerKeys[$id]=$key;if($key!==null)$customerGroups[$key][]=$id;}
        foreach($waivers as $id=>$w){$key=self::key($w['signer_print_name'],$w['contact_email']);$waiverKeys[$id]=$key;if($key!==null)$waiverGroups[$key][]=$id;}
        foreach($people as $id=>$person){$key=self::key(trim(implode(' ',[$person['first_name']??'',$person['middle_name']??'',$person['last_name']??''])),$person['email_normalized']??$person['email']);if($key!==null)$personGroups[$key][]=$id;}
        foreach($data['identity_links'] as $link)$links[$link['source_system'].'/'.$link['source_type'].'/'.$link['external_id'].'/'.$link['source_role']][]=$link;
        $groups=[];$excluded=[];$already=0;
        $score=null;
        if($this->scoreMode()){
            $score=BulkScoreMatchGroups::build($data,static fn(?string $name,?string $email):?string=>self::key($name,$email));
            foreach(['customerKeys','waiverKeys','customerGroups','waiverGroups','personGroups','groups','excluded','already'] as $var)$$var=$score[$var];
            $this->scoreNames=$score['names'];
        }else{
        foreach($data['identity_match_candidates'] as $c){
            $id=(int)$c['id'];$key=$customerKeys[$c['stripe_customer_id']]??null;$wk=$waiverKeys[$c['cognito_waiver_id']]??null;
            if(in_array($c['match_status'],['confirmed','linked'],true)){++$already;continue;}
            if($key===null||$wk===null){$excluded[$id]='missing_or_invalid_name_email';continue;}
            if($key!==$wk){$excluded[$id]='payer_signer_name_email_differ';continue;}
            $w=$waivers[$c['cognito_waiver_id']];$rule=$c['waiver_role']==='signer'?'A':($this->selfStudentInGroup($w,$key)?'B':null);
            if($rule===null){$excluded[$id]='student_rule_not_met';continue;}
            $groups[$key]['decisions'][]=['id'=>$id,'rule'=>$rule,'customer_id'=>(int)$c['stripe_customer_id'],'waiver_id'=>(int)$c['cognito_waiver_id']];
        }
        }
        $selection=$this->normalizeSelection($selection);
        if($selection!==null){
            $selected=array_fill_keys($selection,true);$found=0;$already=0;
            foreach($data['identity_match_candidates'] as $c)if(isset($selected[(int)$c['id']])){++$found;if(in_array($c['match_status'],['confirmed','linked'],true))++$already;}
            if($found!==count($selection))throw new RuntimeException('INVALID_BULK_SELECTION');
            foreach($groups as $key=>&$g){$g['decisions']=array_values(array_filter($g['decisions'],fn($d)=>isset($selected[$d['id']])));if($g['decisions']===[])unset($groups[$key]);}unset($g);
            $excluded=array_intersect_key($excluded,$selected);
            if($score!==null)$score['above']=count(array_filter($data['identity_match_candidates'],fn($c)=>isset($selected[(int)$c['id']])&&!empty($c['score_above_half'])));
        }
        $reasons=[];$summary=['total_candidates'=>$selection===null?count($data['identity_match_candidates']):count($selection),'eligible_candidates'=>0,'payer_signer_candidates'=>0,'self_student_candidates'=>0,'people_to_create'=>0,'people_to_reuse'=>0,'members_to_create'=>0,'additional_waivers'=>0,'associations_to_confirm'=>0,'excluded_candidates'=>0,'already_decided'=>$already,'remaining_manual'=>0];
        foreach($groups as $key=>&$g){
            $blocked=$score['reasons'][$key]??null;$ids=[];$scopes=[];$dobs=[];$hasStudent=false;
            foreach($g['decisions'] as $d){$hasStudent=$hasStudent||$d['rule']==='B';}
            foreach($customerGroups[$key] as $cid){$c=$customers[$cid];$scopes['stripe/customer/'.$c['stripe_customer_id'].'/customer']=$c['person_id'];}
            foreach($waiverGroups[$key] as $wid){$w=$waivers[$wid];$scopes['cognito/waiver/'.$w['source_locator'].'/signer']=$w['signer_person_id'];
                if($this->selfStudentInGroup($w,$key)){
                    // An explicit Self claim with a known different student identity is contradictory even for Rule A.
                    $scopes['cognito/waiver/'.$w['source_locator'].'/student']=$w['student_person_id'];
                    if(($hasStudent||$this->scoreMode()||$w['student_person_id']!==null)&&$w['student_birth_date']!==null)$dobs[$w['student_birth_date']]=true;
                }
            }
            foreach($scopes as $scope=>$cached){
                $accepted=[];
                foreach($links[$scope]??[] as $link){
                    if($link['match_status']==='rejected')$blocked??='manual_identity_rejection';
                    if(in_array($link['match_status'],['automatic','confirmed'],true))$accepted[]=(int)$link['person_id'];
                    if($link['match_status']==='possible'&&($link['reviewed_by']!==null||$link['reviewed_at']!==null))$blocked??='manual_identity_review_pending';
                }
                if(count($accepted)>1||($cached===null&&$accepted!==[])||($cached!==null&&$accepted!==[(int)$cached]))$blocked??='identity_cache_conflict';
                foreach($accepted as $person)$ids[$person]=true;
            }
            foreach($personGroups[$key]??[] as $person)$ids[(int)$person]=true;
            if(count($ids)>1)$blocked??='multiple_existing_people';
            $person=$ids===[]?null:(int)array_key_first($ids);$g['person_bma']=null;
            if($person!==null){
                $p=$people[$person]??null;
                if(!$p||$p['bma_person_id']===null)$blocked??='invalid_existing_person';
                else{
                    $g['person_bma']=$p['bma_person_id'];
                    $full=trim(implode(' ',[$p['first_name']??'',$p['middle_name']??'',$p['last_name']??'']));$sample=$customers[$g['decisions'][0]['customer_id']];
                    if((self::personName($full)!==''&&self::personName($full)!==self::personName($sample['name']))||(!$this->scoreMode()&&($email=self::email($p['email_normalized']??$p['email']))!==''&&$email!==self::email($sample['email_normalized']??$sample['email'])))$blocked??='existing_person_evidence_conflict';
                    if(($hasStudent||$dobs!==[])&&$p['birth_date']!==null)$dobs[$p['birth_date']]=true;
                }
            }
            if($person===null){$sample=$customers[$g['decisions'][0]['customer_id']];$parts=explode(' ',trim(preg_replace('/[\s\p{Z}]+/u',' ',(string)$sample['name'])??''));$first=array_shift($parts);$last=implode(' ',$parts);if($first===''||mb_strlen($first,'UTF-8')>100||mb_strlen($last,'UTF-8')>100)$blocked??='person_name_requires_manual';}
            if(count($dobs)>1)$blocked??='conflicting_birth_dates';
            if($hasStudent)foreach(array_keys($dobs) as $birth){$dob=new DateTimeImmutable($birth);$today=new DateTimeImmutable(AppTime::today());if($dob>$today||$dob->diff($today)->y<18)$blocked??='self_student_age_conflict';}
            foreach($data['identity_match_candidates'] as $c){
                $w=$waivers[$c['cognito_waiver_id']]??null;if(!$w)continue;
                $relevant=($waiverKeys[$w['id']]??null)===$key&&($c['waiver_role']==='signer'||$this->selfStudentInGroup($w,$key));
                if(!$relevant)continue;
                if($c['match_status']==='rejected'||$c['match_reason']==='manual_review_pending'||(!in_array($c['match_status'],['confirmed','linked'],true)&&($c['reviewed_by']!==null||$c['reviewed_at']!==null||str_starts_with((string)$c['match_reason'],'manual_'))))$blocked??='manual_candidate_decision';
                if(in_array($c['match_status'],['confirmed','linked'],true)){
                    $payer=$customers[$c['stripe_customer_id']]['person_id']??null;$other=$c['waiver_role']==='signer'?$w['signer_person_id']:$w['student_person_id'];
                    if($payer===null||$other===null||(int)$payer!==(int)$other)$blocked??='confirmed_candidate_link_conflict';
                }
            }
            foreach($g['decisions'] as $d){
                $c=null;foreach($data['identity_match_candidates'] as $item)if((int)$item['id']===$d['id']){$c=$item;break;}
                if(!in_array($c['match_status'],['possible','unmatched'],true))$blocked??='candidate_conflict';
                $e=json_decode((string)$c['evidence'],true);$conflicts=is_array($e)?($e['conflicts']??[]):null;
                $allowed=$d['rule']==='B'?['student_identity_unverified','source_name_role_unverified']:[];
                if(!is_array($conflicts)||array_diff($conflicts,$allowed)!==[]||((bool)$c['hard_conflict']&&($conflicts===[]||$d['rule']!=='B')))$blocked??='hard_or_contradictory_evidence';
            }
            $g['person_id']=$person;$g['student_dobs']=array_keys($dobs);
            if($blocked!==null){foreach($g['decisions'] as $d)$excluded[$d['id']]=$blocked;$g['excluded_reason']=$blocked;continue;}
        }unset($g);
        foreach($groups as $key=>$g)if(isset($g['excluded_reason']))unset($groups[$key]);
        // Compatible accepted people may span different email groups. Count and validate them globally.
        $personKeys=[];$personDobs=[];
        foreach($groups as $key=>$g)if($g['person_id']!==null){$personKeys[$g['person_id']][]=$key;foreach($g['student_dobs'] as $dob)$personDobs[$g['person_id']][$dob]=true;}
        foreach($personDobs as $id=>$dates){
            $reason=count($dates)>1?'conflicting_birth_dates':null;$student=false;
            foreach($personKeys[$id] as $key)foreach($groups[$key]['decisions'] as $d)if($d['rule']==='B')$student=true;
            if($student&&$reason===null)foreach(array_keys($dates) as $birth){$dob=new DateTimeImmutable($birth);$today=new DateTimeImmutable(AppTime::today());if($dob>$today||$dob->diff($today)->y<18)$reason='self_student_age_conflict';}
            if($reason!==null)foreach($personKeys[$id] as $key){foreach($groups[$key]['decisions'] as $d)$excluded[$d['id']]=$reason;unset($groups[$key]);}
        }
        foreach(['eligible_candidates','payer_signer_candidates','self_student_candidates','people_to_create','people_to_reuse','members_to_create','additional_waivers','associations_to_confirm'] as $metric)$summary[$metric]=0;
        $reused=[];$newMembers=[];$identityWaivers=[];$allScopes=[];
        foreach($groups as $key=>$g){
            $identity=$g['person_id']===null?'group:'.$key:'person:'.$g['person_id'];
            if($g['person_id']===null)$summary['people_to_create']++;else $reused[$g['person_id']]=true;
            foreach($g['decisions'] as $d){
                $summary['eligible_candidates']++;$summary[$d['rule']==='A'?'payer_signer_candidates':'self_student_candidates']++;
                $w=$waivers[$d['waiver_id']];$c=$customers[$d['customer_id']];$identityWaivers[$identity][$w['id']]=true;
                $allScopes['stripe/customer/'.$c['stripe_customer_id'].'/customer']=true;$allScopes['cognito/waiver/'.$w['source_locator'].'/signer']=true;
                if($d['rule']==='B'){$allScopes['cognito/waiver/'.$w['source_locator'].'/student']=true;}
            }
        }
        $summary['people_to_reuse']=count($reused);$summary['members_to_create']=count($newMembers);
        foreach($identityWaivers as $submissions)$summary['additional_waivers']+=max(0,count($submissions)-1);
        foreach($allScopes as $scope=>$_){$confirmed=false;foreach($links[$scope]??[] as $link)if($link['match_status']==='confirmed')$confirmed=true;if(!$confirmed)$summary['associations_to_confirm']++;}
        foreach($excluded as $reason)$reasons[$reason]=($reasons[$reason]??0)+1;ksort($reasons);ksort($groups);
        $summary['excluded_candidates']=count($excluded);$summary['remaining_manual']=0;foreach($data['identity_match_candidates'] as $c)if(isset($excluded[(int)$c['id']])&&$c['match_status']!=='rejected')$summary['remaining_manual']++;
        $reviewGroups=[];
        if($this->scoreMode()){
            $summary['score_above_half']=$score['above'];$summary['score_qualified_excluded']=0;
            foreach($data['identity_match_candidates'] as $c){$id=(int)$c['id'];if(!empty($c['score_above_half'])&&isset($excluded[$id])){
                $summary['score_qualified_excluded']++;
                $component=$customerKeys[$c['stripe_customer_id']]??('candidate:'.$id);
                if(in_array($excluded[$id],['student_role_separation','legacy_source_role_ambiguous'],true))$component='role:'.$c['cognito_waiver_id'].':'.$c['waiver_role'];
                $group=hash('sha256',$component.':'.$excluded[$id]);$reviewGroups[$group]['reason']=$excluded[$id];$reviewGroups[$group]['candidate_ids'][]=$id;
            }}
            $summary['excluded_groups']=count($reviewGroups);
        }
        return ['policy'=>$this->policy,'digest'=>hash('sha256',json_encode(['policy'=>$this->policy,'today'=>AppTime::today(),'data'=>$data,'selection'=>$selection],JSON_THROW_ON_ERROR)),'summary'=>$summary,'exclusions'=>$reasons,'groups'=>$groups,'review_groups'=>array_values($reviewGroups)];
    }
}

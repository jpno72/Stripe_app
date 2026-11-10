<?php
declare(strict_types=1);
require_once __DIR__.'/StripeAssociationRepository.php';
require_once dirname(__DIR__,2).'/src/IdentityAssociations.php';
require_once dirname(__DIR__,2).'/src/People.php';
require_once dirname(__DIR__).'/Security/DashboardPermissionService.php';
require_once dirname(__DIR__,2).'/src/AppTime.php';

/** Reviewed local associations only. Never writes imported financial source fields. */
final class StripeAssociationService
{
    public function __construct(private PDO $db) {}
    private function rows(string $sql,array $p=[]):array{$q=$this->db->prepare($sql);$q->execute($p);return $q->fetchAll();}
    private function actor(int $actor):void
    {
        $u=$this->rows('SELECT id,role,is_active FROM app_users WHERE id=?',[$actor])[0]??null;
        if(!$u||!$u['is_active']||$u['role']!=='admin'||!(new DashboardPermissionService($this->db))->canViewDashboard($u,'members'))throw new RuntimeException('ADMIN_MEMBERS_PERMISSION_REQUIRED');
    }
    public static function request(array $input):array
    {
        $r=[];foreach(['identifier','action','role','target_bma','new_first_name','new_last_name','reason','mapping_id','new_payer','acknowledge'] as $key){$v=$input[$key]??'';if(!is_string($v)||mb_strlen($v)>2000)throw new InvalidArgumentException('Invalid association request.');$r[$key]=trim(str_replace(["\r\n","\r"],"\n",$v));}
        if(!preg_match('/\A(cus_|gcus_|sub_|pi_|ch_|py_)[A-Za-z0-9]{1,240}\z/D',$r['identifier']))throw new InvalidArgumentException('Invalid Stripe identifier.');
        if(!in_array($r['action'],['link','remove'],true)||!in_array($r['role'],['own','payer'],true))throw new InvalidArgumentException('Invalid association action or role.');
        if(mb_strlen($r['reason'])<3)throw new InvalidArgumentException('A reason is required.');
        foreach(['new_payer','acknowledge'] as $key)if(!in_array($r[$key],['','1'],true))throw new InvalidArgumentException('Invalid confirmation.');
        if($r['mapping_id']!==''&&!ctype_digit($r['mapping_id']))throw new InvalidArgumentException('Invalid assignment.');
        return $r;
    }
    private function person(int $id):array
    {
        return $this->rows('SELECT id,bma_person_id,first_name,last_name FROM people WHERE id=?',[$id])[0]??throw new RuntimeException('PERSON_NOT_FOUND');
    }
    public function preview(int $actor,int $subject,array $input):array
    {
        $this->actor($actor);$r=self::request($input);$person=$this->person($subject);$kind=str_starts_with($r['identifier'],'sub_')?'subscription':(preg_match('/\A(?:pi_|ch_|py_)/',$r['identifier'])?'transaction':'customer');
        if($kind==='subscription'&&$r['role']!=='own')throw new RuntimeException('SELECT_MEMBER_ASSIGNMENT_ROLE_SUBSCRIPTION_OWNER_UNCHANGED');
        $target=$person;
        if($r['new_payer']==='1'){
            if($kind!=='customer'||$r['role']!=='payer'||$r['action']!=='link')throw new RuntimeException('NEW_PAYER_ONLY_FOR_CUSTOMER');
            foreach(['new_first_name','new_last_name'] as $key)if($r[$key]===''||mb_strlen($r[$key])>100)throw new RuntimeException('PAYER_NAME_REQUIRED');
            if($this->rows('SELECT id FROM people WHERE LOWER(TRIM(first_name))=LOWER(?) AND LOWER(TRIM(last_name))=LOWER(?) LIMIT 1',[$r['new_first_name'],$r['new_last_name']])!==[])throw new RuntimeException('SELECT_EXISTING_PAYER_OR_REVIEW_DUPLICATE_NAME');
            $target=['id'=>null,'bma_person_id'=>null,'first_name'=>$r['new_first_name'],'last_name'=>$r['new_last_name']];
        }elseif($r['target_bma']!==''){
            if(!preg_match('/\ABMA-[0-9]{1,25}\z/D',$r['target_bma']))throw new RuntimeException('INVALID_BMA_IDENTIFIER');
            $found=$this->rows('SELECT id FROM people WHERE bma_person_id=?',[$r['target_bma']])[0]??throw new RuntimeException('TARGET_PERSON_NOT_FOUND');$target=$this->person((int)$found['id']);
        }
        if($kind==='customer'&&$r['role']==='own'&&(int)$target['id']!==$subject)throw new RuntimeException('OWN_ACCOUNT_MUST_BELONG_TO_PROFILE_PERSON');
        if($kind==='customer'&&$r['role']==='payer'&&$target['id']!==null&&(int)$target['id']===$subject)throw new RuntimeException('PAYER_AND_STUDENT_MUST_BE_SEPARATE');
        $conflicts=[];$details=[];
        if($kind==='customer'){
            $record=$this->rows('SELECT id,stripe_customer_id,person_id,name,email,is_stub,updated_at FROM stripe_customers WHERE stripe_customer_id=?',[$r['identifier']])[0]??throw new RuntimeException('IMPORTED_CUSTOMER_NOT_FOUND');
            $links=$this->rows("SELECT id,person_id,match_status,reviewed_by,reviewed_at,updated_at FROM identity_links WHERE source_system='stripe' AND source_type='customer' AND source_role='customer' AND external_id=? ORDER BY id",[$r['identifier']]);
            $relations=$this->rows("SELECT id,person_id,related_person_id,relationship_type FROM person_relationships WHERE related_person_id=? AND relationship_type='payer' ORDER BY id",[$subject]);
            foreach($links as $link)if((in_array($link['match_status'],['automatic','confirmed'],true)&&(int)$link['person_id']!==(int)$target['id'])||($link['match_status']==='rejected'&&(int)$link['person_id']===(int)$target['id']))$conflicts[]='An accepted owner or a manual rejection already exists. Explicit conflict resolution is required.';
            if($record['person_id']!==null&&(int)$record['person_id']!==(int)$target['id'])$conflicts[]='The recorded customer owner differs from the intended person. Confirm the displayed ownership correction explicitly.';
            if($record['person_id']!==null)$details['existing_owner']=$this->person((int)$record['person_id']);
            if($r['action']==='remove'&&($target['id']===null||($r['role']==='own'?(int)$record['person_id']!==$subject:!array_filter($relations,static fn($v)=>(int)$v['person_id']===(int)$target['id']))))throw new RuntimeException('ASSOCIATION_NOT_ON_THIS_PROFILE');
            $state=[$record,$links,$relations];
        }elseif($kind==='subscription'){
            $record=$this->rows('SELECT s.id,s.stripe_subscription_id,s.customer_id,s.status,s.stripe_subscription_started_at,s.canceled_at,s.current_period_start,s.current_period_end,c.stripe_customer_id,c.person_id owner_person_id,c.name customer_name,c.email customer_email FROM stripe_subscriptions s JOIN stripe_customers c ON c.id=s.customer_id WHERE s.stripe_subscription_id=?',[$r['identifier']])[0]??throw new RuntimeException('IMPORTED_SUBSCRIPTION_NOT_FOUND');
            $maps=$this->rows('SELECT map.id,map.member_id,map.valid_from,map.valid_until,m.person_id,p.bma_person_id FROM subscription_members map JOIN members m ON m.id=map.member_id JOIN people p ON p.id=m.person_id WHERE map.subscription_id=? ORDER BY map.id',[$record['id']]);
            $details['items']=$this->rows('SELECT product_name,stripe_price_id,amount_minor,currency,billing_interval,billing_interval_count,quantity FROM vw_subscription_item_rates WHERE subscription_id=? ORDER BY subscription_item_id',[$record['id']]);
            $member=$this->rows('SELECT id FROM members WHERE person_id=?',[$target['id']])[0]??null;
            if($r['action']==='link'&&$member===null)throw new RuntimeException('EXISTING_MEMBER_REQUIRED_NO_AUTOMATIC_ENROLLMENT');
            $details['target_member_id']=$member['id']??null;$details['assignments']=$maps;
            if($r['mapping_id']!==''||$r['action']==='remove'){
                $selected=array_values(array_filter($maps,static fn($v)=>(int)$v['id']===(int)$r['mapping_id']));
                if(count($selected)!==1||(int)$selected[0]['person_id']!==$subject||!self::current($selected[0]))throw new RuntimeException('CURRENT_PROFILE_ASSIGNMENT_REQUIRED');
                $details['selected_assignment']=$selected[0];
            }
            foreach($maps as $map)if(self::current($map)&&(int)$map['person_id']!==(int)$target['id'])$conflicts[]='Subscription already has another current member assignment. Confirm the displayed correction or shared subscription; other students are preserved.';
            $state=[$record,$maps,$details];
        }else{
            if(!StripeAssociationRepository::attributionReady($this->db))throw new RuntimeException('PAYMENT_ATTRIBUTION_REQUIRES_MIGRATION_012');
            $field=str_starts_with($r['identifier'],'pi_')?'stripe_payment_intent_id':'stripe_charge_id';
            $records=$this->rows("SELECT t.id,t.stripe_charge_id,t.stripe_payment_intent_id,t.amount_minor,t.amount_refunded_minor,t.currency,t.status,t.stripe_created_at,t.description,c.stripe_customer_id,c.person_id owner_person_id FROM stripe_transactions t LEFT JOIN stripe_customers c ON c.id=t.customer_id WHERE t.$field=?",[$r['identifier']]);
            if(count($records)!==1)throw new RuntimeException('PAYMENT_NOT_FOUND_OR_AMBIGUOUS_USE_CHARGE_ID');$record=$records[0];
            $allocation=$this->rows('SELECT * FROM stripe_transaction_attributions WHERE transaction_id=?',[$record['id']])[0]??null;$details['allocation']=$allocation;if($allocation!==null)$details['allocation_person']=$this->person((int)$allocation['person_id']);
            if(($allocation!==null&&(int)$allocation['person_id']!==(int)$target['id'])||($record['owner_person_id']!==null&&(int)$record['owner_person_id']!==(int)$target['id']))$conflicts[]='The source account owner or current allocation is another person. This changes only the explicit allocation, never source ownership.';
            if($r['action']==='remove'&&($allocation===null||(int)$allocation['person_id']!==$subject))throw new RuntimeException('ATTRIBUTION_NOT_ON_THIS_PROFILE');
            $state=[$record,$allocation];
        }
        $p=['kind'=>$kind,'subject'=>$person,'target'=>$target,'request'=>$r,'record'=>$record,'details'=>$details,'conflicts'=>array_values(array_unique($conflicts))];
        $scope=$r;unset($scope['acknowledge']);$p['digest']=hash('sha256',serialize([$actor,$subject,$scope,$target,$state]));return $p;
    }
    private static function current(array $m):bool{return ($m['valid_from']===null||$m['valid_from']<=AppTime::today())&&($m['valid_until']===null||$m['valid_until']>=AppTime::today());}
    public function apply(int $actor,int $subject,array $input,string $digest,string $operation):array
    {
        $this->actor($actor);$r=self::request($input);if(!preg_match('/\A[a-f0-9]{32}\z/D',$operation)||!preg_match('/\A[a-f0-9]{64}\z/D',$digest))throw new RuntimeException('INVALID_CONFIRMATION');
        $hash=hash('sha256',serialize([$actor,$subject,$r,$digest]));$lock='bma_stripe_review_'.substr(hash('sha256',(string)$this->db->query('SELECT DATABASE()')->fetchColumn()),0,32);
        if((int)$this->rows('SELECT GET_LOCK(?,0) acquired',[$lock])[0]['acquired']!==1)throw new RuntimeException('ANOTHER_ASSOCIATION_REVIEW_IS_RUNNING');
        try{return (new IdentityAssociations($this->db))->transaction(function(IdentityAssociations $identity)use($actor,$subject,$r,$digest,$operation,$hash):array{
            $this->rows('SELECT id FROM app_users WHERE id=? FOR UPDATE',[$actor]);$this->actor($actor);
            $receipt=$this->rows("SELECT metadata FROM audit_log WHERE entity_type='application_security' AND entity_id=? AND action='profile_stripe_association' ORDER BY id LIMIT 1",[$operation])[0]??null;
            if($receipt!==null){$saved=json_decode($receipt['metadata'],true,512,JSON_THROW_ON_ERROR);if(!hash_equals($saved['request_hash'],$hash))throw new RuntimeException('OPERATION_ALREADY_USED');return $saved['result'];}
            // Lock the stable source record before comparing the read-only confirmation snapshot.
            $field=str_starts_with($r['identifier'],'sub_')?'stripe_subscription_id':(preg_match('/\A(?:cus_|gcus_)/',$r['identifier'])?'stripe_customer_id':(str_starts_with($r['identifier'],'pi_')?'stripe_payment_intent_id':'stripe_charge_id'));
            $table=match($field){'stripe_subscription_id'=>'stripe_subscriptions','stripe_customer_id'=>'stripe_customers',default=>'stripe_transactions'};
            $this->rows("SELECT id FROM $table WHERE $field=? FOR UPDATE",[$r['identifier']]);
            $p=$this->preview($actor,$subject,$r);if(!hash_equals($digest,$p['digest']))throw new RuntimeException('PREVIEW_CHANGED_REVIEW_AGAIN');
            if($p['conflicts']!==[]&&$r['acknowledge']!=='1')throw new RuntimeException('EXPLICIT_CONFLICT_CONFIRMATION_REQUIRED');
            $target=$p['target'];if($target['id']===null){$target=(new People($this->db))->createInTransaction($r['new_first_name'],$r['new_last_name']);}
            $targetId=(int)$target['id'];$before=$this->associationState($p,$subject);$record=$p['record'];
            if($p['kind']==='customer'){
                if($r['action']==='remove'){
                    if($r['role']==='payer')$identity->removeReviewedRelationship([$targetId,$subject,'payer'],$actor);
                    else $identity->decide('stripe','customer',$r['identifier'],'customer',$subject,'rejected',$actor);
                }else{
                    $relationships=$r['role']==='payer'?[[$targetId,$subject,'payer']]:[];
                    $confirmed=$this->rows("SELECT id FROM identity_links WHERE source_system='stripe' AND source_type='customer' AND source_role='customer' AND external_id=? AND person_id=? AND match_status='confirmed'",[$r['identifier'],$targetId]);
                    if($confirmed===[]||(int)$record['person_id']!==$targetId)$identity->decide('stripe','customer',$r['identifier'],'customer',$targetId,'confirmed',$actor,false,$relationships);
                    elseif($relationships!==[])$identity->supplement('stripe','customer',$r['identifier'],'customer',$targetId,$actor,false,$relationships);
                }
            }elseif($p['kind']==='subscription'){
                if(isset($p['details']['selected_assignment']))$this->endAssignment($p['details']['selected_assignment']);
                if($r['action']==='link'){
                    $member=(int)$p['details']['target_member_id'];$maps=$this->rows('SELECT id,valid_from,valid_until FROM subscription_members WHERE subscription_id=? AND member_id=? FOR UPDATE',[$record['id'],$member]);
                    if(!array_filter($maps,self::current(...)))$this->db->prepare('INSERT INTO subscription_members(subscription_id,member_id,valid_from,notes) VALUES (?,?,?,?)')->execute([$record['id'],$member,AppTime::today(),'Reviewed profile association']);
                }
            }else{
                if($r['action']==='remove')$this->db->prepare('DELETE FROM stripe_transaction_attributions WHERE transaction_id=?')->execute([$record['id']]);
                else $this->db->prepare('INSERT INTO stripe_transaction_attributions(transaction_id,person_id,attribution_role,verified_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE person_id=VALUES(person_id),attribution_role=VALUES(attribution_role),verified_by=VALUES(verified_by),verified_at=UTC_TIMESTAMP(6)')->execute([$record['id'],$targetId,$r['role']==='payer'?'payer':'person',$actor]);
            }
            $result=['person_id'=>$targetId,'kind'=>$p['kind'],'action'=>$r['action'],'identifier'=>$r['identifier']];
            Security::audit($this->db,$actor,'profile_stripe_association',$operation,['request_hash'=>$hash,'subject_person_id'=>$subject,'reason'=>$r['reason'],'role'=>$r['role'],'before'=>$before,'after'=>$this->associationState($p,$subject),'result'=>$result]);return $result;
        });}finally{$this->rows('SELECT RELEASE_LOCK(?) released',[$lock]);}
    }
    private function endAssignment(array $m):void
    {
        if($m['valid_from']!==null&&$m['valid_from']>=AppTime::today())$this->db->prepare('DELETE FROM subscription_members WHERE id=?')->execute([$m['id']]);
        else $this->db->prepare('UPDATE subscription_members SET valid_until=? WHERE id=?')->execute([AppTime::now()->modify('-1 day')->format('Y-m-d'),$m['id']]);
        // Complete before/after snapshots are kept in the immutable review audit, including same-day corrections.
    }
    private function associationState(array $p,int $subject):array
    {
        if($p['kind']==='customer')return ['links'=>$this->rows("SELECT id,person_id,match_status,match_method,match_score,match_reason,match_evidence,reviewed_by,reviewed_at,updated_at FROM identity_links WHERE source_system='stripe' AND source_type='customer' AND source_role='customer' AND external_id=? ORDER BY id",[$p['record']['stripe_customer_id']]),'cached_owner'=>$this->rows('SELECT person_id FROM stripe_customers WHERE id=?',[$p['record']['id']]),'payers'=>$this->rows("SELECT person_id,related_person_id FROM person_relationships WHERE related_person_id=? AND relationship_type='payer' ORDER BY id",[$subject])];
        if($p['kind']==='subscription')return $this->rows('SELECT id,subscription_id,member_id,valid_from,valid_until,notes,created_at,updated_at FROM subscription_members WHERE subscription_id=? ORDER BY id',[$p['record']['id']]);
        return $this->rows('SELECT * FROM stripe_transaction_attributions WHERE transaction_id=?',[$p['record']['id']]);
    }
    public function links(int $actor,int $person):array
    {
        $this->actor($actor);$out=[];
        $rows=$this->rows("SELECT c.stripe_customer_id identifier,c.person_id owner_id,p.bma_person_id,l.match_status,CASE WHEN c.person_id=? THEN 'own' ELSE 'payer' END role FROM stripe_customers c JOIN identity_links l ON l.source_system='stripe' AND l.source_type='customer' AND l.source_role='customer' AND l.external_id=c.stripe_customer_id AND l.person_id=c.person_id AND l.match_status IN ('automatic','confirmed') JOIN people p ON p.id=l.person_id WHERE c.person_id=? OR EXISTS(SELECT 1 FROM person_relationships r WHERE r.person_id=c.person_id AND r.related_person_id=? AND r.relationship_type='payer') ORDER BY c.id",[$person,$person,$person]);
        foreach($rows as $r)$out[]=$r+['kind'=>'customer','mapping_id'=>'','source'=>'Accepted identity / payer relationship'];
        foreach($this->rows('SELECT s.stripe_subscription_id identifier,map.id mapping_id,p.bma_person_id,map.valid_from,map.valid_until FROM subscription_members map JOIN stripe_subscriptions s ON s.id=map.subscription_id JOIN members m ON m.id=map.member_id JOIN people p ON p.id=m.person_id WHERE p.id=? ORDER BY map.id',[$person]) as $r){$r['match_status']=self::current($r)?'confirmed':'historical';$out[]=$r+['kind'=>'subscription','role'=>'own','source'=>'Explicit member assignment'];}
        if(StripeAssociationRepository::attributionReady($this->db))foreach($this->rows('SELECT t.stripe_charge_id identifier,a.attribution_role,p.bma_person_id FROM stripe_transaction_attributions a JOIN stripe_transactions t ON t.id=a.transaction_id JOIN people p ON p.id=a.person_id WHERE a.person_id=? ORDER BY t.id',[$person]) as $r)$out[]=$r+['kind'=>'transaction','role'=>$r['attribution_role']==='payer'?'payer':'own','mapping_id'=>'','match_status'=>'confirmed','source'=>'Reviewed direct allocation; source ownership unchanged'];
        return $out;
    }
}

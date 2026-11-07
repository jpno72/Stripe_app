<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/Matching/BulkExactMatchService.php';
require_once dirname(__DIR__).'/Matching/IdentityReviewGroups.php';

/** Existing identity approvals are immediate. This workflow reuses them; it never invents staged approvals. */
final class PeopleMemberFinalizationService
{
    private BulkExactMatchService $bulk;
    public function __construct(private readonly PDO $db){$this->bulk=new BulkExactMatchService($db,BulkExactMatchService::SCORE);}
    public static function choices(array $input): array
    {
        if(count($input)>1000)throw new RuntimeException('FINALIZATION_TOO_LARGE');$out=[];
        foreach($input as $person=>$v){if(!ctype_digit((string)$person)||(int)$person<1||!is_array($v))throw new RuntimeException('INVALID_FINALIZATION');if(($v['include']??'')!=='1')continue;
            $kind=is_string($v['kind']??null)?$v['kind']:'';$note=is_string($v['evidence']??null)?trim($v['evidence']):'';
            if(!in_array($kind,['undetermined','waiver_only','trial_participant','unknown','active','inactive','former','trial'],true)||$note===''||mb_strlen($note)>2000)throw new RuntimeException('MEMBERSHIP_EVIDENCE_REQUIRED');
            $established=($v['established']??'')==='1';if($established!==in_array($kind,['unknown','active','inactive','former','trial'],true))throw new RuntimeException('MEMBERSHIP_EVIDENCE_REQUIRED');
            $out[(int)$person]=['kind'=>$kind,'evidence'=>$note,'established'=>$established];
        }ksort($out,SORT_NUMERIC);return $out;
    }
    private static function validate(array $choices): void
    {
        $raw=[];foreach($choices as $person=>$v){if(!is_array($v)||!is_bool($v['established']??null))throw new RuntimeException('INVALID_FINALIZATION');$raw[$person]=['include'=>'1','kind'=>$v['kind']??null,'evidence'=>$v['evidence']??null,'established'=>$v['established']?'1':''];}
        if(self::choices($raw)!==$choices)throw new RuntimeException('INVALID_FINALIZATION');
    }
    public function preview(int $actor,array $choices=[]): array
    {
        self::validate($choices);return $this->bulk->reviewTransaction($actor,fn(IdentityAssociations $identity)=>$this->plan($this->bulk->lockedReviewState(),$choices));
    }
    private function plan(array $state,array $choices): array
    {
        $data=$state['data'];$projection=IdentityReviewGroups::build($state);$people=array_column($data['people'],null,'id');$customers=array_column($data['stripe_customers'],null,'stripe_customer_id');$waivers=array_column($data['cognito_waivers'],null,'source_locator');$members=array_column($data['members'],null,'person_id');$blocked=[];$groupIds=[];$waiverGroups=[];
        foreach($projection['groups'] as $g){foreach($g['waiver_records'] as $w){$waiverGroups[$w['source_locator']][$g['id']]=$g['status'];if(!in_array($g['status'],['confirmed','rejected'],true))$blocked[$w['source_locator']]=true;}if($g['status']==='confirmed')$groupIds[$g['id']]=true;}
        foreach($data['identity_match_candidates'] as $c)if($c['match_reason']==='manual_review_pending')foreach($waivers as $w)if((int)$w['id']===(int)$c['cognito_waiver_id'])$blocked[$w['source_locator']]=true;
        foreach($data['identity_links'] as $l)if($l['source_system']==='cognito'&&($l['match_status']==='rejected'||($l['match_status']==='possible'&&$l['reviewed_at']!==null)))$blocked[$l['external_id']]=true;
        $events=$this->db->query("SELECT id,entity_id,metadata FROM audit_log WHERE entity_type='membership_review' AND action='membership_reviewed' ORDER BY id DESC LIMIT 30001 FOR UPDATE")->fetchAll();if(count($events)>30000)throw new RuntimeException('FINALIZATION_TOO_LARGE');$latest=[];
        foreach($events as $event)if(!isset($latest[(int)$event['entity_id']]))$latest[(int)$event['entity_id']]=json_decode($event['metadata'],true,512,JSON_THROW_ON_ERROR);
        $rows=[];$excluded=[];foreach($projection['groups'] as $g)if($g['needs_decision'])$excluded['group/'.$g['id']]='Unresolved identity group: '.$g['status'];$scopeCounts=['stripe'=>0,'signer'=>0,'student'=>0];
        foreach($data['identity_links'] as $l){if($l['match_status']!=='confirmed'||$l['reviewed_by']===null||$l['reviewed_at']===null)continue;$pid=(int)$l['person_id'];if(!isset($people[$pid]))continue;
            if($l['source_system']==='stripe'&&$l['source_type']==='customer'&&$l['source_role']==='customer'){$record=$customers[$l['external_id']]??null;$valid=$record!==null&&(int)$record['person_id']===$pid;$role='stripe';}
            elseif($l['source_system']==='cognito'&&$l['source_type']==='waiver'&&in_array($l['source_role'],['signer','student'],true)){$record=$waivers[$l['external_id']]??null;$valid=$record!==null&&(int)$record[$l['source_role'].'_person_id']===$pid&&!isset($blocked[$l['external_id']]);$role=$l['source_role'];}
            else continue;
            if(!$valid){$excluded[$l['source_system'].'/'.$l['external_id'].'/'.$l['source_role']]='Conflicting, held or inconsistent association';continue;}
            if(!isset($rows[$pid]))$rows[$pid]=['person_id'=>$pid,'bma_person_id'=>$people[$pid]['bma_person_id'],'name'=>trim(implode(' ',[$people[$pid]['first_name'],$people[$pid]['middle_name'],$people[$pid]['last_name']])),'roles'=>[],'stripe_accounts'=>[],'waivers'=>[],'student_waivers'=>[],'existing_member'=>$members[$pid]??null,'membership_review'=>$latest[$pid]??null];
            $rows[$pid]['roles'][$role]=true;++$scopeCounts[$role];
            if($role==='stripe')$rows[$pid]['stripe_accounts'][]=$l['external_id'];else{$rows[$pid]['waivers'][$record['id']]=['id'=>$record['id'],'source_record_id'=>$record['source_record_id'],'role'=>$role];if($role==='student')$rows[$pid]['student_waivers'][]=$l['external_id'];}
        }
        $summary=['approved_groups_awaiting_finalization'=>0,'people_to_create'=>0,'people_to_reuse'=>count($rows),'stripe_associations_to_establish'=>0,'signer_associations_to_establish'=>0,'student_associations_to_establish'=>0,'relationships_to_establish'=>0,'members_to_create'=>0,'members_to_update'=>0,'membership_needs_review'=>0,'participants_without_membership'=>0,'trial_or_waiver_only'=>0,'records_still_requiring_review'=>count($excluded),'approved_stripe_associations'=>$scopeCounts['stripe'],'approved_signer_associations'=>$scopeCounts['signer'],'approved_student_associations'=>$scopeCounts['student'],'existing_verified_relationships'=>0,'skipped'=>0];$pendingGroups=[];
        foreach($rows as $pid=>&$row){$row['eligible_student']=$row['student_waivers']!==[];$row['needs_membership_review']=$row['eligible_student']&&($row['membership_review']===null||($row['membership_review']['decision']['kind']??'')==='undetermined');
            if($row['needs_membership_review']){++$summary['membership_needs_review'];foreach($row['student_waivers'] as $locator){$found=false;foreach($waiverGroups[$locator]??[] as $gid=>$status)if($status==='confirmed'){$pendingGroups[$gid]=true;$found=true;}if(!$found)$pendingGroups['verified-student:'.$pid]=true;}}
            if($row['eligible_student']&&$row['existing_member']===null){++$summary['participants_without_membership'];if(in_array($row['membership_review']['decision']['kind']??'', ['trial_participant','waiver_only'],true))++$summary['trial_or_waiver_only'];}
        }unset($row);$summary['approved_groups_awaiting_finalization']=count($pendingGroups);
        $relationships=[];foreach($data['person_relationships'] as $rel)if(isset($rows[$rel['person_id']],$rows[$rel['related_person_id']])){$relationships[]=$rel;++$summary['existing_verified_relationships'];}
        $changes=[];$errors=[];
        foreach($choices as $pid=>$decision){if(!isset($rows[$pid])||!$rows[$pid]['eligible_student']){$errors[]='Selected person lacks an approved, consistent student identity.';continue;}$row=$rows[$pid];$current=$row['existing_member'];
            if(!$decision['established']&&$current!==null){$errors[]='An existing member cannot be downgraded or deleted in this workflow.';continue;}
            if(($row['membership_review']['decision']??null)===$decision&&(!$decision['established']||($current!==null&&$current['member_status']===$decision['kind']))){++$summary['skipped'];continue;}
            $changes[$pid]=$decision;if($decision['established'])++$summary[$current===null?'members_to_create':'members_to_update'];
        }
        return ['summary'=>$summary,'people'=>$rows,'relationships'=>$relationships,'excluded'=>$excluded,'changes'=>$changes,'errors'=>array_values(array_unique($errors)),'digest'=>hash('sha256',json_encode([$state['plan']['digest'],$events,$choices],JSON_THROW_ON_ERROR))];
    }
    public function confirm(int $actor,string $operation,string $digest,array $choices,string $backupReference=''): array
    {
        self::validate($choices);if(mb_strlen($backupReference)>500)throw new RuntimeException('INVALID_BACKUP_REFERENCE');
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$operation)||!preg_match('/\A[a-f0-9]{64}\z/D',$digest))throw new RuntimeException('INVALID_FINALIZATION');
        return $this->bulk->reviewTransaction($actor,function(IdentityAssociations $identity)use($actor,$operation,$digest,$choices,$backupReference){
            $q=$this->db->prepare("SELECT actor_user_id,metadata FROM audit_log WHERE entity_type='membership_finalizations' AND entity_id=? AND action='membership_finalization_completed' ORDER BY id LIMIT 1 FOR UPDATE");$q->execute([$operation]);$receipt=$q->fetch();
            if($receipt){$m=json_decode($receipt['metadata'],true,512,JSON_THROW_ON_ERROR);if((int)$receipt['actor_user_id']!==$actor||!hash_equals($m['digest'],$digest))throw new RuntimeException('INVALID_FINALIZATION');return $m['summary']+['replayed'=>true];}
            $p=$this->plan($this->bulk->lockedReviewState(),$choices);if(!hash_equals($p['digest'],$digest))throw new RuntimeException('FINALIZATION_STALE');if($p['errors']!==[]||$p['changes']===[])throw new RuntimeException('FINALIZATION_REQUIRES_REVIEW');
            foreach($p['changes'] as $person=>$decision){if($decision['established'])$identity->finalizeMembership((int)$person,$decision['kind'],$decision['evidence'],$actor);
                $q=$this->db->prepare('INSERT INTO audit_log(actor_user_id,action,entity_type,entity_id,metadata) VALUES (?,?,?,?,?)');$q->execute([$actor,'membership_reviewed','membership_review',(string)$person,json_encode(['operation_id'=>$operation,'before'=>$p['people'][$person]['membership_review']['decision']??null,'decision'=>$decision,'bma_person_id'=>$p['people'][$person]['bma_person_id']],JSON_THROW_ON_ERROR)]);
            }
            $q=$this->db->prepare('INSERT INTO audit_log(actor_user_id,action,entity_type,entity_id,metadata) VALUES (?,?,?,?,?)');$q->execute([$actor,'membership_finalization_completed','membership_finalizations',$operation,json_encode(['digest'=>$digest,'summary'=>$p['summary'],'backup_reference'=>$backupReference],JSON_THROW_ON_ERROR)]);return $p['summary']+['replayed'=>false];
        });
    }
}

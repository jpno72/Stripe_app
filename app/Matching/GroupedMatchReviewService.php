<?php
declare(strict_types=1);
require_once __DIR__.'/IdentityReviewGroups.php';

/** Person-centered projection and explicit review; all identity writes use existing audited services. */
final class GroupedMatchReviewService
{
    private BulkExactMatchService $bulk;
    public function __construct(private readonly PDO $db){$this->bulk=new BulkExactMatchService($db,BulkExactMatchService::SCORE);}
    public function queue(int $actor,string $search='',string $status='open',int $page=1): array
    {
        $projection=IdentityReviewGroups::build($this->bulk->reviewState($actor));
        // Transaction grain is aggregated before touching any candidate/waiver association.
        $counts=$this->db->query('SELECT customer_id,COUNT(*) AS n FROM stripe_transactions WHERE customer_id IS NOT NULL GROUP BY customer_id')->fetchAll(PDO::FETCH_KEY_PAIR);
        $groups=[];$term=BulkExactMatchService::name(mb_substr($search,0,100));
        foreach($projection['groups'] as $g){foreach($g['customer_records'] as $c)$g['transaction_count']+=(int)($counts[$c['id']]??0);
            if($term!==''&&!str_contains($g['search'],$term))continue;
            if($status==='open'&&!$g['needs_decision'])continue;
            if(!in_array($status,['open','all'],true)&&$g['status']!==$status)continue;
            $groups[]=$g;
        }
        usort($groups,static fn($a,$b)=>[$a['status']!=='conflict',strtolower($a['name']),$a['id']]<=>[$b['status']!=='conflict',strtolower($b['name']),$b['id']]);
        $total=count($groups);$page=max(1,min($page,max(1,(int)ceil($total/20))));
        return ['groups'=>array_slice($groups,($page-1)*20,20),'total'=>$total,'page'=>$page,'summary'=>$projection['summary']];
    }
    public function preview(int $actor,string $groupId,string $action='same'): array
    {
        return $this->plan($this->bulk->reviewState($actor),$groupId,$action);
    }
    private function plan(array $state,string $id,string $action): array
    {
        $projection=IdentityReviewGroups::build($state);$g=$projection['groups'][$id]??throw new RuntimeException('GROUP_NOT_FOUND');
        if(!in_array($action,['same','relationship'],true))throw new RuntimeException('INVALID_GROUP_ACTION');
        $result=['group'=>$g,'action'=>$action,'selection'=>[],'excluded'=>[],'people_to_create'=>0,'people_to_reuse'=>0,'members_to_create'=>0,'blockers'=>[],'payer_bma'=>null,'student_bma'=>$g['person_bma']];
        if($action==='same'){
            $selection=$g['eligible_ids'];
            // Build the selected plan using the full conflict graph, never a filtered graph.
            $result['selection']=$selection;
            foreach($state['plan']['groups'] as $pg){$selected=array_filter($pg['decisions'],fn($d)=>in_array($d['id'],$selection,true));if($selected===[])continue;
                $result['people_to_create']+=(int)($pg['person_id']===null);$result['people_to_reuse']+=(int)($pg['person_id']!==null);
                $student=false;foreach($selected as $d)$student=$student||$d['rule']==='B';
                if($student&&($pg['person_id']===null||!in_array($pg['person_id'],array_column($state['data']['members'],'person_id'))))++$result['members_to_create'];
            }
        }else{
            if($g['kind']!=='student'||$g['issues']!==[]||!empty($g['held']))$result['blockers'][]='Group requires individual conflict review.';
            $payerIds=[];$studentIds=[];$labels=[];$names=[];$customers=[];$waivers=$g['waiver_records'];$signerIds=[];
            foreach($g['customer_records'] as $c){$customers[$c['id']]=$c;$names[BulkExactMatchService::name($c['name'])]=true;if($c['person_id']!==null)$payerIds[(int)$c['person_id']]=true;}
            foreach($waivers as $w){$label=BulkExactMatchService::name($w['relationship_to_student']);$labels[$label]=true;
                if(!WaiverSourceRole::isStudent($w)||!isset($names[BulkExactMatchService::name($w['signer_print_name'])])||BulkExactMatchService::name($w['source_name'])===BulkExactMatchService::name($w['signer_print_name'])||!in_array($label,['mother','father','parent','guardian','legal guardian'],true))$result['blockers'][]='Explicit separate student and parent/guardian source roles required.';
                if($w['signer_person_id']!==null)$payerIds[(int)$w['signer_person_id']]=true;
                if($w['student_person_id']!==null)$studentIds[(int)$w['student_person_id']]=true;
            }
            if(count($names)!==1||isset($names[''])||count($labels)!==1||count($payerIds)>1||count($studentIds)>1)$result['blockers'][]='Competing roles or existing people require individual review.';
            // Manual holds/rejections on any affected source role remain authoritative.
            foreach($state['data']['identity_links'] as $l){$affected=$l['source_system']==='stripe'?in_array($l['external_id'],array_column($customers,'stripe_customer_id'),true):in_array($l['external_id'],array_column($waivers,'source_locator'),true);
                if($affected&&($l['match_status']==='rejected'||($l['match_status']==='possible'&&($l['reviewed_by']!==null||$l['reviewed_at']!==null))))$result['blockers'][]='An affected identity scope has a manual rejection or hold.';
            }
            $scopes=[];foreach($customers as $c)$scopes['stripe/'.$c['stripe_customer_id'].'/customer']=$c['person_id'];foreach($waivers as $w)foreach(['signer','student'] as $role)$scopes['cognito/'.$w['source_locator'].'/'.$role]=$w[$role.'_person_id'];
            foreach($scopes as $scope=>$cached){$accepted=[];foreach($state['data']['identity_links'] as $l)if($l['source_system'].'/'.$l['external_id'].'/'.$l['source_role']===$scope&&in_array($l['match_status'],['confirmed','automatic'],true))$accepted[]=(int)$l['person_id'];if(($cached===null&&$accepted!==[])||($cached!==null&&$accepted!==[(int)$cached]))$result['blockers'][]='Authoritative identity links disagree with cached associations.';}
            $safeSigner=[];$signerPairs=[];foreach($state['plan']['groups'] as $pg)foreach($pg['decisions'] as $d)if($d['rule']==='A'){$safeSigner[$d['id']]=$pg;if(isset($customers[$d['customer_id']])&&isset($waivers[$d['waiver_id']])&&$pg['person_id']!==null)$payerIds[(int)$pg['person_id']]=true;}
            foreach($state['data']['identity_match_candidates'] as $c){if(!isset($customers[$c['stripe_customer_id']],$waivers[$c['cognito_waiver_id']]))continue;
                if($c['match_reason']==='manual_review_pending'||($c['match_status']==='rejected'&&$c['match_reason']!=='manual_confirm_relationship'))$result['blockers'][]='An affected candidate has a manual rejection or hold.';
                if($c['waiver_role']==='signer'){
                    $w=$waivers[$c['cognito_waiver_id']];$cu=$customers[$c['stripe_customer_id']];
                    if(isset($safeSigner[$c['id']])){$signerIds[]=(int)$c['id'];$signerPairs[$c['stripe_customer_id'].':'.$c['cognito_waiver_id']]=true;}
                    elseif($c['match_status']!=='confirmed'||$cu['person_id']===null||$cu['person_id']!==$w['signer_person_id'])$result['blockers'][]='Payer/signer identity requires a safe above-50% candidate or prior confirmation.';else $signerPairs[$c['stripe_customer_id'].':'.$c['cognito_waiver_id']]=true;
                }elseif(in_array((int)$c['id'],$g['candidate_ids'],true)&&!$g['resolved'][(int)$c['id']]){
                    if(empty($c['score_above_half'])||in_array($c['match_status'],['confirmed','rejected'],true)){$result['blockers'][]='A student candidate is below the threshold or already decided.';continue;}
                    $result['selection'][]=(int)$c['id'];
                }
            }
            foreach($g['candidate_rows'] as $c)if(in_array((int)$c['id'],$result['selection'],true)&&!isset($signerPairs[$c['stripe_customer_id'].':'.$c['cognito_waiver_id']]))$result['blockers'][]='A selected waiver lacks a verified or eligible signer comparison.';
            if($result['selection']===[])$result['blockers'][]='No unresolved student candidates.';
            if(count($payerIds)>1)$result['blockers'][]='Competing payer identities.';
            $payer=$payerIds===[]?null:(int)array_key_first($payerIds);$student=$studentIds===[]?null:(int)array_key_first($studentIds);
            if($payer!==null&&$payer===$student)$result['blockers'][]='Payer and student must remain separate.';
            $people=array_column($state['data']['people'],null,'id');
            foreach($people as $person){$name=BulkExactMatchService::name(trim(implode(' ',[$person['first_name']??'',$person['middle_name']??'',$person['last_name']??''])));if($student===null&&$name===BulkExactMatchService::name($g['name']))$result['blockers'][]='An existing student-name proposal needs individual BMA selection.';if($student===(int)$person['id']&&$person['birth_date']!==null)foreach($waivers as $w)if($w['student_birth_date']!==null&&$w['student_birth_date']!==$person['birth_date'])$result['blockers'][]='Student birth date conflicts with the existing BMA person.';}

            $result['payer_bma']=$payer===null?null:$people[$payer]['bma_person_id'];$result['student_bma']=$student===null?null:$people[$student]['bma_person_id'];
            $result['people_to_create']=(int)($payer===null)+(int)($student===null);$result['people_to_reuse']=2-$result['people_to_create'];
            $result['members_to_create']=(int)($student===null||!in_array($student,array_column($state['data']['members'],'person_id')));
            $result['signer_selection']=$signerIds;
        }
        $result['selection']=array_values(array_unique($result['selection']));sort($result['selection'],SORT_NUMERIC);
        $result['excluded']=array_values(array_diff($g['candidate_ids'],$result['selection'],$result['signer_selection']??[]));
        if($result['selection']===[])$result['blockers'][]='No eligible candidates remain.';
        $affected=array_fill_keys([...$result['selection'],...($result['signer_selection']??[])],true);$result['affected_customers']=[];$result['affected_waivers']=[];
        $cs=array_column($state['data']['stripe_customers'],null,'id');$ws=array_column($state['data']['cognito_waivers'],null,'id');
        foreach($state['data']['identity_match_candidates'] as $c)if(isset($affected[(int)$c['id']])){$result['affected_customers'][$c['stripe_customer_id']]=$cs[$c['stripe_customer_id']];$result['affected_waivers'][$c['cognito_waiver_id']]=$ws[$c['cognito_waiver_id']];}
        $result['blockers']=array_values(array_unique($result['blockers']));
        $result['digest']=$action==='same'?$this->bulk->planForState($state['data'],$result['selection'])['digest']:hash('sha256',json_encode([$state['plan']['digest'],$id,$action,$result],JSON_THROW_ON_ERROR));
        return $result;
    }
    public function confirm(int $actor,string $operation,string $digest,string $groupId,string $action,?array $selection=null): array
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$operation)||!preg_match('/\A[a-f0-9]{64}\z/D',$digest))throw new RuntimeException('INVALID_GROUP_PREVIEW');
        if($action==='same'){
            if($selection===null)throw new RuntimeException('INVALID_GROUP_PREVIEW');
            return $this->bulk->confirm($actor,$operation,$digest,$selection);
        }
        return $this->bulk->reviewTransaction($actor,function(IdentityAssociations $identity)use($actor,$operation,$digest,$groupId,$action){
            $saved=$this->receipt($actor,$operation,$digest);if($saved!==null)return $saved;
            $p=$this->plan($this->bulk->lockedReviewState(),$groupId,$action);
            if(!hash_equals($digest,$p['digest']))throw new RuntimeException('BULK_PREVIEW_STALE');
            if($p['blockers']!==[])throw new RuntimeException('GROUP_REQUIRES_REVIEW');
            $review=new MatchReviewService($this->db);$input=['payer_bma'=>$p['payer_bma']??'','student_bma'=>$p['student_bma']??'','confirm_source_relationship'=>'1','notes'=>'Administrator-approved consolidated parent/student review. Operation '.$operation];
            foreach($p['selection'] as $id){$review->decideInTransaction($identity,$id,'confirm_relationship',$actor,$input,[],['operation_id'=>$operation,'group_id'=>$groupId,'preview_digest'=>$digest]);
                $q=$this->db->prepare('SELECT pp.bma_person_id payer,sp.bma_person_id student FROM identity_match_candidates m JOIN stripe_customers c ON c.id=m.stripe_customer_id JOIN cognito_waivers w ON w.id=m.cognito_waiver_id JOIN people pp ON pp.id=c.person_id JOIN people sp ON sp.id=w.student_person_id WHERE m.id=?');$q->execute([$id]);$ids=$q->fetch();$input['payer_bma']=$ids['payer'];$input['student_bma']=$ids['student'];
            }
            foreach($p['signer_selection'] as $id)$review->decideInTransaction($identity,$id,'confirm_same',$actor,['person_bma'=>$input['payer_bma'],'notes'=>$input['notes']],['operation_id'=>$operation,'rule'=>'A','policy'=>BulkExactMatchService::SCORE,'preview_digest'=>$digest]);
            $summary=['candidates'=>count($p['selection'])+count($p['signer_selection']),'people_created'=>$p['people_to_create'],'members_created'=>$p['members_to_create']];
            $q=$this->db->prepare('INSERT INTO audit_log(actor_user_id,action,entity_type,entity_id,metadata) VALUES (?,?,?,?,?)');$q->execute([$actor,'identity_group_confirmed','identity_review_groups',$operation,json_encode(['digest'=>$digest,'group_id'=>$groupId,'action'=>$action,'summary'=>$summary],JSON_THROW_ON_ERROR)]);return $summary+['replayed'=>false];
        });
    }
    private function receipt(int $actor,string $operation,string $digest): ?array
    {
        $q=$this->db->prepare("SELECT actor_user_id,metadata FROM audit_log WHERE entity_type='identity_review_groups' AND entity_id=? AND action='identity_group_confirmed' ORDER BY id LIMIT 1");$q->execute([$operation]);$r=$q->fetch();if(!$r)return null;$m=json_decode($r['metadata'],true,512,JSON_THROW_ON_ERROR);if((int)$r['actor_user_id']!==$actor||!hash_equals($m['digest'],$digest))throw new RuntimeException('INVALID_GROUP_PREVIEW');return $m['summary']+['replayed'=>true];
    }
}

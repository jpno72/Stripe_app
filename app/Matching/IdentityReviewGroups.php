<?php
declare(strict_types=1);
require_once __DIR__.'/BulkExactMatchService.php';

/** Read-only person/role projection. No candidate or source record is merged or rewritten. */
final class IdentityReviewGroups
{
    public static function build(array $state): array
    {
        $data=$state['data'];$plan=$state['plan'];$customers=array_column($data['stripe_customers'],null,'id');$waivers=array_column($data['cognito_waivers'],null,'id');$people=array_column($data['people'],null,'id');
        $graph=BulkScoreMatchGroups::build($data,[BulkExactMatchService::class,'identityKey']);$eligible=[];foreach($plan['groups'] as $g)foreach($g['decisions'] as $d)$eligible[$d['id']]=true;
        $reviewReasons=[];foreach($plan['review_groups']??[] as $r)foreach($r['candidate_ids'] as $id)$reviewReasons[(int)$id]=$r['reason'];
        $links=[];foreach($data['identity_links'] as $l)if(in_array($l['match_status'],['automatic','confirmed'],true))$links[$l['source_system'].'/'.$l['external_id'].'/'.$l['source_role']]=$l;
        $scope=static function(string $system,string $external,string $role,?int $cached)use($links):array{
            $l=$links[$system.'/'.$external.'/'.$role]??null;$valid=$l!==null&&$cached!==null&&(int)$l['person_id']===$cached;
            return ['person'=>$valid?$cached:null,'confirmed'=>$valid&&$l['match_status']==='confirmed','drift'=>($cached!==null||$l!==null)&&!$valid];
        };
        $componentPeople=[];
        foreach($customers as $id=>$c){$p=$scope('stripe',$c['stripe_customer_id'],'customer',$c['person_id']===null?null:(int)$c['person_id']);if($p['person']!==null)$componentPeople[$graph['customerKeys'][$id]][$p['person']]=true;}
        foreach($waivers as $id=>$w){$p=$scope('cognito',$w['source_locator'],'signer',$w['signer_person_id']===null?null:(int)$w['signer_person_id']);if($p['person']!==null)$componentPeople[$graph['waiverKeys'][$id]][$p['person']]=true;}
        $relationships=[];foreach($data['person_relationships'] as $r)$relationships[$r['person_id'].':'.$r['related_person_id'].':'.$r['relationship_type']]=true;
        $assign=[];$stems=[];
        foreach($data['identity_match_candidates'] as $c){
            $cu=$customers[$c['stripe_customer_id']];$w=$waivers[$c['cognito_waiver_id']];$n=BulkExactMatchService::name($cu['name']);$signer=BulkExactMatchService::name($w['signer_print_name']);$target=$c['waiver_role']==='signer'?$signer:BulkExactMatchService::name($w['source_name']);$role=$c['waiver_role']==='signer'?'signer':'student';
            $ps=$scope('stripe',$cu['stripe_customer_id'],'customer',$cu['person_id']===null?null:(int)$cu['person_id']);$ws=$scope('cognito',$w['source_locator'],$role,$w[$role.'_person_id']===null?null:(int)$w[$role.'_person_id']);$root=$graph['customerKeys'][$cu['id']];
            $self=WaiverSourceRole::isStudent($w)&&BulkExactMatchService::name($w['relationship_to_student'])==='self'&&$n!==''&&$n===$signer&&$target===$n;
            $sameRole=$role==='signer'||$self||($ps['person']!==null&&$ps['person']===$ws['person']);
            if($sameRole){
                $known=$ws['person'];if($known===null&&count($componentPeople[$root]??[])===1&&$n!==''&&$n===$target)$known=(int)array_key_first($componentPeople[$root]);
                $compatible=$n!==''&&$n===$target&&$root===($graph['waiverKeys'][$w['id']]??null);
                $key=$known!==null?'person:'.$known:($compatible?'person-root:'.$root:'proposal:'.$root.':'.$role.':'.hash('sha256',$target!==''?$target:'waiver:'.$w['id']));
                $assign[(int)$c['id']]=['key'=>$key,'kind'=>'person','person'=>$known,'name'=>$role==='signer'?$w['signer_print_name']:$w['source_name'],'stem'=>null];
            }else{
                $stem=$root.':'.hash('sha256',$target!==''?$target:'waiver:'.$w['id']);
                $assign[(int)$c['id']]=['key'=>'student:'.$stem,'kind'=>WaiverSourceRole::isStudent($w)||$ws['confirmed']?'student':'unresolved','person'=>$ws['person'],'name'=>$w['source_name'],'stem'=>$stem];
                if($ws['person']!==null)$stems[$stem]['people'][$ws['person']]=true;
                if($w['student_birth_date']!==null)$stems[$stem]['dob'][$w['student_birth_date']]=true;
            }
        }
        $componentDates=[];foreach($data['identity_match_candidates'] as $c){$w=$waivers[$c['cognito_waiver_id']];if(WaiverSourceRole::isStudent($w)&&BulkExactMatchService::name($w['relationship_to_student'])==='self'&&$w['student_birth_date']!==null)$componentDates[$assign[(int)$c['id']]['key']][$w['student_birth_date']]=true;}
        $groups=[];
        foreach($data['identity_match_candidates'] as $c){
            $a=$assign[(int)$c['id']];$cu=$customers[$c['stripe_customer_id']];$w=$waivers[$c['cognito_waiver_id']];$stem=$a['stem'];$issues=[];$reason=$reviewReasons[(int)$c['id']]??null;if($reason!==null&&!in_array($reason,['score_not_above_half','student_role_separation','legacy_source_role_ambiguous','manual_candidate_decision','manual_identity_review_pending'],true))$issues[]=$reason;if(isset($graph['reasons'][$graph['customerKeys'][$cu['id']]]))$issues[]=$graph['reasons'][$graph['customerKeys'][$cu['id']]];
            if($stem!==null){
                $ids=$stems[$stem]['people']??[];$dates=$stems[$stem]['dob']??[];
                if(count($ids)>1){$a['key'].=':person:'.($a['person']??'unknown:'.$w['id']);$issues[]='competing_existing_people';}
                elseif(count($ids)===1){$a['person']=(int)array_key_first($ids);$a['key']='student-person:'.$a['person'];}
                if(count($dates)>1){$a['key'].=':dob:'.($w['student_birth_date']??'unknown');$issues[]='conflicting_birth_dates';}
            }
            if(count($componentDates[$a['key']]??[])>1){$a['key'].=':dob:'.($w['student_birth_date']??'unknown');$issues[]='conflicting_birth_dates';}
            if($c['match_reason']==='manual_review_pending')$a['key'].=':held';
            elseif($c['match_status']==='rejected'&&$c['match_reason']!=='manual_confirm_relationship')$a['key'].=':rejected';
            $key=hash('sha256','identity-review-v1:'.$a['key']);
            if(!isset($groups[$key]))$groups[$key]=['id'=>$key,'kind'=>$a['kind'],'name'=>$a['name']?:'Name unavailable','person_id'=>$a['person'],'candidate_rows'=>[],'customer_records'=>[],'waiver_records'=>[],'eligible_ids'=>[],'issues'=>[],'roles'=>['signer'=>0,'student'=>0,'unresolved'=>0],'transaction_count'=>0];
            $g=&$groups[$key];$g['candidate_rows'][(int)$c['id']]=$c;$g['customer_records'][(int)$cu['id']]=$cu;$g['waiver_records'][(int)$w['id']]=$w;
            if(isset($eligible[(int)$c['id']]))$g['eligible_ids'][]=(int)$c['id'];
            $g['roles'][$c['waiver_role']==='signer'?'signer':(WaiverSourceRole::isStudent($w)?'student':'unresolved')]++;
            array_push($g['issues'],...$issues);
            $payer=$scope('stripe',$cu['stripe_customer_id'],'customer',$cu['person_id']===null?null:(int)$cu['person_id']);$role=$c['waiver_role']==='signer'?'signer':'student';$other=$scope('cognito',$w['source_locator'],$role,$w[$role.'_person_id']===null?null:(int)$w[$role.'_person_id']);
            if($payer['drift']||$other['drift'])$g['issues'][]='identity_cache_conflict';
            if($a['person']!==null&&isset($people[$a['person']])){$p=$people[$a['person']];$g['name']=trim(implode(' ',[$p['first_name']??'',$p['middle_name']??'',$p['last_name']??'']))?:$g['name'];}
            $e=json_decode((string)$c['evidence'],true)?:[];
            foreach($e['conflicts']??[] as $code)if(!in_array($code,['source_name_role_unverified','student_identity_unverified'],true)&&!($g['kind']==='student'&&in_array($code,['different_full_name','different_last_name'],true)))$g['issues'][]=(string)$code;
            if($g['kind']==='person'&&$payer['person']!==null&&$other['person']!==null&&$payer['person']!==$other['person'])$g['issues'][]='competing_existing_people';
            $resolved=$payer['confirmed']&&$other['confirmed']&&$payer['person']===$other['person'];
            if($c['match_reason']==='manual_confirm_relationship'&&$payer['confirmed']&&$other['confirmed']&&$w['student_person_id']!==null&&isset($relationships[$payer['person'].':'.$w['student_person_id'].':payer']))$resolved=true;
            $signerScope=$scope('cognito',$w['source_locator'],'signer',$w['signer_person_id']===null?null:(int)$w['signer_person_id']);
            if($c['match_reason']==='manual_confirm_relationship'&&!$signerScope['confirmed'])$resolved=false;
            $g['waiver_records'][(int)$w['id']]['student_identity_reviewed']=$scope('cognito',$w['source_locator'],'student',$w['student_person_id']===null?null:(int)$w['student_person_id'])['confirmed'];
            $g['resolved'][(int)$c['id']]=$resolved;
            if($c['match_reason']==='manual_review_pending')$g['held']=true;
            unset($g);
        }
        $summary=['total_groups'=>count($groups),'groups_needing_decision'=>0,'candidate_records'=>count($data['identity_match_candidates']),'waivers_represented'=>0,'existing_people'=>0,'unresolved_conflicts'=>0];$allWaivers=[];$allPeople=[];
        foreach($groups as &$g){
            $g['candidate_ids']=array_keys($g['candidate_rows']);sort($g['candidate_ids'],SORT_NUMERIC);$g['issues']=array_values(array_unique($g['issues']));
            $scores=array_map(static fn($c)=>(float)$c['match_score'],array_values($g['candidate_rows']));$g['score_min']=min($scores);$g['score_max']=max($scores);
            $g['person_bma']=$g['person_id']!==null?($people[$g['person_id']]['bma_person_id']??null):null;
            $allResolved=!in_array(false,$g['resolved'],true);$allRejected=count(array_filter($g['candidate_rows'],static fn($c)=>$c['match_status']==='rejected'))===count($g['candidate_rows']);
            $g['status']=$g['issues']!==[]?'conflict':(!empty($g['held'])?'held':($allResolved?'confirmed':($allRejected?'rejected':($g['eligible_ids']!==[]||$g['kind']==='student'?'review':'unmatched'))));
            $g['needs_decision']=!in_array($g['status'],['confirmed','rejected'],true);if($g['needs_decision'])++$summary['groups_needing_decision'];if($g['status']==='conflict')++$summary['unresolved_conflicts'];
            $g['eligible_ids']=array_values(array_filter($g['eligible_ids'],fn($id)=>!$g['resolved'][$id]));
            $g['search']=BulkExactMatchService::name($g['name'].' '.implode(' ',$g['candidate_ids']));
            foreach($g['customer_records'] as $c){$g['search'].=' '.BulkExactMatchService::name($c['name'].' '.$c['email'].' '.$c['email_normalized'].' '.$c['stripe_customer_id']);if($c['person_id']!==null&&isset($people[$c['person_id']])){$allPeople[$c['person_id']]=true;$g['search'].=' '.strtolower($people[$c['person_id']]['bma_person_id']??'');}}
            foreach($g['waiver_records'] as $w){$allWaivers[$w['id']]=true;$g['search'].=' '.BulkExactMatchService::name($w['source_name'].' '.$w['signer_print_name'].' '.$w['contact_email'].' '.$w['contact_phone'].' '.$w['source_record_id']);foreach(['signer_person_id','student_person_id'] as $field)if($w[$field]!==null&&isset($people[$w[$field]])){$allPeople[$w[$field]]=true;$g['search'].=' '.strtolower($people[$w[$field]]['bma_person_id']??'');}}
        }unset($g);
        $summary['waivers_represented']=count($allWaivers);$summary['existing_people']=count($allPeople);
        return ['groups'=>$groups,'summary'=>$summary];
    }
}

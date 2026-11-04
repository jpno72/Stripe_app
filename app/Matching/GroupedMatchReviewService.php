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
        $state=$this->bulk->reviewState($actor);$projection=IdentityReviewGroups::build($state);
        $projection=$this->classify($state,$projection);
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
    /** One read-only snapshot; completed groups are neither reclassified nor reproposed. */
    private function classify(array $state,array $projection): array
    {
        $counts=['examined'=>0,'same_person'=>0,'parent_student'=>0,'existing_person'=>0,'needs_review'=>0];$newPeople=[];$newMembers=[];
        foreach($projection['groups'] as &$g){
            if(!$g['needs_decision'])continue;
            ++$counts['examined'];$g['classification']='Needs Review';$g['classification_evidence']=[];$g['review_action']=null;
            $action=$g['kind']==='student'?'relationship':'same';$plan=$this->plan($state,$g['id'],$action,$projection,false);
            $g['classification_blockers']=$plan['blockers'];$household=true;$contact=true;$hasRows=false;
            foreach($g['candidate_rows'] as $c){
                if($g['resolved'][(int)$c['id']]||in_array($c['match_status'],['confirmed','rejected'],true))continue;
                $hasRows=true;$payer=$g['customer_records'][$c['stripe_customer_id']];$w=$g['waiver_records'][$c['cognito_waiver_id']];
                $email=BulkExactMatchService::email($payer['email_normalized']??$payer['email']);$emailMatch=$email!==''&&filter_var($email,FILTER_VALIDATE_EMAIL)&&$email===BulkExactMatchService::email($w['contact_email']);
                $billing=implode(' ',array_filter([$payer['address_line1']??null,$payer['address_city']??null,$payer['address_postal_code']??null],static fn($v)=>$v!==null&&trim($v)!==''));
                $address=self::reviewAddress($billing);$addressMatch=!empty($payer['address_line1'])&&!empty($payer['address_city'])&&!empty($payer['address_postal_code'])&&$address!==''&&$address===self::reviewAddress((string)$w['address_text']);
                $name=BulkExactMatchService::personName($payer['name']);$target=BulkExactMatchService::personName($c['waiver_role']==='signer'?$w['signer_print_name']:$w['source_name']);
                $contact=$contact&&$name!==''&&$name===$target&&($emailMatch||$addressMatch);
                $household=$household&&$emailMatch&&$addressMatch&&WaiverSourceRole::isStudent($w)&&$name!==''&&$target!==''&&$name!==$target&&in_array(BulkExactMatchService::name($w['relationship_to_student']),['mother','father','parent','guardian','legal guardian'],true);
            }
            if($hasRows&&$g['issues']===[]&&empty($g['held'])){
                if($g['kind']==='student'&&$household){$g['classification']='Parent/Payer → Student';$g['classification_evidence']=['Payer email matches waiver contact','Normalized billing and waiver addresses match','Explicit parent/guardian source label','Student and payer are separate named people; surname differences are permitted'];if($plan['blockers']===[])$g['review_action']='relationship';}
                elseif($g['kind']==='person'&&$plan['blockers']===[]&&($contact||$plan['people_to_reuse']>0)){$g['classification']=$plan['people_to_reuse']>0?'Existing Person':'Same Person';$g['classification_evidence']=['Compatible full name and contact evidence','Existing source-role and score safeguards pass'];$g['review_action']='same';}
            }
            $key=match($g['classification']){'Same Person'=>'same_person','Parent/Payer → Student'=>'parent_student','Existing Person'=>'existing_person',default=>'needs_review'};++$counts[$key];
            // Estimates include only actionable proposals and deduplicate shared payer proposals across children/cards.
            if($g['review_action']!==null){
                $payerKey='payer:'.$g['payer_key'];
                if($action==='relationship'){
                    if($plan['payer_bma']===null)$newPeople[$payerKey]=true;
                    if($plan['student_bma']===null)$newPeople['student:'.$g['id']]=true;
                    if($plan['members_to_create']>0)$newMembers[$plan['student_bma']??'student:'.$g['id']]=true;
                }else{
                    if($plan['people_to_create']>0)$newPeople[$payerKey]=true;
                    if($plan['members_to_create']>0)$newMembers[$g['person_bma']??$payerKey]=true;
                }
            }
        }unset($g);
        $projection['summary']['classification']=$counts+['new_people_estimate'=>count($newPeople),'new_members_estimate'=>count($newMembers)];return $projection;
    }
    private static function reviewAddress(string $value): string
    {
        $value=BulkExactMatchService::name($value);$value=preg_replace('/[^\p{L}\p{N}]+/u',' ',$value)??'';
        $aliases=['street'=>'st','road'=>'rd','avenue'=>'ave','drive'=>'dr','lane'=>'ln','court'=>'ct','apartment'=>'apt'];
        $value=preg_replace('/\bpennsylvania\b/u','pa',$value)??$value;
        return trim(implode(' ',array_map(static fn($part)=>$aliases[$part]??$part,explode(' ',$value))));
    }
    public function preview(int $actor,string $groupId,string $action='same',array $input=[]): array
    {
         $state=$this->bulk->reviewState($actor);
        return in_array($action,['manual_same','manual_relationship','different','defer'],true)?$this->manualPlan($state,$groupId,$action,$input):$this->plan($state,$groupId,$action);
    }
    public function resolution(int $actor,string $id): array
    {
        $state=$this->bulk->reviewState($actor);$projection=IdentityReviewGroups::build($state);$g=$projection['groups'][$id]??throw new RuntimeException('GROUP_NOT_FOUND');
        $one=$this->classify($state,['groups'=>[$id=>$g],'summary'=>$projection['summary']]);return ['group'=>$one['groups'][$id],'people'=>$state['data']['people']];
    }
    public static function reviewInput(array $input): array
    {
        $out=[];foreach(['person_bma','payer_bma','student_bma','signer_bma','notes'] as $key)$out[$key]=is_string($input[$key]??null)?trim($input[$key]):'';
        if(mb_strlen($out['notes'])>2000)throw new RuntimeException('NOTES_TOO_LONG');
        foreach(['member_verified','signer_is_payer','signer_is_student','confirm_source_relationship','verify_variant'] as $key)$out[$key]=($input[$key]??null)==='1'?'1':'';
        return $out;
    }
    private function manualPlan(array $state,string $id,string $action,array $input): array
    {
        $input=self::reviewInput($input);$projection=IdentityReviewGroups::build($state);$g=$projection['groups'][$id]??throw new RuntimeException('GROUP_NOT_FOUND');
        $r=['group'=>$g,'action'=>$action,'input'=>$input,'selection'=>[],'signer_selection'=>[],'excluded'=>[],'blockers'=>[],'people_to_create'=>0,'people_to_reuse'=>0,'members_to_create'=>0,'affected_customers'=>[],'affected_waivers'=>[],'payer_bma'=>null,'student_bma'=>null];
        $people=array_column($state['data']['people'],null,'bma_person_id');$ids=array_column($state['data']['people'],'bma_person_id','id');$proposed=[];$reuse=[];$membership=[];
        foreach(['person_bma','payer_bma','student_bma','signer_bma'] as $key)if($input[$key]!==''&&!isset($people[$input[$key]]))$r['blockers'][]='Selected BMA person is unavailable.';
        if($action==='defer'&&$input['notes']==='')$r['blockers'][]='Enter a short reason for later review.';
        if(in_array($action,['manual_same','manual_relationship'],true))foreach($g['issues'] as $issue)if(!in_array($issue,['different_full_name','different_last_name','competing_source_names','hard_or_contradictory_evidence','person_name_requires_manual'],true))$r['blockers'][]='Protected conflict: '.str_replace('_',' ',$issue);
        $targetNames=[];$payerNames=[];$signerNames=[];$births=[];
        foreach($g['candidate_rows'] as $c){
            $cid=(int)$c['id'];$w=$g['waiver_records'][$c['cognito_waiver_id']];$cu=$g['customer_records'][$c['stripe_customer_id']];$student=$c['waiver_role']!=='signer';$target=$student?$w['source_name']:$w['signer_print_name'];
            if(in_array($c['match_status'],['confirmed','linked','rejected'],true)||$g['resolved'][$cid]||($c['match_reason']==='manual_review_pending'&&$action!=='defer')){$r['excluded'][]=$cid;continue;}
            if($action==='manual_same'){
                $name=BulkExactMatchService::personName($cu['name']);$other=BulkExactMatchService::personName($target);
                $email=BulkExactMatchService::email($cu['email_normalized']??$cu['email']);$contact=$email!==''&&$email===BulkExactMatchService::email($w['contact_email']);
                $address=self::reviewAddress(implode(' ',array_filter([$cu['address_line1']??null,$cu['address_city']??null,$cu['address_postal_code']??null])));$contact=$contact||(!empty($cu['address_line1'])&&!empty($cu['address_postal_code'])&&$address===self::reviewAddress((string)$w['address_text']));
                $ambiguous=MatchScorer::ambiguousName($cu['name'])||MatchScorer::ambiguousName($target);
                // Ambiguous aliases require explicit independent verification and an existing reviewed BMA selection.
                if($name===''||(($name!==$other||$ambiguous)&&!($ambiguous&&$input['verify_variant']==='1'&&$input['person_bma']!==''&&MatchScorer::namesCompatible($cu['name'],$target,true)))){$r['excluded'][]=$cid;continue;}
                if(!$contact&&$cu['person_id']===null){$r['excluded'][]=$cid;continue;}
                if($student&&(!WaiverSourceRole::isStudent($w)||BulkExactMatchService::name($w['relationship_to_student'])!=='self'||!MatchScorer::namesCompatible($cu['name'],$w['signer_print_name'],$input['verify_variant']==='1'&&$input['person_bma']!=='')||$input['member_verified']!=='1')){$r['excluded'][]=$cid;continue;}
                if($student&&$w['student_birth_date']!==null){$births[$w['student_birth_date']]=true;$dob=new DateTimeImmutable($w['student_birth_date']);$today=new DateTimeImmutable(AppTime::today());if($dob>$today||$dob->diff($today)->y<18)$r['blockers'][]='A minor Self claim needs individual role verification.';}
                $role=$student?'student':'signer';$targetNames[$name]=true;
                foreach([$cu['person_id'],$w[$role.'_person_id'],$input['person_bma']!==''?($people[$input['person_bma']]['id']??null):null] as $pid)if($pid!==null)$reuse[(int)$pid]=true;
                if($student)$membership['same']=true;
            }
            if($action==='manual_relationship'){
                if(!$student||!WaiverSourceRole::isStudent($w)||$input['member_verified']!=='1'){$r['excluded'][]=$cid;continue;}
                if(MatchScorer::comparisonName($cu['name'],false)===MatchScorer::comparisonName($w['source_name'],false)){$r['excluded'][]=$cid;continue;}
                if($input['signer_is_payer']==='1'&&$input['signer_is_student']==='1')$r['blockers'][]='Signer cannot be both the separate payer and student.';
                if($input['signer_is_student']==='1'&&!MatchScorer::namesCompatible($w['signer_print_name'],$w['source_name']))$r['blockers'][]='Signer and student names do not agree.';
                if($input['signer_is_student']==='1'&&$input['confirm_source_relationship']==='1')$r['blockers'][]='A student cannot be their own parent/guardian.';
                $payerNames[MatchScorer::comparisonName($cu['name'],false)]=true;$targetNames[MatchScorer::comparisonName($w['source_name'],false)]=true;$signerNames[MatchScorer::comparisonName($w['signer_print_name'],false)]=true;
                if($w['student_birth_date']!==null)$births[$w['student_birth_date']]=true;
                if($input['signer_is_payer']==='1'&&BulkExactMatchService::personName($cu['name'])!==BulkExactMatchService::personName($w['signer_print_name']))$r['blockers'][]='Payer and signer names do not agree; identify the signer separately.';
                if($input['confirm_source_relationship']==='1'&&!in_array(BulkExactMatchService::name($w['relationship_to_student']),['mother','father','parent','guardian','legal guardian'],true))$r['blockers'][]='No supported explicit parent/guardian label to verify.';
                foreach(['payer'=>[$cu['person_id'],'payer_bma'],'student'=>[$w['student_person_id'],'student_bma'],'signer'=>[$w['signer_person_id'],'signer_bma']] as $role=>[$cached,$field]){
                    if($role==='signer'&&$input['signer_is_payer']==='1')$field='payer_bma';elseif($role==='signer'&&$input['signer_is_student']==='1')$field='student_bma';
                    $selected=$input[$field]!==''?($people[$input[$field]]['id']??null):null;
                    if($cached!==null&&$selected!==null&&(int)$cached!==(int)$selected)$r['blockers'][]='An accepted role belongs to another BMA person.';
                    $person=$cached??$selected;$key=$role==='signer'&&$input['signer_is_payer']==='1'?'payer':($role==='signer'?($input['signer_is_student']==='1'?'student':'signer:'.MatchScorer::comparisonName($w['signer_print_name'],false)):$role);
                    $proposed[$key][$person===null?'new':(string)$person]=true;if($person!==null)$reuse[(int)$person]=true;
                }
                $membership['student']=true;
            }
            if($action==='different'&&$cu['person_id']!==null&&$cu['person_id']===$w[$student?'student_person_id':'signer_person_id']){$r['excluded'][]=$cid;continue;}
            if(in_array($action,['manual_same','manual_relationship'],true)){
                $e=json_decode($c['evidence'],true)?:[];foreach($e['conflicts']??[] as $conflict)if(!in_array($conflict,['different_full_name','different_last_name','student_identity_unverified','source_name_role_unverified','name_variant_requires_review','ambiguous_name_variant'],true))$r['blockers'][]='Conflicting source evidence: '.$conflict;
            }
            $r['selection'][]=$cid;$r['affected_customers'][$cu['id']]=$cu;$r['affected_waivers'][$w['id']]=$w;
        }
        if(in_array($action,['manual_same','manual_relationship'],true)){
            foreach($r['affected_customers'] as $cu){$selected=$input[$action==='manual_same'?'person_bma':'payer_bma'];if($selected!==''&&isset($people[$selected])){$p=$people[$selected];$name=trim(implode(' ',[$p['first_name'],$p['middle_name'],$p['last_name']]));if(!MatchScorer::namesCompatible($name,$cu['name'],$input['verify_variant']==='1'))$r['blockers'][]='Selected existing payer/person has incompatible name evidence.';}}
            foreach($r['affected_waivers'] as $w){
                foreach(['signer','student'] as $role){if($action==='manual_same'&&$role==='student'&&!isset($membership['same']))continue;$selected=$input[$action==='manual_same'?'person_bma':($role==='signer'&&$input['signer_is_payer']==='1'?'payer_bma':($role==='signer'&&$input['signer_is_student']==='1'?'student_bma':$role.'_bma'))];$source=$w[$role==='signer'?'signer_print_name':'source_name'];if($selected!==''&&isset($people[$selected])){$p=$people[$selected];$name=trim(implode(' ',[$p['first_name'],$p['middle_name'],$p['last_name']]));if(!MatchScorer::namesCompatible($name,$source,$input['verify_variant']==='1'))$r['blockers'][]='Selected existing '.$role.' has incompatible name evidence.';if($role==='student'&&$p['birth_date']!==null&&$w['student_birth_date']!==null&&$p['birth_date']!==$w['student_birth_date'])$r['blockers'][]='Selected student birth date conflicts with the waiver.';}}
                if($action==='manual_relationship'&&(trim((string)$w['source_name'])===''||trim((string)$w['signer_print_name'])===''))$r['blockers'][]='Missing student/signer name requires more information.';
            }
        }
        if(count($births)>1||count($targetNames)>1||count($payerNames)>1)$r['blockers'][]='Competing names or birth dates need separate review.';
        if($action==='manual_same'){
            if(count($reuse)>1)$r['blockers'][]='Competing existing BMA identities cannot be combined.';
            if($reuse!==[]){$pid=(int)array_key_first($reuse);$r['input']['person_bma']=$ids[$pid];$r['people_to_reuse']=1;}else $r['people_to_create']=1;
            $r['members_to_create']=$membership===[]?0:(int)($reuse===[]||!in_array(array_key_first($reuse),array_column($state['data']['members'],'person_id')));
        }elseif($action==='manual_relationship'){
            $roles=[];foreach($proposed as $role=>$pids){unset($pids['new']);if(count($pids)>1)$r['blockers'][]='Competing accepted identities for '.$role;$pid=$pids===[]?null:(int)array_key_first($pids);$roles[$role]=$pid;if($pid===null)++$r['people_to_create'];elseif(in_array($role,['payer','student'],true))$r['input'][$role.'_bma']=$ids[$pid];elseif(count($signerNames)===1)$r['input']['signer_bma']=$ids[$pid];}
            if(isset($roles['payer'],$roles['student'])&&$roles['payer']===$roles['student'])$r['blockers'][]='Payer and student must be separate people.';
            $r['payer_bma']=$r['input']['payer_bma']?:null;$r['student_bma']=$r['input']['student_bma']?:null;$r['people_to_reuse']=count($reuse);$r['members_to_create']=$membership===[]?0:(int)(($roles['student']??null)===null||!in_array($roles['student'],array_column($state['data']['members'],'person_id')));
        }
        if(in_array($action,['manual_same','manual_relationship'],true))foreach($state['data']['identity_links'] as $l){$affected=$l['source_system']==='stripe'?in_array($l['external_id'],array_column($r['affected_customers'],'stripe_customer_id'),true):in_array($l['external_id'],array_column($r['affected_waivers'],'source_locator'),true);if($affected&&($l['match_status']==='rejected'||($l['match_status']==='possible'&&($l['reviewed_at']!==null||$l['reviewed_by']!==null))))$r['blockers'][]='A protected identity rejection or hold requires individual review.';}
        if(in_array($action,['manual_same','manual_relationship'],true))foreach($state['data']['identity_match_candidates'] as $other)if(isset($r['affected_customers'][$other['stripe_customer_id']],$r['affected_waivers'][$other['cognito_waiver_id']])&&!in_array((int)$other['id'],$r['selection'],true)&&($other['match_reason']==='manual_review_pending'||$other['match_status']==='rejected'))$r['blockers'][]='Another comparison on these source records has a protected hold or rejection.';
        if($r['selection']===[])$r['blockers'][]='No eligible records for this choice. Protected decisions and holds remain unchanged.';
        sort($r['selection']);$r['blockers']=array_values(array_unique($r['blockers']));$r['digest']=hash('sha256',json_encode([$state['plan']['digest'],$r],JSON_THROW_ON_ERROR));return $r;
    }
    private function plan(array $state,string $id,string $action,?array $projection=null,bool $fingerprint=true): array
    {
        $projection??=IdentityReviewGroups::build($state);$g=$projection['groups'][$id]??throw new RuntimeException('GROUP_NOT_FOUND');
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
            foreach($g['customer_records'] as $c){$customers[$c['id']]=$c;$names[BulkExactMatchService::personName($c['name'])]=true;if($c['person_id']!==null)$payerIds[(int)$c['person_id']]=true;}
            foreach($waivers as $w){$label=BulkExactMatchService::name($w['relationship_to_student']);$labels[$label]=true;
                if(!WaiverSourceRole::isStudent($w)||!isset($names[BulkExactMatchService::personName($w['signer_print_name'])])||BulkExactMatchService::personName($w['source_name'])===BulkExactMatchService::personName($w['signer_print_name'])||!in_array($label,['mother','father','parent','guardian','legal guardian'],true))$result['blockers'][]='Explicit separate student and parent/guardian source roles required.';
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
            foreach($people as $person){$name=BulkExactMatchService::personName(trim(implode(' ',[$person['first_name']??'',$person['middle_name']??'',$person['last_name']??''])));if($student===null&&$name===BulkExactMatchService::personName($g['name']))$result['blockers'][]='An existing student-name proposal needs individual BMA selection.';if($student===(int)$person['id']&&$person['birth_date']!==null)foreach($waivers as $w)if($w['student_birth_date']!==null&&$w['student_birth_date']!==$person['birth_date'])$result['blockers'][]='Student birth date conflicts with the existing BMA person.';}

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
        if($fingerprint)$result['digest']=$action==='same'?$this->bulk->planForState($state['data'],$result['selection'])['digest']:hash('sha256',json_encode([$state['plan']['digest'],$id,$action,$result],JSON_THROW_ON_ERROR));
        return $result;
    }
    public function confirm(int $actor,string $operation,string $digest,string $groupId,string $action,?array $selection=null,array $input=[]): array
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$operation)||!preg_match('/\A[a-f0-9]{64}\z/D',$digest))throw new RuntimeException('INVALID_GROUP_PREVIEW');
        if(in_array($action,['manual_same','manual_relationship','different','defer'],true))return $this->bulk->reviewTransaction($actor,function(IdentityAssociations $identity)use($actor,$operation,$digest,$groupId,$action,$input){
            $saved=$this->receipt($actor,$operation,$digest);if($saved!==null)return $saved;
            $p=$this->manualPlan($this->bulk->lockedReviewState(),$groupId,$action,$input);if(!hash_equals($digest,$p['digest']))throw new RuntimeException('BULK_PREVIEW_STALE');if($p['blockers']!==[])throw new RuntimeException('GROUP_REQUIRES_REVIEW');
            $review=new MatchReviewService($this->db);$values=$p['input'];$signers=[];$context=['operation_id'=>$operation,'group_id'=>$groupId,'preview_digest'=>$digest];
            foreach($p['selection'] as $id){
                if($action==='manual_relationship'){$q=$this->db->prepare('SELECT w.signer_print_name FROM identity_match_candidates m JOIN cognito_waivers w ON w.id=m.cognito_waiver_id WHERE m.id=?');$q->execute([$id]);$key=MatchScorer::comparisonName($q->fetchColumn(),false);$values['signer_bma']=$signers[$key]??$p['input']['signer_bma'];}
                $decision=match($action){'manual_same'=>'confirm_same','manual_relationship'=>'confirm_relationship','different'=>'reject','defer'=>'later'};
                $review->decideInTransaction($identity,$id,$decision,$actor,$values,[],$context);
                if(in_array($action,['manual_same','manual_relationship'],true)){$q=$this->db->prepare('SELECT pp.bma_person_id payer,sp.bma_person_id student,sip.bma_person_id signer FROM identity_match_candidates m JOIN stripe_customers c ON c.id=m.stripe_customer_id JOIN cognito_waivers w ON w.id=m.cognito_waiver_id LEFT JOIN people pp ON pp.id=c.person_id LEFT JOIN people sp ON sp.id=w.student_person_id LEFT JOIN people sip ON sip.id=w.signer_person_id WHERE m.id=?');$q->execute([$id]);$row=$q->fetch();$values['person_bma']=$values['payer_bma']=$row['payer'];$values['student_bma']=$row['student']??'';if($action==='manual_relationship')$signers[$key]=$row['signer'];}
            }
            $summary=['candidates'=>count($p['selection']),'people_created'=>$p['people_to_create'],'members_created'=>$p['members_to_create']];$q=$this->db->prepare('INSERT INTO audit_log(actor_user_id,action,entity_type,entity_id,metadata) VALUES (?,?,?,?,?)');$q->execute([$actor,'identity_group_confirmed','identity_review_groups',$operation,json_encode(['digest'=>$digest,'group_id'=>$groupId,'action'=>$action,'summary'=>$summary],JSON_THROW_ON_ERROR)]);return $summary+['replayed'=>false];
        });
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

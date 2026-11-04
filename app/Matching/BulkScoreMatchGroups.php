<?php
declare(strict_types=1);

/** Pure source-ID graph planner. Shared contacts alone never connect graph components. */
final class BulkScoreMatchGroups
{
    public static function build(array $data,callable $identityKey): array
    {
        $customers=array_column($data['stripe_customers'],null,'id');$waivers=array_column($data['cognito_waivers'],null,'id');
        $parent=[];$find=function(string $node)use(&$parent,&$find):string{if(!isset($parent[$node]))$parent[$node]=$node;if($parent[$node]!==$node)$parent[$node]=$find($parent[$node]);return $parent[$node];};
        $join=function(string $a,string $b)use(&$parent,$find):void{$a=$find($a);$b=$find($b);if($a!==$b){if(strcmp($a,$b)>0)[$a,$b]=[$b,$a];$parent[$b]=$a;}};
        $signatures=[];$nodeSignatures=[];$nodeNames=[];$accepted=[];$customerByExternal=[];$waiverByExternal=[];
        foreach($customers as $id=>$c){$node='c:'.$id;$find($node);$customerByExternal[$c['stripe_customer_id']]=$node;$nodeNames[$node]=BulkExactMatchService::personName($c['name']);$nodeSignatures[$node]=$identityKey($c['name'],$c['email_normalized']??$c['email']);}
        foreach($waivers as $id=>$w){$node='w:'.$id;$find($node);$waiverByExternal[$w['source_locator']]=$node;$nodeNames[$node]=BulkExactMatchService::personName($w['signer_print_name']);$nodeSignatures[$node]=$identityKey($w['signer_print_name'],$w['contact_email']);}
        // Exact full name+email and accepted identity IDs are independent, previously authorized anchors.
        foreach($nodeSignatures as $node=>$key)if($key!==null){if(isset($signatures[$key]))$join($node,$signatures[$key]);else $signatures[$key]=$node;}
        foreach($data['identity_links'] as $link){
            if(!in_array($link['match_status'],['automatic','confirmed'],true))continue;
            $node=$link['source_system']==='stripe'&&$link['source_type']==='customer'&&$link['source_role']==='customer'?($customerByExternal[$link['external_id']]??null):($link['source_system']==='cognito'&&$link['source_type']==='waiver'&&$link['source_role']==='signer'?($waiverByExternal[$link['external_id']]??null):null);
            if($node!==null){$accepted[$node][(int)$link['person_id']]=true;$join($node,'p:'.$link['person_id']);}
        }
        $selfStudent=static function(array $w,array $c):bool{
            $n=BulkExactMatchService::personName($c['name']);return $n!==''&&WaiverSourceRole::isStudent($w)&&BulkExactMatchService::name($w['relationship_to_student'])==='self'&&BulkExactMatchService::personName($w['source_name'])===$n&&BulkExactMatchService::personName($w['signer_print_name'])===$n;
        };
        $signerScores=[];$confirmedSigners=[];
        foreach($data['identity_match_candidates'] as $c)if($c['waiver_role']==='signer'&&!empty($c['score_above_half']))$signerScores[$c['stripe_customer_id'].':'.$c['cognito_waiver_id']]=true;
        foreach($data['identity_links'] as $link)if($link['source_system']==='cognito'&&$link['source_type']==='waiver'&&$link['source_role']==='signer'&&$link['match_status']==='confirmed')$confirmedSigners[$link['external_id']]=true;
        $rules=[];$excluded=[];$above=0;$already=0;
        foreach($data['identity_match_candidates'] as $c){
            $id=(int)$c['id'];$qualified=!empty($c['score_above_half']);if($qualified)++$above;
            if(in_array($c['match_status'],['confirmed','linked'],true)){++$already;continue;}
            if(!$qualified){$excluded[$id]='score_not_above_half';continue;}
            $customer=$customers[$c['stripe_customer_id']]??null;$w=$waivers[$c['cognito_waiver_id']]??null;
            if(!$customer||!$w){$excluded[$id]='missing_source_record';continue;}
            if($c['waiver_role']==='signer'&&MatchScorer::comparisonName($customer['name'],false)!==MatchScorer::comparisonName($w['signer_print_name'],false)&&BulkExactMatchService::personName($customer['name'])===BulkExactMatchService::personName($w['signer_print_name'])){
                $email=BulkExactMatchService::email($customer['email_normalized']??$customer['email']);$e=json_decode((string)$c['evidence'],true)?:[];
                if(!(($email!==''&&$email===BulkExactMatchService::email($w['contact_email']))||in_array('exact_address',$e['signals']??[],true)||($customer['person_id']!==null&&$customer['person_id']===$w['signer_person_id']))){$excluded[$id]='variant_contact_required';continue;}
            }
            if($c['waiver_role']==='signer')$rule='A';
            elseif($c['waiver_role']==='source_name_unresolved'&&$selfStudent($w,$customer))$rule='B';
            else{$excluded[$id]=WaiverSourceRole::isStudent($w)?'student_role_separation':'legacy_source_role_ambiguous';continue;}
            if($rule==='B'&&!isset($signerScores[$customer['id'].':'.$w['id']])&&!isset($confirmedSigners[$w['source_locator']])){$excluded[$id]='self_signer_not_score_qualified';continue;}
            $rules[$id]=$rule;$join('c:'.$customer['id'],'w:'.$w['id']);
        }
        $customerKeys=[];$waiverKeys=[];$customerGroups=[];$waiverGroups=[];$names=[];$groups=[];$reasons=[];$personGroups=[];$strong=[];$sourcePeople=[];
        foreach($customers as $id=>$c){$key=hash('sha256','score-component:'.$find('c:'.$id));$customerKeys[$id]=$key;$customerGroups[$key][]=$id;}
        foreach($waivers as $id=>$w){$key=hash('sha256','score-component:'.$find('w:'.$id));$waiverKeys[$id]=$key;$waiverGroups[$key][]=$id;}
        foreach($data['identity_match_candidates'] as $c){$id=(int)$c['id'];if(!isset($rules[$id]))continue;$key=$customerKeys[$c['stripe_customer_id']];$groups[$key]['decisions'][]=['id'=>$id,'rule'=>$rules[$id],'customer_id'=>(int)$c['stripe_customer_id'],'waiver_id'=>(int)$c['cognito_waiver_id'],'score'=>$c['match_score']];
            $a=$nodeSignatures['c:'.$c['stripe_customer_id']];$b=$nodeSignatures['w:'.$c['cognito_waiver_id']];if($a!==null&&$a===$b)$strong[$key]=true;
        }
        $peopleBySignature=[];$peopleByName=[];
        foreach($data['people'] as $person){$n=BulkExactMatchService::personName(trim(implode(' ',[$person['first_name']??'',$person['middle_name']??'',$person['last_name']??''])));if($n!=='')$peopleByName[$n][]=(int)$person['id'];$sig=$identityKey($n,$person['email_normalized']??$person['email']);if($sig!==null)$peopleBySignature[$sig][]=(int)$person['id'];}
        foreach($groups as $key=>$group){
            $allNames=[];$payerSignatures=[];
            foreach($customerGroups[$key]??[] as $id){$node='c:'.$id;$allNames[$nodeNames[$node]]=true;$payerSignatures[$nodeSignatures[$node]??'missing:'.$id]=true;foreach($accepted[$node]??[] as $pid=>$_)$sourcePeople[$key][$pid]=true;if(($sig=$nodeSignatures[$node])!==null)foreach($peopleBySignature[$sig]??[] as $pid)$personGroups[$key][$pid]=$pid;}
            foreach($waiverGroups[$key]??[] as $id){$node='w:'.$id;$allNames[$nodeNames[$node]]=true;foreach($accepted[$node]??[] as $pid=>$_)$sourcePeople[$key][$pid]=true;if(($sig=$nodeSignatures[$node])!==null)foreach($peopleBySignature[$sig]??[] as $pid)$personGroups[$key][$pid]=$pid;}
            if(isset($allNames[''])||count($allNames)!==1)$reasons[$key]='competing_source_names';
            $names[$key]=(string)array_key_first($allNames);
            if(count($customerGroups[$key]??[])>1&&count($payerSignatures)>1&&($sourcePeople[$key]??[])===[])$reasons[$key]??='competing_payer_proposals';
            if(($sourcePeople[$key]??[])===[]&&($personGroups[$key]??[])===[]&&($peopleByName[$names[$key]]??[])!==[])$reasons[$key]??='existing_person_name_ambiguity';
        }
        // Two isolated name-only components must not allocate duplicate identities or join by household contact.
        $byName=[];foreach($groups as $key=>$g)if(!isset($reasons[$key]))$byName[$names[$key]][]=$key;
        foreach($byName as $keys)if(count($keys)>1)foreach($keys as $key)if(empty($strong[$key])&&($sourcePeople[$key]??[])===[]&&($personGroups[$key]??[])===[])$reasons[$key]??='competing_name_only_groups';
        return compact('customerKeys','waiverKeys','customerGroups','waiverGroups','personGroups','groups','excluded','already','above','names','reasons');
    }
}

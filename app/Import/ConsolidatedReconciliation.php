<?php
declare(strict_types=1);
require_once __DIR__.'/ConsolidatedInputs.php';
require_once dirname(__DIR__).'/Matching/BulkExactMatchService.php';
require_once dirname(__DIR__,2).'/src/People.php';

/** Reference reconciliation only: no new matching scores, source imports or financial writes. */
final class ConsolidatedReconciliation
{
    private BulkExactMatchService $bulk;
    public function __construct(private readonly PDO $db){$this->bulk=new BulkExactMatchService($db,BulkExactMatchService::SCORE);}
    public static function approvals(array $rows): array
    {
        if(count($rows)>1000)throw new RuntimeException('APPROVALS_INVALID');$out=[];
        foreach($rows as $group=>$v){if(!preg_match('/\ABMA-W-[0-9]{4,}\z/D',(string)$group)||!is_array($v))throw new RuntimeException('APPROVALS_INVALID');$r=[];
            foreach(['student_verified','payer_verified','payer_student_verified','signer_verified','same_person_verified','signer_is_payer','parent_relationship_verified','contacts_student_owned','student_address_verified'] as $key)$r[$key]=($v[$key]??false)===true;
            foreach(['student_bma','payer_bma','signer_bma'] as $key){$r[$key]=$v[$key]??'';if(!is_string($r[$key])||($r[$key]!==''&&!preg_match('/\ABMA-[0-9]{6,}\z/D',$r[$key])))throw new RuntimeException('APPROVALS_INVALID');}
            $out[$group]=$r;
        }ksort($out);return $out;
    }
    private function counts(): array {$out=[];foreach(['people','members','identity_links','person_relationships','cognito_waivers','stripe_customers','stripe_transactions','stripe_subscriptions','audit_log'] as $t)$out[$t]=(int)$this->db->query('SELECT COUNT(*) FROM '.$t)->fetchColumn();return $out;}
    public function preview(int $actor,array $input,array $approvals=[]): array
    {
        return $this->bulk->reviewTransaction($actor,fn(IdentityAssociations $identity)=>$this->plan($this->bulk->lockedReviewState(),$input,self::approvals($approvals)));
    }
    private static function dob(string $s): ?string
    {
        if(trim($s)==='')return null;foreach(['!Y-m-d','!m/d/Y','!m/d/y'] as $format){$d=DateTimeImmutable::createFromFormat($format,trim($s));$e=DateTimeImmutable::getLastErrors();if($d&&($e===false||($e['warning_count']===0&&$e['error_count']===0)))return $d->format('Y-m-d');}throw new RuntimeException('DOB_INVALID');
    }
    private function plan(array $state,array $input,array $approvals): array
    {
        $data=$state['data'];$waivers=[];foreach($data['cognito_waivers'] as $w)if($w['source_record_id']!==null)$waivers[$w['source_record_id']][]=$w;$customers=array_column($data['stripe_customers'],null,'stripe_customer_id');$people=array_column($data['people'],null,'id');$peopleBma=array_column($data['people'],'id','bma_person_id');$scopes=[];$groupLinks=[];$groupRejected=[];
        foreach($data['identity_links'] as $l){if($l['source_system']==='manual'&&$l['source_type']==='consolidated_waiver'&&in_array($l['match_status'],['rejected','possible'],true))$groupRejected[$l['external_id']]=true;$scopes[$l['source_system'].'/'.$l['external_id'].'/'.$l['source_role']][]=$l;if($l['source_system']==='manual'&&$l['source_type']==='consolidated_waiver'&&$l['match_status']==='confirmed')$groupLinks[$l['external_id']]=(int)$l['person_id'];}
        $held=[];foreach($data['identity_match_candidates'] as $c)if($c['match_reason']==='manual_review_pending'||($c['match_status']==='rejected'&&$c['match_reason']!=='manual_confirm_relationship'))$held[(int)$c['cognito_waiver_id']]=true;
        $groups=[];$seenWaivers=[];$duplicates=[];$payments=[];$paymentRows=[];$evidenceNameConflicts=[];
        foreach($input['groups'] as $g){$id=$g['Group ID'];if(isset($groups[$id]))throw new RuntimeException('DUPLICATE_GROUP_ID');$groups[$id]=$g;}
        foreach($input['duplicates'] as $d){$id=$d['Group ID'];if(!isset($groups[$id])||isset($duplicates[$id])||ConsolidatedInputs::parts($d['Waiver IDs'])!==ConsolidatedInputs::parts($groups[$id]['Waiver IDs'])||(int)$d['Waivers consolidated']!==(int)$groups[$id]['Waiver count'])throw new RuntimeException('DUPLICATE_MANIFEST_CONFLICT');$duplicates[$id]=true;}
        foreach($input['payments'] as $r){if(!isset($groups[$r['Group ID']])||!preg_match('/\A(?:pi|ch|py)_[A-Za-z0-9]+\z/D',$r['Payment ID']))throw new RuntimeException('PAYMENT_REFERENCE_INVALID');if(!MatchScorer::namesCompatible($r['Student'],$groups[$r['Group ID']]['Student name']))$evidenceNameConflicts[$r['Group ID']]=true;$payments[$r['Payment ID']]=true;$paymentRows[$r['Group ID']][]=$r;}
        // Resolve actual imported IDs once. Multiple charges sharing a PaymentIntent remain an ambiguity.
        $found=[];foreach(array_keys($payments) as $id){$q=$this->db->prepare('SELECT id,customer_id FROM stripe_transactions WHERE stripe_charge_id=? OR stripe_payment_intent_id=?');$q->execute([$id,$id]);$found[$id]=$q->fetchAll();}
        $summary=['groups_examined'=>count($groups),'waiver_source_ids_found'=>0,'waiver_source_ids_missing'=>0,'existing_people_reused'=>0,'new_people_proposed'=>0,'student_identities_unresolved'=>0,'signers_to_link'=>0,'payer_associations_to_link'=>0,'proposed_household_groups'=>0,'member_records_supported'=>0,'demographic_profiles_to_populate'=>0,'unique_payment_ids_referenced'=>count($payments),'unique_payments_present'=>0,'missing_source_payments'=>0,'ambiguous_payment_ids'=>0,'conflicts_requiring_review'=>0,'groups_ready'=>0,'already_reconciled'=>0];
        foreach($found as $rows){if($rows===[])++$summary['missing_source_payments'];elseif(count($rows)>1)++$summary['ambiguous_payment_ids'];else ++$summary['unique_payments_present'];}
        $plans=[];$entities=[];$claims=[];$accountOwners=[];
        foreach($groups as $gid=>$g){$a=$approvals[$gid]??self::approvals([$gid=>[]])[$gid];$p=['group_id'=>$gid,'waivers'=>[],'customers'=>[],'student_key'=>null,'payer_key'=>null,'signer_keys'=>[],'entities'=>[],'issues'=>[],'proposals'=>[],'demographics'=>[],'ready'=>false,'changed'=>false,'approval'=>$a];$existing=[];$dates=[];$names=[];$studentPreviouslyReviewed=false;if(isset($groupRejected['bma3y-v1:'.$gid]))$p['issues'][]='MANUAL_GROUP_DECISION_PROTECTED';if(isset($evidenceNameConflicts[$gid]))$p['issues'][]='EVIDENCE_STUDENT_NAME_CONFLICT';$ids=ConsolidatedInputs::parts($g['Waiver IDs']);
            if(count($ids)!==(int)$g['Waiver count'])$p['issues'][]='WAIVER_COUNT_CONFLICT';
            foreach($ids as $id){if(!ctype_digit($id)||isset($seenWaivers[$id])){$p['issues'][]='WAIVER_GROUP_CONFLICT';continue;}$seenWaivers[$id]=$gid;$matches=$waivers[$id]??[];if($matches===[]){++$summary['waiver_source_ids_missing'];$p['issues'][]='WAIVER_SOURCE_MISSING';continue;}if(count($matches)!==1){$p['issues'][]='WAIVER_SOURCE_AMBIGUOUS';continue;}++$summary['waiver_source_ids_found'];$w=$matches[0];$p['waivers'][]=$w;
                if(isset($held[(int)$w['id']]))$p['issues'][]='MANUAL_DECISION_PROTECTED';
                if(!MatchScorer::namesCompatible($g['Student name'],$w['source_name']))$p['issues'][]='STUDENT_NAME_CONFLICT';
                if(!WaiverSourceRole::isStudent($w)&&$w['student_person_id']===null)$p['issues'][]='LEGACY_STUDENT_ROLE_UNVERIFIED';
                if($w['student_birth_date']!==null)$dates[$w['student_birth_date']]=true;
                if($w['student_person_id']!==null)$existing[(int)$w['student_person_id']]=true;
                foreach($scopes['cognito/'.$w['source_locator'].'/student']??[] as $l){if($l['match_status']==='rejected'||($l['match_status']==='possible'&&$l['reviewed_at']!==null))$p['issues'][]='MANUAL_DECISION_PROTECTED';if($l['match_status']==='confirmed'&&$l['reviewed_by']!==null&&$l['reviewed_at']!==null)$studentPreviouslyReviewed=true;if(in_array($l['match_status'],['confirmed','automatic'],true)){if((int)$l['person_id']!==(int)$w['student_person_id'])$p['issues'][]='IDENTITY_CACHE_CONFLICT';$existing[(int)$l['person_id']]=true;}}
            }
            if(isset($groupLinks['bma3y-v1:'.$gid])){$existing[$groupLinks['bma3y-v1:'.$gid]]=true;$studentPreviouslyReviewed=true;}
            if($a['student_bma']!==''){if(!isset($peopleBma[$a['student_bma']]))$p['issues'][]='EXISTING_PERSON_NOT_FOUND';else $existing[(int)$peopleBma[$a['student_bma']]]=true;}
            try{$csvDob=self::dob($g['Student birthdate(s)']);if($csvDob!==null&&$dates!==[]&&!isset($dates[$csvDob]))$p['issues'][]='DOB_CONFLICT';}catch(Throwable){$p['issues'][]='DOB_CONFLICT';}
            foreach(array_keys($dates) as $birth)try{if(self::dob($birth)!==$birth||$birth>AppTime::today())$p['issues'][]='DOB_CONFLICT';}catch(Throwable){$p['issues'][]='DOB_CONFLICT';}
            if(count($dates)>1||count($existing)>1)$p['issues'][]='EXISTING_IDENTITY_OR_DOB_CONFLICT';
            $pid=$existing===[]?null:(int)array_key_first($existing);
            if($pid!==null&&isset($people[$pid])){$person=$people[$pid];if(!MatchScorer::namesCompatible(trim(implode(' ',[$person['first_name'],$person['middle_name'],$person['last_name']])),$g['Student name']))$p['issues'][]='EXISTING_PERSON_NAME_CONFLICT';if($person['birth_date']!==null&&$dates!==[]&&!isset($dates[$person['birth_date']]))$p['issues'][]='DOB_CONFLICT';}
            if(!$studentPreviouslyReviewed&&!$a['student_verified'])$p['issues'][]='STUDENT_ADMIN_VERIFICATION_REQUIRED';
            $studentKey=$pid===null?'student:'.$gid:'person:'.$pid;$p['student_key']=$studentKey;
            $name=$p['waivers'][0]['source_name']??null;$entities[$studentKey]=['person_id'=>$pid,'name'=>$name];$p['entities'][]=$studentKey;
            // A known distinct BMA person with the same compatible name requires explicit selection, not another allocation.
            if($pid===null)foreach($people as $old)if(MatchScorer::namesCompatible(trim(implode(' ',[$old['first_name'],$old['middle_name'],$old['last_name']])),$name))$p['issues'][]='EXISTING_PERSON_SELECTION_REQUIRED';
            $cuIds=ConsolidatedInputs::parts($g['Stripe customer ID(s)']);foreach($paymentRows[$gid]??[] as $r){if($r['Customer ID']!=='')$cuIds[]=$r['Customer ID'];foreach($found[$r['Payment ID']]??[] as $tx)if($r['Customer ID']!==''&&isset($customers[$r['Customer ID']])&&$tx['customer_id']!==null&&(int)$tx['customer_id']!==(int)$customers[$r['Customer ID']]['id'])$p['issues'][]='PAYMENT_CUSTOMER_CONFLICT';}
            $cuIds=array_values(array_unique($cuIds));sort($cuIds);$payerIds=[];
            foreach($cuIds as $id){if(!isset($customers[$id])){$p['proposals'][]='STRIPE_CUSTOMER_MISSING';continue;}$c=$customers[$id];$p['customers'][$id]=$c;if($c['person_id']!==null)$payerIds[(int)$c['person_id']]=true;$accepted=[];foreach($scopes['stripe/'.$id.'/customer']??[] as $link)if(in_array($link['match_status'],['confirmed','automatic'],true))$accepted[]=(int)$link['person_id'];if(($c['person_id']===null&&$accepted!==[])||($c['person_id']!==null&&$accepted!==[(int)$c['person_id']]))$p['issues'][]='IDENTITY_CACHE_CONFLICT';foreach($scopes['stripe/'.$id.'/customer']??[] as $l)if($l['match_status']==='rejected'||($l['match_status']==='possible'&&$l['reviewed_at']!==null))$p['issues'][]='MANUAL_DECISION_PROTECTED';}
            if($a['payer_bma']!==''){if(isset($peopleBma[$a['payer_bma']]))$payerIds[(int)$peopleBma[$a['payer_bma']]]=true;else $p['issues'][]='EXISTING_PERSON_NOT_FOUND';}
            if(count($payerIds)>1)$p['issues'][]='COMPETING_PAYER_IDENTITIES';$payer=$payerIds===[]?null:(int)array_key_first($payerIds);$payerKey=null;
            if($p['customers']!==[]&&($payer!==null||$a['payer_verified'])){
                $sample=reset($p['customers']);$payerKey=$payer===null?'payer:'.array_key_first($p['customers']):'person:'.$payer;$owners=[];foreach($p['customers'] as $c)if(isset($accountOwners[$c['stripe_customer_id']]))$owners[$accountOwners[$c['stripe_customer_id']]]=true;if(count($owners)===1&&$payer===null)$payerKey=array_key_first($owners);elseif(count($owners)>1)$p['issues'][]='PAYER_PROPOSAL_CONFLICT';
                if($a['same_person_verified']){foreach($p['waivers'] as $w)if(BulkExactMatchService::name($w['relationship_to_student'])!=='self'||!MatchScorer::namesCompatible($sample['name'],$w['source_name']))$p['issues'][]='PARENT_STUDENT_ROLE_SEPARATION';if($payer!==null&&$pid!==null&&$payer!==$pid)$p['issues'][]='COMPETING_PAYER_IDENTITIES';$payerKey=$studentKey;if($payer!==null&&$pid===null){if(!isset($people[$payer])||!MatchScorer::namesCompatible(trim(implode(' ',[$people[$payer]['first_name'],$people[$payer]['middle_name'],$people[$payer]['last_name']])),$g['Student name']))$p['issues'][]='EXISTING_PERSON_NAME_CONFLICT';else{$entities[$studentKey]['person_id']=$payer;if($people[$payer]['birth_date']!==null&&$dates!==[]&&!isset($dates[$people[$payer]['birth_date']]))$p['issues'][]='DOB_CONFLICT';}}}
                foreach($p['customers'] as $c)if(!MatchScorer::namesCompatible($sample['name'],$c['name']))$p['issues'][]='COMPETING_PAYER_NAMES';
                if($payerKey!==$studentKey){if(!isset($entities[$payerKey]))$entities[$payerKey]=['person_id'=>$payer,'name'=>$sample['name']];$p['entities'][]=$payerKey;}$p['payer_key']=$payerKey;
            }elseif($p['customers']!==[])$p['proposals'][]='HOUSEHOLD_PAYMENT_ASSOCIATION_UNVERIFIED';
            foreach($p['waivers'] as $w){$signerKey=null;$accepted=[];foreach($scopes['cognito/'.$w['source_locator'].'/signer']??[] as $link){if(in_array($link['match_status'],['confirmed','automatic'],true))$accepted[]=(int)$link['person_id'];if($link['match_status']==='rejected'||($link['match_status']==='possible'&&$link['reviewed_at']!==null))$p['issues'][]='MANUAL_DECISION_PROTECTED';}if(($w['signer_person_id']===null&&$accepted!==[])||($w['signer_person_id']!==null&&$accepted!==[(int)$w['signer_person_id']]))$p['issues'][]='IDENTITY_CACHE_CONFLICT';if($w['signer_person_id']!==null){$signerKey='person:'.$w['signer_person_id'];$entities[$signerKey]=['person_id'=>(int)$w['signer_person_id'],'name'=>$w['signer_print_name']];}
                elseif($a['signer_verified']){if($a['signer_is_payer']&&$payerKey!==null&&MatchScorer::namesCompatible($w['signer_print_name'],$entities[$payerKey]['name']))$signerKey=$payerKey;elseif($a['signer_bma']!==''&&isset($peopleBma[$a['signer_bma']])){$chosen=$people[$peopleBma[$a['signer_bma']]];if(!MatchScorer::namesCompatible(trim(implode(' ',[$chosen['first_name'],$chosen['middle_name'],$chosen['last_name']])),$w['signer_print_name']))$p['issues'][]='SIGNER_NAME_CONFLICT';$signerKey='person:'.$peopleBma[$a['signer_bma']];$entities[$signerKey]=['person_id'=>(int)$peopleBma[$a['signer_bma']],'name'=>$w['signer_print_name']];}else $p['proposals'][]='SIGNER_SELECTION_REQUIRED';}
                if($signerKey!==null){$p['signer_keys'][$w['source_locator']]=$signerKey;$p['entities'][]=$signerKey;}
            }
            if($dates!==[]){$value=(string)array_key_first($dates);$effectivePid=$entities[$studentKey]['person_id'];if($effectivePid===null||$people[$effectivePid]['birth_date']===null)$p['demographics']['birth_date']=$value;}
            if($a['parent_relationship_verified'])foreach($p['waivers'] as $w){$label=BulkExactMatchService::name($w['relationship_to_student']);if(!isset($p['signer_keys'][$w['source_locator']])||$p['signer_keys'][$w['source_locator']]===$studentKey||!in_array($label,['mother','father','parent','guardian','legal guardian'],true))$p['issues'][]='PARENT_RELATIONSHIP_REQUIRES_VERIFICATION';}
            $emails=[];$phones=[];$addresses=[];foreach($p['waivers'] as $w){if(trim((string)$w['contact_email'])!=='')$emails[BulkExactMatchService::email($w['contact_email'])]=$w['contact_email'];if(trim((string)$w['contact_phone'])!=='')$phones[preg_replace('/[^0-9]/','',$w['contact_phone'])]=$w['contact_phone'];if(trim((string)$w['address_text'])!=='')$addresses[preg_replace('/[^\p{L}\p{N}]+/u',' ',BulkExactMatchService::name($w['address_text']))]=true;}
            if($a['contacts_student_owned']){if(count($emails)===1&&($pid===null||empty($people[$pid]['email']))){$p['demographics']['email']=array_values($emails)[0];$p['demographics']['email_normalized']=array_key_first($emails);}if(count($phones)===1&&($pid===null||empty($people[$pid]['phone']))){$p['demographics']['phone']=array_values($phones)[0];$p['demographics']['phone_normalized']=array_key_first($phones);}}
            $csvAddress=trim(preg_replace('/[^\p{L}\p{N}]+/u',' ',BulkExactMatchService::name(implode(' ',array_filter([$g['Address line 1'],$g['Address line 2'],$g['City'],$g['State'],$g['Postal code']]))))??'');
            if($a['student_address_verified']&&count($addresses)===1&&trim((string)array_key_first($addresses))===$csvAddress){foreach(['street_address'=>'Address line 1','address_line2'=>'Address line 2','city'=>'City','state'=>'State','postal_code'=>'Postal code'] as $field=>$source)if(trim($g[$source])!==''&&($pid===null||empty($people[$pid][$field])))$p['demographics'][$field]=$g[$source];}
            elseif($a['student_address_verified']&&count($addresses)>1)$p['proposals'][]='ADDRESS_VALUES_REQUIRE_REVIEW';
            if($payerKey!==null&&!$a['payer_student_verified'])$p['proposals'][]='PAYER_STUDENT_RELATIONSHIP_NOT_CONFIRMED';
            $p['issues']=array_values(array_unique($p['issues']));$p['proposals']=array_values(array_unique($p['proposals']));$p['ready']=$p['issues']===[]&&$p['waivers']!==[];
            if($p['ready']){++$summary['groups_ready'];foreach(array_unique($p['entities']) as $key)$claims[$key]=true;if($p['demographics']!==[])++$summary['demographic_profiles_to_populate'];if($payerKey!==null)foreach($p['customers'] as $c)if($c['person_id']===null)++$summary['payer_associations_to_link'];foreach($p['waivers'] as $w)if(isset($p['signer_keys'][$w['source_locator']])&&$w['signer_person_id']===null)++$summary['signers_to_link'];}
            else{++$summary['student_identities_unresolved'];++$summary['conflicts_requiring_review'];}
            if($p['proposals']!==[])++$summary['proposed_household_groups'];if($p['ready']&&$payerKey!==null&&$a['payer_verified'])foreach($p['customers'] as $c)$accountOwners[$c['stripe_customer_id']]=$payerKey;$plans[$gid]=$p;
        }
        // A repeated unlinked person proposal across two groups is a conflict, never an automatic merge.
        $proposedNames=[];foreach($plans as $gid=>$g)if($g['ready']&&$entities[$g['student_key']]['person_id']===null)$proposedNames[MatchScorer::comparisonName($groups[$gid]['Student name'])][]=$gid;
        foreach($proposedNames as $ids)if(count($ids)>1)foreach($ids as $gid){$plans[$gid]['ready']=false;$plans[$gid]['issues'][]='DUPLICATE_PERSON_PROPOSALS';}
        $payerNames=[];foreach($plans as $gid=>$g)if($g['ready']&&$g['payer_key']!==null&&$g['payer_key']!==$g['student_key']&&$entities[$g['payer_key']]['person_id']===null)$payerNames[MatchScorer::comparisonName($entities[$g['payer_key']]['name'])][$g['payer_key']][]=$gid;
        foreach($payerNames as $keys)if(count($keys)>1)foreach($keys as $gids)foreach($gids as $gid){$plans[$gid]['ready']=false;$plans[$gid]['issues'][]='DUPLICATE_PAYER_PROPOSALS';}
        foreach(['groups_ready','student_identities_unresolved','conflicts_requiring_review','new_people_proposed','existing_people_reused','demographic_profiles_to_populate','signers_to_link','payer_associations_to_link'] as $key)$summary[$key]=0;$claims=[];
        foreach($plans as $g){if(!$g['ready']){++$summary['student_identities_unresolved'];++$summary['conflicts_requiring_review'];continue;}++$summary['groups_ready'];foreach(array_unique($g['entities']) as $key)$claims[$entities[$key]['person_id']===null?$key:'person:'.$entities[$key]['person_id']]=$entities[$key];if($g['demographics']!==[])++$summary['demographic_profiles_to_populate'];foreach($g['customers'] as $c)if($c['person_id']===null&&$g['approval']['payer_verified']&&$g['payer_key']!==null)++$summary['payer_associations_to_link'];foreach($g['waivers'] as $w)if($w['signer_person_id']===null&&isset($g['signer_keys'][$w['source_locator']]))++$summary['signers_to_link'];}
        // Count shared account/link scopes once, and report existing membership evidence only.
        $payerScopes=[];$signerScopes=[];$supportedMembers=[];
        $membersByPerson=array_column($data['members'],'id','person_id');
        foreach($plans as $gid=>$g){if(!$g['ready'])continue;
            $studentId=$entities[$g['student_key']]['person_id'];
            if($studentId!==null&&isset($membersByPerson[$studentId]))$supportedMembers[$membersByPerson[$studentId]]=true;
            if(isset($groupLinks['bma3y-v1:'.$gid]))++$summary['already_reconciled'];
            foreach($g['customers'] as $id=>$c)if($c['person_id']===null&&$g['approval']['payer_verified']&&$g['payer_key']!==null)$payerScopes[$id]=true;
            foreach($g['waivers'] as $w)if($w['signer_person_id']===null&&isset($g['signer_keys'][$w['source_locator']]))$signerScopes[$w['source_locator']]=true;
        }
        $summary['member_records_supported']=count($supportedMembers);
        $summary['payer_associations_to_link']=count($payerScopes);
        $summary['signers_to_link']=count($signerScopes);
        foreach($claims as $entity){if($entity['person_id']===null)++$summary['new_people_proposed'];else ++$summary['existing_people_reused'];}
        return ['summary'=>$summary,'groups'=>$plans,'entities'=>$entities,'before_counts'=>$this->counts(),'digest'=>hash('sha256',json_encode([$state['plan']['digest'],$input,$approvals,$found],JSON_THROW_ON_ERROR))];
    }
    public function apply(int $actor,array $input,array $approvals,string $digest,string $backupReference): array
    {
        if(trim($backupReference)===''||mb_strlen($backupReference)>500||!preg_match('/\A[a-f0-9]{64}\z/D',$digest))throw new RuntimeException('EXPLICIT_REVIEW_AND_BACKUP_REQUIRED');
        return $this->bulk->reviewTransaction($actor,function(IdentityAssociations $identity)use($actor,$input,$approvals,$digest,$backupReference){$p=$this->plan($this->bulk->lockedReviewState(),$input,self::approvals($approvals));if(!hash_equals($p['digest'],$digest))throw new RuntimeException('RECONCILIATION_PREVIEW_STALE');$resolved=[];$created=0;$linked=0;$auditBefore=(int)$this->db->query('SELECT COALESCE(MAX(id),0) FROM audit_log')->fetchColumn();
            foreach($p['groups'] as $g){if(!$g['ready'])continue;foreach(array_unique($g['entities']) as $key){if(isset($resolved[$key]))continue;$entity=$p['entities'][$key];$pid=$entity['person_id'];if($pid===null){$parts=explode(' ',trim(preg_replace('/[\s\p{Z}]+/u',' ',(string)$entity['name'])??''));$first=array_shift($parts);if($first==='')throw new RuntimeException('SOURCE_NAME_REQUIRED');$pid=(int)(new People($this->db))->createInTransaction($first,$parts===[]?null:implode(' ',$parts))['id'];++$created;}$resolved[$key]=$pid;}
                $student=$resolved[$g['student_key']];foreach($g['waivers'] as $w){if($w['student_person_id']===null){$identity->decide('cognito','waiver',$w['source_locator'],'student',$student,'confirmed',$actor);++$linked;}if(isset($g['signer_keys'][$w['source_locator']])&&$w['signer_person_id']===null){$identity->decide('cognito','waiver',$w['source_locator'],'signer',$resolved[$g['signer_keys'][$w['source_locator']]],'confirmed',$actor);++$linked;}}
                if($g['payer_key']!==null){$payer=$resolved[$g['payer_key']];foreach($g['customers'] as $c)if($c['person_id']===null&&$g['approval']['payer_verified']){$identity->decide('stripe','customer',$c['stripe_customer_id'],'customer',$payer,'confirmed',$actor);++$linked;}}
                $edges=[];$a=$g['approval'];if($g['payer_key']!==null&&$a['payer_student_verified']){$payer=$resolved[$g['payer_key']];$edges[]=[$payer,$student,$payer===$student?'self':'payer'];}
                if($a['parent_relationship_verified'])foreach($g['waivers'] as $w){$key=$g['signer_keys'][$w['source_locator']]??null;$signer=$key===null?null:$resolved[$key];$label=BulkExactMatchService::name($w['relationship_to_student']);if($signer===null||$signer===$student||!in_array($label,['mother','father','parent','guardian','legal guardian'],true))throw new RuntimeException('PARENT_RELATIONSHIP_REQUIRES_VERIFICATION');$edges[]=[$signer,$student,in_array($label,['mother','father','parent'],true)?'parent':'guardian'];if(in_array($label,['mother','father','parent'],true))$edges[]=[$student,$signer,'child'];}
                foreach($edges as $edge){$q=$this->db->prepare('SELECT 1 FROM person_relationships WHERE person_id=? AND related_person_id=? AND relationship_type=?');$q->execute($edge);if(!$q->fetchColumn())$identity->supplement('cognito','waiver',$g['waivers'][0]['source_locator'],'student',$student,$actor,false,[$edge]);}
                $identity->mapConsolidatedGroup($g['group_id'],$student,$actor);foreach($g['demographics'] as $field=>$value){if(!in_array($field,['birth_date','email','email_normalized','phone','phone_normalized','street_address','address_line2','city','state','postal_code'],true))throw new RuntimeException('DEMOGRAPHIC_FIELD_INVALID');$sql='UPDATE people SET '.$field.'=? WHERE id=? AND ('.$field." IS NULL OR ".$field."='')";$q=$this->db->prepare($sql);$q->execute([$value,$student]);if($q->rowCount()>0){$q=$this->db->prepare('INSERT INTO audit_log(actor_user_id,action,entity_type,entity_id,metadata) VALUES (?,?,?,?,?)');$q->execute([$actor,'source_demographic_filled','people',(string)$student,json_encode(['fields'=>[$field]],JSON_THROW_ON_ERROR)]);}}
            }
            $after=$this->counts();if((int)$this->db->query('SELECT COALESCE(MAX(id),0) FROM audit_log')->fetchColumn()===$auditBefore)return ['summary'=>$p['summary'],'people_created'=>0,'links_established'=>0,'before_counts'=>$p['before_counts'],'after_counts'=>$after];$after['audit_log']++;$q=$this->db->prepare('INSERT INTO audit_log(actor_user_id,action,entity_type,entity_id,metadata) VALUES (?,?,?,?,?)');$q->execute([$actor,'consolidated_reconciled','consolidated_reconciliation',$digest,json_encode(['backup_reference'=>$backupReference,'groups_ready'=>$p['summary']['groups_ready'],'people_created'=>$created,'links_established'=>$linked,'before_counts'=>$p['before_counts'],'after_counts'=>$after],JSON_THROW_ON_ERROR)]);return ['summary'=>$p['summary'],'people_created'=>$created,'links_established'=>$linked,'before_counts'=>$p['before_counts'],'after_counts'=>$this->counts()];
        });
    }
}

<?php
declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__).'/src/database.php';require dirname(__DIR__).'/app/Members/MemberProfileService.php';require dirname(__DIR__).'/app/Members/StripeAssociationService.php';
$config=bmaConfig();if(($config['APP_ENV']??'')!=='test'||$config['DB_HOST']!=='127.0.0.1'||!str_starts_with($config['DB_DATABASE'],'bma_schema_test_'))throw new RuntimeException('Disposable local database required.');
$db=bmaDatabase($config);$service=new StripeAssociationService($db);$repo=new MemberProfileRepository($db);$profiles=new MemberProfileService($repo);$checks=0;
function assocCheck(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException('FAIL '.$label);++$checks;echo 'PASS '.$label.PHP_EOL;}
function assocReject(callable $work,string $label):void{try{$work();}catch(RuntimeException|InvalidArgumentException){assocCheck(true,$label);return;}throw new RuntimeException('FAIL '.$label);}
$query=static function(string $sql,array $p=[])use($db){$q=$db->prepare($sql);$q->execute($p);return $q;};
$actor=(int)$db->query("SELECT id FROM app_users WHERE role='admin' AND is_active=1 ORDER BY id LIMIT 1")->fetchColumn();
$viewer=(int)$db->query("SELECT id FROM app_users WHERE role='viewer' AND is_active=1 ORDER BY id LIMIT 1")->fetchColumn();
$all=$repo->peoplePage([],0,25,2,'asc');$next=$repo->peoplePage([],50,25,2,'asc');
assocCheck($all['filtered']>50&&count($next['rows'])===25&&array_intersect(array_column($all['rows'],'id'),array_column($next['rows'],'id'))===[],'directory reaches beyond 50 without duplicate people');
foreach([25,50,100,250] as $limit)assocCheck(count($repo->peoplePage([],0,$limit,0,'asc')['rows'])===min($limit,$all['filtered']),'requested directory page size '.$limit);
$ids=[];for($offset=0;$offset<$all['filtered'];$offset+=100)$ids=array_merge($ids,array_column($repo->peoplePage([],$offset,100,0,'asc')['rows'],'id'));
assocCheck(count($ids)===count(array_unique($ids))&&count($ids)===$all['filtered'],'complete directory traversed once');
for($column=0;$column<10;++$column)foreach(['asc','desc'] as $direction){$page=$repo->peoplePage([],0,25,$column,$direction);assocCheck(count($page['rows'])===25,'allowlisted sort column '.$column.' '.$direction);}
assocCheck($repo->peoplePage(['scope'=>'members'],0,250,0,'asc')['filtered']===(int)$db->query('SELECT COUNT(*) FROM members')->fetchColumn(),'Members Only excludes waiver-only people');
foreach(['customer','subscription','payments'] as $key)assocCheck($repo->peoplePage([$key=>'yes'],0,1,0,'asc')['filtered']+$repo->peoplePage([$key=>'no'],0,1,0,'asc')['filtered']===$all['filtered'],'has/no '.$key.' filters partition people');
$bma=$all['rows'][0]['bma_person_id'];assocCheck($repo->peoplePage(['search'=>$bma],0,25,0,'asc')['filtered']===1,'BMA search applies before pagination');
assocCheck($repo->peoplePage(['search'=>"%' OR 1=1 --"],0,25,999,'DROP TABLE')['filtered']===0,'search and arbitrary sort input cannot inject SQL');
assocReject(fn()=>$repo->peoplePage(['age_min'=>'20','age_max'=>'10'],0,25,0,'asc'),'invalid age bounds rejected');
$expected=(int)$query('SELECT COUNT(*) FROM people WHERE birth_date<=?',[AppTime::today()])->fetchColumn();assocCheck($repo->peoplePage(['age_min'=>'0'],0,1,0,'asc')['filtered']===$expected,'age filter excludes unknown/future DOB');
assocCheck($repo->peoplePage(['program'=>'__absent_program__'],0,25,0,'asc')['filtered']===0,'program filter does not infer enrollment from descriptions');
if(!StripeAssociationRepository::attributionReady($db))throw new RuntimeException('Apply new migration locally before integration tests.');
$tag=bin2hex(random_bytes(5));$people=[];$customers=[];$subscriptions=[];$transactions=[];$allocator=new People($db);
$request=static fn(string $id,array $over=[])=>array_replace(['identifier'=>$id,'action'=>'link','role'=>'own','reason'=>'Verified disposable fixture','target_bma'=>'','mapping_id'=>'','new_payer'=>'','new_first_name'=>'','new_last_name'=>'','acknowledge'=>''],$over);
assocCheck(StripeAssociationService::request($request('cus_Fixture',['reason'=>"Line one\r\nLine two"]))['reason']==="Line one\nLine two",'multiline reasons have stable confirmation serialization');
$run=function(int $person,array $r)use($service,$actor){$p=$service->preview($actor,$person,$r);$op=bin2hex(random_bytes(16));$result=$service->apply($actor,$person,$r,$p['digest'],$op);return [$p,$op,$result];};
try{
 foreach(['payer','child','sibling','other'] as $key)$people[$key]=$allocator->create('Fixture'.$tag,ucfirst($key));
 $query("UPDATE people SET middle_name='Q.' WHERE id=?",[$people['child']['id']]);
 assocCheck($repo->peoplePage(['search'=>'Fixture'.$tag.' Q. Child'],0,25,0,'asc')['filtered']===1,'directory retains stored middle-name search');
 assocCheck($repo->peoplePage(['search'=>'Fixture'.$tag.' Child'],0,25,0,'asc')['filtered']===1,'first/last search also finds a person with a middle name');
 $email='lookup-'.$tag.'@example.invalid';$phone='000'.(string)random_int(1000000,9999999);$query('UPDATE people SET email=?,phone=? WHERE id=?',[$email,$phone,$people['other']['id']]);
 assocCheck(in_array($people['other']['id'],array_column($repo->peoplePage(['search'=>$email],0,250,0,'asc')['rows'],'id')),'existing email search remains available');
 assocCheck(in_array($people['other']['id'],array_column($repo->peoplePage(['search'=>$phone],0,250,0,'asc')['rows'],'id')),'existing phone search remains available');
 foreach(['child','sibling'] as $key)$query("INSERT INTO members(person_id,member_status) VALUES (?,'active')",[$people[$key]['id']]);
 $memberCount=(int)$db->query('SELECT COUNT(*) FROM members')->fetchColumn();
 foreach(['family','single','newpayer'] as $key){$id='cus_'.$tag.$key;$query('INSERT INTO stripe_customers(stripe_customer_id,name,email) VALUES (?,?,?)',[$id,'Fixture <img src=x onerror=alert(1)>','fixture@example.invalid']);$customers[$key]=['id'=>(int)$db->lastInsertId(),'external'=>$id];}
 $r=$request($customers['newpayer']['external'],['role'=>'payer','new_payer'=>'1','new_first_name'=>'NewFixture'.$tag,'new_last_name'=>'Payer']);[$newPreview,$newOperation,$newResult]=$run((int)$people['child']['id'],$r);$people['newpayer']=['id'=>$newResult['person_id']];$service->apply($actor,(int)$people['child']['id'],$r,$newPreview['digest'],$newOperation);
 $newPerson=$query('SELECT first_name,email,birth_date FROM people WHERE id=?',[$newResult['person_id']])->fetch();assocCheck($newPerson['first_name']==='NewFixture'.$tag&&$newPerson['email']===null&&$newPerson['birth_date']===null,'controlled new payer creation preserves distinct identity without invented demographics');
 assocCheck((int)$query("SELECT COUNT(*) FROM person_relationships WHERE person_id=? AND related_person_id=? AND relationship_type='payer'",[$newResult['person_id'],$people['child']['id']])->fetchColumn()===1,'new payer replay creates exactly one relationship');
 $r=$request($customers['family']['external']);[$p,$op,$result]=$run((int)$people['payer']['id'],$r);$audit=(int)$db->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();$again=$service->apply($actor,(int)$people['payer']['id'],$r,$p['digest'],$op);
 assocCheck($again===$result&&(int)$db->query('SELECT COUNT(*) FROM audit_log')->fetchColumn()===$audit,'repeated confirmation returns receipt without duplicate audit/association');
 assocReject(fn()=>$service->apply($actor,(int)$people['payer']['id'],$request($customers['family']['external'],['reason'=>'changed']),$p['digest'],$op),'operation token cannot be reused for changed request');
 assocReject(fn()=>$query('UPDATE stripe_customers SET person_id=NULL WHERE id=?',[$customers['family']['id']]),'database rejects direct cached-owner edits outside the authoritative service');assocCheck((int)$query('SELECT person_id FROM stripe_customers WHERE id=?',[$customers['family']['id']])->fetchColumn()===(int)$people['payer']['id'],'accepted identity and cached owner remain synchronized');
 foreach(['child','sibling'] as $key)$run((int)$people[$key]['id'],$request($customers['family']['external'],['role'=>'payer','target_bma'=>$people['payer']['bma_person_id']]));
 assocCheck((int)$query("SELECT COUNT(*) FROM person_relationships WHERE person_id=? AND relationship_type='payer'",[$people['payer']['id']])->fetchColumn()===2,'verified payer links separate siblings without merging people');
 $owner=$query('SELECT person_id FROM stripe_customers WHERE id=?',[$customers['family']['id']])->fetchColumn();assocCheck((int)$owner===(int)$people['payer']['id'],'payer remains customer owner');
 $r=$request($customers['family']['external']);$p=$service->preview($actor,(int)$people['other']['id'],$r);
 assocReject(fn()=>$service->apply($actor,(int)$people['other']['id'],$r,$p['digest'],bin2hex(random_bytes(16))),'conflicting accepted owner blocks silent reassignment');
 assocCheck((int)$query('SELECT person_id FROM stripe_customers WHERE id=?',[$customers['family']['id']])->fetchColumn()===(int)$owner,'conflict rejection rolls back and preserves cached owner');
 $run((int)$people['other']['id'],$request($customers['single']['external']));$run((int)$people['other']['id'],$request($customers['single']['external'],['action'=>'remove']));
 assocCheck($query('SELECT person_id FROM stripe_customers WHERE id=?',[$customers['single']['id']])->fetchColumn()===null,'removing own account retains source customer and clears authoritative cache');
 $rejected=$request($customers['single']['external']);$rejectedPreview=$service->preview($actor,(int)$people['other']['id'],$rejected);assocReject(fn()=>$service->apply($actor,(int)$people['other']['id'],$rejected,$rejectedPreview['digest'],bin2hex(random_bytes(16))),'manual rejection requires explicit administrator override');
 $run((int)$people['other']['id'],$request($customers['single']['external'],['acknowledge'=>'1']));
 $r=$request($customers['single']['external'],['acknowledge'=>'1']);$p=$service->preview($actor,(int)$people['payer']['id'],$r);$service->apply($actor,(int)$people['payer']['id'],$r,$p['digest'],bin2hex(random_bytes(16)));
 assocCheck((int)$query('SELECT person_id FROM stripe_customers WHERE id=?',[$customers['single']['id']])->fetchColumn()===(int)$people['payer']['id'],'explicit correction changes only accepted customer identity');
 $run((int)$people['child']['id'],$request($customers['family']['external'],['role'=>'payer','target_bma'=>$people['payer']['bma_person_id'],'action'=>'remove']));
 assocCheck((int)$query("SELECT COUNT(*) FROM person_relationships WHERE person_id=? AND related_person_id=? AND relationship_type='payer'",[$people['payer']['id'],$people['sibling']['id']])->fetchColumn()===1,'removing one student payer relationship preserves sibling relationship and owner');
 $run((int)$people['child']['id'],$request($customers['family']['external'],['role'=>'payer','target_bma'=>$people['payer']['bma_person_id']]));
 $sub='sub_'.$tag;$query('INSERT INTO stripe_subscriptions(stripe_subscription_id,customer_id,status) VALUES (?,?,?)',[$sub,$customers['family']['id'],'active']);$subscriptions[]=(int)$db->lastInsertId();$sid=$subscriptions[0];
 assocReject(fn()=>$service->preview($actor,(int)$people['other']['id'],$request($sub)),'subscription never creates a member automatically');
 $query('INSERT INTO stripe_subscription_items(subscription_id,source_locator,product_name_snapshot) VALUES (?,?,?)',[$sid,'fixture'.$tag,'Fixture program '.$tag]);
 $run((int)$people['child']['id'],$request($sub));assocCheck($repo->peoplePage(['program'=>'Fixture program '.$tag],0,25,6,'asc')['filtered']===1,'program filter uses explicit assignment and never counts parent as student');$r=$request($sub);$p=$service->preview($actor,(int)$people['sibling']['id'],$r);
 assocReject(fn()=>$service->apply($actor,(int)$people['sibling']['id'],$r,$p['digest'],bin2hex(random_bytes(16))),'existing student subscription assignment requires explicit shared approval');
 $run((int)$people['sibling']['id'],$request($sub,['acknowledge'=>'1']));assocCheck((int)$query('SELECT COUNT(*) FROM subscription_members WHERE subscription_id=?',[$sid])->fetchColumn()===2,'explicitly shared subscription preserves both members');
 $map=$query('SELECT map.id FROM subscription_members map JOIN members m ON m.id=map.member_id WHERE map.subscription_id=? AND m.person_id=?',[$sid,$people['child']['id']])->fetchColumn();
 $run((int)$people['child']['id'],$request($sub,['mapping_id'=>(string)$map,'target_bma'=>$people['sibling']['bma_person_id'],'acknowledge'=>'1']));assocCheck((int)$query('SELECT COUNT(*) FROM subscription_members WHERE subscription_id=?',[$sid])->fetchColumn()===1,'editing same-day assignment retains audit snapshot without duplicate target assignment');
 $map=$query('SELECT id FROM subscription_members WHERE subscription_id=?',[$sid])->fetchColumn();$query('UPDATE subscription_members SET valid_from=? WHERE id=?',[AppTime::now()->modify('-3 days')->format('Y-m-d'),$map]);$run((int)$people['sibling']['id'],$request($sub,['mapping_id'=>(string)$map,'action'=>'remove']));
 assocCheck($query('SELECT valid_until FROM subscription_members WHERE id=?',[$map])->fetchColumn()<AppTime::today(),'removing older subscription assignment closes and retains history');
 assocCheck((int)$query('SELECT customer_id FROM stripe_subscriptions WHERE id=?',[$sid])->fetchColumn()===$customers['family']['id'],'subscription owner and billing source remain unchanged');
 foreach([['paid',10000,1000,$customers['family']['id']],['failed',4000,0,$customers['family']['id']],['refunded',5000,5000,$customers['family']['id']],['paid',1000,0,null]] as $index=>[$status,$amount,$refund,$customer]){$external='ch_'.$tag.$index;$intent='pi_'.$tag.$index;$query('INSERT INTO stripe_transactions(stripe_charge_id,stripe_payment_intent_id,customer_id,status,amount_minor,amount_refunded_minor,currency,captured,stripe_created_at,description) VALUES (?,?,?,?,?,?,?,1,UTC_TIMESTAMP(6),?)',[$external,$intent,$customer,$status,$amount,$refund,'usd','Fixture lesson']);$transactions[]=(int)$db->lastInsertId();}
 $marks=implode(',',array_fill(0,count($transactions),'?'));$sourceBefore=$query("SELECT * FROM stripe_transactions WHERE id IN ($marks) ORDER BY id",$transactions)->fetchAll();
 $r=$request('pi_'.$tag.'3');[$p,$op,$result]=$run((int)$people['child']['id'],$r);$service->apply($actor,(int)$people['child']['id'],$r,$p['digest'],$op);
 assocCheck((int)$query('SELECT COUNT(*) FROM stripe_transaction_attributions WHERE transaction_id=?',[$transactions[3]])->fetchColumn()===1,'PaymentIntent lookup allocates existing transaction exactly once');
 $output=[];exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../bin/rollback-profile-attribution.php').' --apply 2>&1',$output,$rollbackCode);assocCheck($rollbackCode!==0&&StripeAssociationRepository::attributionReady($db),'rollback refuses nonempty allocations without deleting data');
 $profile=$profiles->profile(new MemberProfileIdentifier(null,null,(int)$people['child']['id']),'usd',false);$total=$profile['totals'][0];
 assocCheck((int)$total['transaction_count']===4&&(int)$total['payment_count']===2&&(int)$total['failed_count']===1&&(int)$total['fully_refunded_count']===1,'profile refresh includes explicit payment and distinct source statuses');
 assocCheck((int)$total['gross_paid_minor']===11000&&(int)$total['collections_before_fees_minor']===10000&&$total['stripe_fees_minor']===null,'failed/full refunds do not inflate gross or double-reduce collections; fees unknown');
 $run((int)$people['child']['id'],$request('ch_'.$tag.'0',['acknowledge'=>'1']));$profile=$profiles->profile(new MemberProfileIdentifier(null,null,(int)$people['child']['id']),'usd',false);assocCheck((int)$profile['totals'][0]['transaction_count']===4&&!$profile['exclusive'],'direct allocation plus shared account does not duplicate charge or attribute household revenue');
 $r=$request('ch_'.$tag.'3');$p=$service->preview($actor,(int)$people['sibling']['id'],$r);assocReject(fn()=>$service->apply($actor,(int)$people['sibling']['id'],$r,$p['digest'],bin2hex(random_bytes(16))),'competing sibling payment allocation requires explicit correction');
 $run((int)$people['sibling']['id'],$request('ch_'.$tag.'3',['acknowledge'=>'1']));$run((int)$people['sibling']['id'],$request('ch_'.$tag.'3',['action'=>'remove']));
 assocCheck($query('SELECT customer_id FROM stripe_transactions WHERE id=?',[$transactions[3]])->fetchColumn()===null,'removing payment allocation preserves originally unassigned ownership');
 assocCheck($query("SELECT * FROM stripe_transactions WHERE id IN ($marks) ORDER BY id",$transactions)->fetchAll()===$sourceBefore,'all source transaction fields preserved through allocation/correction/removal');
 $r=$request('ch_'.$tag.'3');$p=$service->preview($actor,(int)$people['child']['id'],$r);$query('UPDATE stripe_transactions SET description=? WHERE id=?',['Changed local fixture',$transactions[3]]);assocReject(fn()=>$service->apply($actor,(int)$people['child']['id'],$r,$p['digest'],bin2hex(random_bytes(16))),'stale preview is rejected transactionally');
 assocReject(fn()=>$service->preview($viewer,(int)$people['child']['id'],$r),'viewer cannot use association service');assocReject(fn()=>$service->preview($actor,(int)$people['child']['id'],$request('cus_bad;DROP')),'invalid Stripe identifier rejected');
 $db->beginTransaction();try{$query('UPDATE app_users SET is_active=0 WHERE id=?',[$actor]);assocReject(fn()=>$service->preview($actor,(int)$people['child']['id'],$r),'inactive administrator denied');}finally{$db->rollBack();}
 $db->beginTransaction();try{$query("UPDATE role_dashboard_permissions p JOIN dashboards d ON d.id=p.dashboard_id SET p.can_view=0 WHERE p.role='admin' AND d.dashboard_key='members'");assocReject(fn()=>$service->preview($actor,(int)$people['child']['id'],$r),'administrator still requires Members dashboard permission');}finally{$db->rollBack();}
 $db->beginTransaction();try{$query("DELETE FROM schema_migrations WHERE version='012_profile_transaction_attribution'");assocCheck(!StripeAssociationRepository::attributionReady($db),'untracked or incomplete migration cannot enable attribution');assocReject(fn()=>$service->preview($actor,(int)$people['child']['id'],$r),'payment review refuses an untracked attribution table');}finally{$db->rollBack();}
 assocCheck((int)$db->query('SELECT COUNT(*) FROM members')->fetchColumn()===$memberCount,'association editing never creates or activates memberships');
 $audit=$query("SELECT metadata FROM audit_log WHERE action='profile_stripe_association' AND entity_id=?",[$op])->fetchColumn();$audit=json_decode($audit,true);assocCheck(isset($audit['before'],$audit['after'],$audit['reason'],$audit['request_hash'],$audit['result']),'audit records include before/after, reason, actor-backed receipt and action');
}finally{
 foreach($transactions as $id){$query('DELETE FROM stripe_transaction_attributions WHERE transaction_id=?',[$id]);$query('DELETE FROM stripe_transactions WHERE id=?',[$id]);}
 foreach($subscriptions as $id){$query('DELETE FROM subscription_members WHERE subscription_id=?',[$id]);$query('DELETE FROM stripe_subscription_items WHERE subscription_id=?',[$id]);$query('DELETE FROM stripe_subscriptions WHERE id=?',[$id]);}
 foreach($customers as $c){$query("DELETE FROM identity_links WHERE source_system='stripe' AND source_type='customer' AND external_id=?",[$c['external']]);$query('DELETE FROM stripe_customers WHERE id=?',[$c['id']]);}
 foreach($people as $p){$query('DELETE FROM person_relationships WHERE person_id=? OR related_person_id=?',[$p['id'],$p['id']]);$query('DELETE FROM members WHERE person_id=?',[$p['id']]);$query('DELETE FROM people WHERE id=?',[$p['id']]);}
}
echo "$checks directory and association checks passed; disposable fixtures removed.\n";

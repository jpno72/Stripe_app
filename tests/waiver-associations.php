<?php
declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__).'/src/database.php';
require dirname(__DIR__).'/src/People.php';
require dirname(__DIR__).'/app/Members/MemberProfileRepository.php';
$config=bmaConfig();if(PHP_SAPI!=='cli'||$config['APP_ENV']!=='test'||$config['DB_HOST']!=='127.0.0.1'||!str_starts_with($config['DB_DATABASE'],'bma_schema_test_'))throw new RuntimeException('Disposable local database required');
$db=bmaDatabase($config);$service=new WaiverAssociationService($db);$repo=new MemberProfileRepository($db);$q=static function(string $sql,array $p=[])use($db):PDOStatement{$s=$db->prepare($sql);$s->execute($p);return $s;};$checks=0;
function waiverCheck(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException('FAIL '.$label);++$checks;echo 'PASS '.$label.PHP_EOL;}
function waiverReject(callable $fn,string $label):void{try{$fn();}catch(RuntimeException|InvalidArgumentException){waiverCheck(true,$label);return;}throw new RuntimeException('FAIL '.$label);}
$actor=(int)$q("SELECT id FROM app_users WHERE role='admin' AND is_active=1 LIMIT 1")->fetchColumn();$viewer=(int)$q("SELECT id FROM app_users WHERE role='viewer' AND is_active=1 LIMIT 1")->fetchColumn();if(!$actor||!$viewer)throw new RuntimeException('Local test admin/viewer required');
$p=['id'=>1,'first_name'=>'Alexander','last_name'=>'Smith','middle_name'=>null,'birth_date'=>null];$w=['source_student_first_name'=>'Alex','source_student_last_name'=>'Smith','source_name_role'=>'student','contact_email'=>'family@example.invalid'];
waiverCheck(WaiverAssociationService::evidence($p,$w,'student',['family@example.invalid'],[])['classification']==='strong','curated nickname plus contact evidence is a suggestion, never an automatic link');
$p['first_name']='John';$p['middle_name']='A.';$w['source_student_first_name']='John';waiverCheck(WaiverAssociationService::evidence($p,$w,'student',['family@example.invalid'],[])['classification']==='strong','middle initial ignored only for comparison');
$p['first_name']='Patricia';$p['middle_name']=null;$w['source_student_first_name']='Pat';waiverCheck(WaiverAssociationService::evidence($p,$w,'student',['family@example.invalid'],[])['classification']==='ambiguous','ambiguous nickname remains review-only');
$p['first_name']='Jane';$w['source_student_first_name']='Johnny';waiverCheck(WaiverAssociationService::evidence($p,$w,'student',['family@example.invalid'],[])['classification']==='ambiguous','household email with different first name is ambiguous');
$w['source_student_first_name']='Jane';$w['contact_phone']='5705551212';$w['source_name_role']='unresolved';waiverCheck(WaiverAssociationService::evidence($p,$w,'student',[],['5705551212'])['classification']==='ambiguous','legacy source name ambiguity retained');
waiverCheck(WaiverAssociationService::evidence($p,$w,'emergency',['family@example.invalid'],[])===null,'generic waiver email does not identify a missing emergency contact');
waiverCheck(WaiverAssociationService::ready($db),'new reviewed-role schema tracked');
$seen=[];$total=$repo->peoplePage([],0,1,10,'desc',true)['total'];
for($offset=0;$offset<$total;$offset+=100)$seen=array_merge($seen,array_column($repo->peoplePage([], $offset,100,10,'asc',true)['rows'],'id'));
waiverCheck(count($seen)===$total&&count(array_unique($seen))===$total,'association sort traverses complete directory beyond first 50 people');
foreach([25,50,100,250] as $limit)waiverCheck(count($repo->peoplePage([],0,$limit,10,'desc',true)['rows'])===min($limit,$total),'association column supports page size '.$limit);
$tag=bin2hex(random_bytes(5));$persons=[];$waivers=[];$sourceBefore=[];$counts=[];
foreach(['people','members','stripe_transactions','stripe_subscriptions','person_relationships'] as $table)$counts[$table]=(int)$q('SELECT COUNT(*) FROM '.$table)->fetchColumn();
$allocator=new People($db);$ids=new IdentityAssociations($db);
try{
    foreach([['payer','Alex','Fixture'.$tag],['student','Child','Fixture'.$tag],['other','Child','Other'.$tag]] as [$k,$first,$last])$persons[$k]=$allocator->create($first,$last);
    $person=(int)$persons['payer']['id'];$child=(int)$persons['student']['id'];$other=(int)$persons['other']['id'];
    $q('UPDATE people SET email=?,phone=? WHERE id=?',['fixture-'.$tag.'@example.invalid','5705551212',$person]);
    foreach([0,1,2,3] as $i){$student=$i<3?$child:$other;$loc='fixture_'.$tag.'_'.$i;$q("INSERT INTO cognito_waivers(source_id_kind,form_namespace,source_locator,source_record_id,content_hash,source_format,source_name_role,source_student_first_name,source_student_last_name,source_name,signer_print_name,contact_email,contact_phone,emergency_contact_name,emergency_contact_phone,relationship_to_student,student_birth_date) VALUES ('external','test',?,?,?,'bma2_v1','student','Child',?,?,?,?,'5705551212',?,'5705551212','Mother','2012-01-01')",[$loc,$loc,hash('sha256',$loc),$i<3?'Fixture'.$tag:'Other'.$tag,'Child '.($i<3?'Fixture'.$tag:'Other'.$tag),'Alexander A. Fixture'.$tag,'fixture-'.$tag.'@example.invalid','Alex Fixture'.$tag]);$waivers[]=(int)$db->lastInsertId();$ids->decide('cognito','waiver',$loc,'student',$student,'confirmed',$actor,false);}
    foreach($waivers as $wid)$sourceBefore[$wid]=$q('SELECT source_name,signer_print_name,contact_email,contact_phone,emergency_contact_name,emergency_contact_phone,relationship_to_student,content_hash FROM cognito_waivers WHERE id=?',[$wid])->fetch();
    waiverReject(fn()=>$service->discover($viewer,$person),'viewer cannot discover cross-person source evidence');
    $service->discover($actor,$person);$groups=$service->groups($actor,$person);waiverCheck(count($groups)===2,'repeated waivers grouped by two distinct accepted students');
    waiverCheck(count($groups[0]['waivers'])===3&&count($groups[1]['waivers'])===1,'repeated source submissions count once each despite multiple roles');
    $r=$q("SELECT * FROM waiver_association_reviews WHERE person_id=? AND waiver_id=? AND source_role='signer'",[$person,$waivers[0]])->fetch();waiverCheck($r['classification']==='strong','payer signer nickname plus phone discovered on unlinked waiver');
    $run=static function(string $action,string $role)use($service,$actor,$person,$r){$service->decide($actor,$person,(int)$r['id'],$action,$role,$r['source_digest'],'Verified disposable source evidence');};
    $n=(int)$q('SELECT COUNT(*) FROM waiver_association_reviews WHERE person_id=?',[$person])->fetchColumn();$service->discover($actor,$person);waiverCheck((int)$q('SELECT COUNT(*) FROM waiver_association_reviews WHERE person_id=?',[$person])->fetchColumn()===$n,'repeated discovery creates no duplicate proposals');
    $run('confirm','signer');$run('confirm','signer');waiverCheck((int)$q('SELECT signer_person_id FROM cognito_waivers WHERE id=?',[$waivers[0]])->fetchColumn()===$person,'explicit signer confirmation synchronizes authoritative cache');
    waiverCheck((int)$q("SELECT COUNT(*) FROM identity_links WHERE person_id=? AND source_role='signer' AND match_status='confirmed'",[$person])->fetchColumn()===1,'repeated confirmation is idempotent');
    $er=$q("SELECT * FROM waiver_association_reviews WHERE person_id=? AND waiver_id=? AND source_role='emergency'",[$person,$waivers[0]])->fetch();$service->decide($actor,$person,(int)$er['id'],'confirm','emergency',$er['source_digest'],'Verified contact role');
    waiverCheck((int)$q("SELECT COUNT(*) FROM identity_links WHERE person_id=? AND source_role='student'",[$person])->fetchColumn()===0,'emergency identity never misuses student links');
    waiverCheck((int)$q('SELECT COUNT(*) FROM person_relationships WHERE person_id=?',[$person])->fetchColumn()===0,'signer and emergency identity do not infer parentage or payer');
    $q('UPDATE people SET email=? WHERE id=?',['fixture-'.$tag.'@example.invalid',$other]);
    $service->discover($actor,$other);$wrong=$q("SELECT * FROM waiver_association_reviews WHERE person_id=? AND waiver_id=? AND source_role='student'",[$other,$waivers[0]])->fetch();
    waiverCheck((bool)$wrong,'shared email exposes a conflicting proposal without merging identities');
    waiverReject(fn()=>$service->decide($actor,$other,(int)$wrong['id'],'confirm','student',$wrong['source_digest'],'Wrong existing student'),'accepted student association cannot be reassigned');
    $reject=$q("SELECT * FROM waiver_association_reviews WHERE person_id=? AND waiver_id=? AND source_role='student'",[$person,$waivers[0]])->fetch();$service->decide($actor,$person,(int)$reject['id'],'reject','student',$reject['source_digest'],'Different household person');$service->discover($actor,$person);
    waiverCheck($q('SELECT review_status FROM waiver_association_reviews WHERE id=?',[$reject['id']])->fetchColumn()==='rejected','rejections survive rediscovery');
    waiverReject(fn()=>$service->decide($actor,$person,(int)$reject['id'],'confirm','student',$reject['source_digest'],'Attempted reassignment'),'existing rejection cannot be overwritten');
    $hr=$q("SELECT * FROM waiver_association_reviews WHERE person_id=? AND waiver_id=? AND source_role='student'",[$person,$waivers[1]])->fetch();$service->decide($actor,$person,(int)$hr['id'],'hold','student',$hr['source_digest'],'Need independent review');
    waiverReject(fn()=>$service->decide($actor,$person,(int)$hr['id'],'confirm','student',$hr['source_digest'],'Attempted override'),'manual hold blocks confirmation');
    $service->decide($actor,$person,(int)$hr['id'],'resume','student',$hr['source_digest'],'Explicitly resume discovery review');
    waiverCheck($q('SELECT review_status FROM waiver_association_reviews WHERE id=?',[$hr['id']])->fetchColumn()==='possible','explicit resume reopens only this discovery hold');
    waiverCheck((int)$q('SELECT student_person_id FROM cognito_waivers WHERE id=?',[$waivers[1]])->fetchColumn()===$child,'resuming review never changes student identity');
    $pr=$q("SELECT * FROM waiver_association_reviews WHERE person_id=? AND waiver_id=? AND source_role='signer'",[$person,$waivers[2]])->fetch();$service->decide($actor,$person,(int)$pr['id'],'confirm','payer',$pr['source_digest'],'Verified payer only');
    waiverCheck((int)$q("SELECT COUNT(*) FROM person_relationships WHERE person_id=? AND related_person_id=? AND relationship_type='payer'",[$person,$child])->fetchColumn()===1,'explicit payer relationship keeps separate people');
    waiverReject(fn()=>$service->decide($actor,$person,(int)$pr['id'],'confirm','student',$pr['source_digest'],'Wrong role'),'role cannot silently change into a student identity');
    $rows=$repo->peoplePage(['search'=>$persons['payer']['bma_person_id']],0,25,10,'desc',true)['rows'];waiverCheck(count($rows)===1&&(int)$rows[0]['association_roles']>=3,'sortable association summary uses distinct roles');
    waiverCheck($repo->peoplePage(['associations'=>'review','search'=>$persons['payer']['bma_person_id']],0,25,10,'asc',true)['filtered']===1,'cached possible/held review filter works before pagination');
    waiverCheck($repo->peoplePage(['associations'=>'people','search'=>$persons['payer']['bma_person_id']],0,25,10,'asc',true)['filtered']===1,'multiple students include distinct cached candidate students, not repeated waiver totals');
    waiverCheck($repo->peoplePage(['associations'=>'roles','search'=>$persons['payer']['bma_person_id']],0,25,10,'asc',true)['filtered']===1,'multiple role filter applies at database grain');
    waiverCheck($repo->peoplePage(['associations'=>'multiple','search'=>$persons['payer']['bma_person_id']],0,25,10,'asc',true)['filtered']===1,'either-condition multiple association filter');
    waiverReject(fn()=>$repo->peoplePage(['associations'=>'review'],0,25,0,'asc'),'ordinary directory callers cannot request sensitive filters');
    $service->decide($actor,$person,(int)$pr['id'],'confirm','parent',$pr['source_digest'],'Independently verified legal parent');
    $foreign=$q("SELECT * FROM waiver_association_reviews WHERE person_id=? AND waiver_id=? AND source_role='signer'",[$person,$waivers[3]])->fetch();
    $db->exec("CREATE TRIGGER test_waiver_audit_failure BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.action='waiver_association_review' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Disposable audit failure'; END IF; END");
    try{waiverReject(fn()=>$service->decide($actor,$person,(int)$foreign['id'],'confirm','signer',$foreign['source_digest'],'Rollback fixture'),'audit failure rolls back authoritative identity decision');}finally{$db->exec('DROP TRIGGER test_waiver_audit_failure');}
    waiverCheck($q('SELECT signer_person_id FROM cognito_waivers WHERE id=?',[$waivers[3]])->fetchColumn()===null,'failed audit leaves signer unassigned');
    $q('UPDATE cognito_waivers SET contact_phone=? WHERE id=?',['5705550000',$waivers[3]]);waiverReject(fn()=>$service->decide($actor,$person,(int)$foreign['id'],'confirm','signer',$foreign['source_digest'],'Stale source'),'changed source requires rediscovery');$q('UPDATE cognito_waivers SET contact_phone=? WHERE id=?',['5705551212',$waivers[3]]);
    foreach($sourceBefore as $wid=>$source)waiverCheck($q('SELECT source_name,signer_print_name,contact_email,contact_phone,emergency_contact_name,emergency_contact_phone,relationship_to_student,content_hash FROM cognito_waivers WHERE id=?',[$wid])->fetch()===$source,'original waiver source fields preserved '.$wid);
    waiverCheck((int)$q('SELECT COUNT(*) FROM members')->fetchColumn()===$counts['members'],'no memberships created');
    waiverCheck((int)$q("SELECT COUNT(*) FROM audit_log WHERE action='waiver_association_review' AND entity_id=?",[(string)$waivers[0]])->fetchColumn()===3,'individual reviews audited exactly once, including rejection');
}finally{
    foreach($waivers as $wid){$q('DELETE FROM waiver_association_reviews WHERE waiver_id=?',[$wid]);$loc=$q('SELECT source_locator FROM cognito_waivers WHERE id=?',[$wid])->fetchColumn();$ids->transaction(function(IdentityAssociations $i)use($db,$q,$loc,$wid){foreach($q("SELECT source_role,person_id FROM identity_links WHERE source_system='cognito' AND external_id=? AND match_status='confirmed'",[$loc])->fetchAll() as $l)$i->reopenAccepted('cognito','waiver',$loc,$l['source_role'],(int)$l['person_id'],(int)$db->query("SELECT id FROM app_users WHERE role='admin' AND is_active=1 LIMIT 1")->fetchColumn());});$q("DELETE FROM identity_links WHERE source_system='cognito' AND external_id=?",[$loc]);$q('DELETE FROM cognito_waivers WHERE id=?',[$wid]);}
    foreach($persons as $p){$q('DELETE FROM waiver_association_reviews WHERE person_id=?',[$p['id']]);$q('DELETE FROM person_relationships WHERE person_id=? OR related_person_id=?',[$p['id'],$p['id']]);$q('DELETE FROM people WHERE id=?',[$p['id']]);}
}
echo "$checks focused waiver association checks passed; disposable fixture rows removed.\n";

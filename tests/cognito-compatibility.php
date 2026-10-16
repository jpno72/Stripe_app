<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once dirname(__DIR__).'/app/Import/bootstrap.php';
require_once dirname(__DIR__).'/app/Matching/bootstrap.php';
require_once dirname(__DIR__).'/src/Migrations.php';
$checks=0;
function cogCheck(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException('FAIL '.$label);++$checks;echo 'PASS '.$label.PHP_EOL;}
function cogThrows(callable $fn,string $code,string $label):void{try{$fn();}catch(RuntimeException $e){cogCheck($e->getMessage()===$code,$label);return;}cogCheck(false,$label);}
$mapping=(require dirname(__DIR__).'/config/import-mappings.php')['cognito_waivers_bma2_v1'];
$headers=array_keys($mapping);$headers[]='Future Field';
$base=[CognitoSource::ID=>'1','StudentName_First'=>'Student','StudentName_Last'=>'Example','Phone'=>'5705551212','TextFriendly'=>'Yes','StudentBirthdate'=>'1/2/2010','Email'=>'contact@example.invalid','Address_Line1'=>'100 Example St','Address_Line2'=>'Unit 2','Address_City'=>'Example City','Address_State'=>'PA','Address_PostalCode'=>'12345','PrintName'=>'Parent Example','Date'=>'10/15/2026','RelationshipToStudent'=>'Mother','Entry_DateSubmitted'=>'10/15/2026 10:17 PM'];
foreach(['EmergencyContact','Phone2','Considerations','Goals','Entry_Status','Entry_DateCreated','Entry_DateUpdated','Future Field'] as $field)$base[$field]='DO_NOT_STORE_12345';
$record=function(array $row,string $encoding='UTF-8')use($headers){return ApprovedRecord::fromRow('cognito_waivers',$headers,array_map(fn($h)=>$row[$h]??'',$headers),$encoding);};
$r=$record($base);$v=CognitoSource::canonical($r);
cogCheck(count($r->values())===16&&count($r->unknownHeaders)===1,'explicit native contract retains only 16 approved original keys');
cogCheck(!str_contains($r->json(),'DO_NOT_STORE_12345'),'sensitive/unknown/workflow source values excluded');
cogCheck($r->values()['Entry_DateSubmitted']===$base['Entry_DateSubmitted'],'original submission formatting retained');
cogCheck($v['Name']==='Student Example'&&$v['Print Name']==='Parent Example','student and signer labels remain distinct');
cogCheck($v['Address']==='100 Example St, Unit 2, Example City, PA, 12345','structured contact address derived without losing source cells');
cogCheck($r->values()['StudentName_First']==='Student'&&$r->values()['StudentName_Last']==='Example','original student name components retained');
foreach(['0','',null,' 1','01','1x','-1',str_repeat('1',256)] as $bad)cogThrows(fn()=>CognitoSource::entryId($bad),'INVALID_IDENTIFIER','invalid native entry ID rejected');
$id=CognitoSource::identity($r,'fixture');
cogCheck($id['source_record_id']==='1'&&$id['source_id_kind']==='external'&&$id['form_namespace']==='fixture.BloomsburgMartialArtsAcademy2','native entry scoped to explicit form namespace');
cogCheck(CognitoSource::identity($r,'other-form')['source_locator']!==$id['source_locator'],'different configured forms cannot share a native locator');
cogCheck(CognitoSource::identity($record(array_replace($base,['Phone'=>'5705559999'])),'fixture')['source_locator']===$id['source_locator'],'changed contact does not change submission identity');
$legacyHeaders=['Date Submitted','Name','Print Name'];$legacyCells=['1/3/2021 16:03','Student Example','Parent Example'];
$legacy=ApprovedRecord::fromRow('cognito_waivers',$legacyHeaders,$legacyCells);
$expected='cog-v1-'.hash('sha256',json_encode(['fixture','civil:2021-01-03 16:03:00','student example','parent example'],JSON_UNESCAPED_UNICODE));
cogCheck(CognitoIdentity::identify($legacy,'fixture')['source_locator']===$expected,'historical fingerprint algorithm unchanged');
cogCheck(count($legacy->values())===10&&array_key_exists('Date Submitted',$legacy->values()),'legacy raw contract remains exactly ten approved keys');
$oldMaps=(require dirname(__DIR__).'/config/import-mappings.php')['cognito_waivers'];$expectedValues=[];foreach($oldMaps as $key=>$map)if($map['approved'])$expectedValues[$key]='';foreach(array_combine($legacyHeaders,$legacyCells) as $key=>$value)$expectedValues[$key]=$value;ksort($expectedValues);
cogCheck($legacy->contentHash()===hash('sha256',json_encode((object)$expectedValues,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),'legacy content hash unaffected by new adapter keys');
foreach([
 ['1/2/2026 12:00 AM','2026-01-02 00:00:00'],['1/2/2026 12:00 PM','2026-01-02 12:00:00'],
 ['10/15/2026 10:17 PM','2026-10-15 22:17:00'],['1/2/2026 1:02:03 PM','2026-01-02 13:02:03'],
 ['2026-10-15T22:17:00+00:00','2026-10-15 22:17:00'],['1/2/2026 13:02','2026-01-02 13:02:00']
] as [$input,$utc])cogCheck(CognitoIdentity::submissionTime($input,'UTC')['utc']===$utc,'strict supported source timestamp parsed');
foreach(['2/30/2026 1:00 PM','13/2/2026 1:00 PM','1/2/2026 13:00 PM','1/2/2026 0:00 AM','1/2/2026 1:60 PM','ambiguous date'] as $bad)cogCheck(CognitoIdentity::submissionTime($bad,'UTC')['warning']==='INVALID_TIMESTAMP','impossible/malformed timestamp rejected');
$unknown=CognitoIdentity::submissionTime($base['Entry_DateSubmitted'],null);
cogCheck($unknown['utc']===null&&$unknown['timezone']===null&&$unknown['warning']==='TIMEZONE_UNRESOLVED','display timezone never supplies missing source timezone');
foreach(['11/1/2026 1:30 AM','3/8/2026 2:30 AM'] as $input)cogCheck(CognitoIdentity::submissionTime($input,'America/New_York')['warning']==='AMBIGUOUS_OR_INVALID_LOCAL_TIME','DST ambiguity/nonexistent source time remains unresolved');
$encoded=array_replace($base,['StudentName_First'=>"Jos\xe9",'PrintName'=>"Parent\x92s Name"]);$converted=$record($encoded,'Windows-1252');
cogCheck($converted->values()['StudentName_First']==="José"&&$converted->values()['PrintName']==="Parent’s Name",'Windows-1252 extended letters and punctuation convert losslessly');
cogThrows(fn()=>$record($encoded),'INVALID_ENCODING','invalid UTF-8 approved bytes rejected by default');
cogThrows(fn()=>$record(array_replace($base,['StudentName_First'=>"bad\x81"]),'Windows-1252'),'INVALID_ENCODING','undefined Windows-1252 approved byte rejected');
$ignored=array_replace($base,['Considerations'=>"ignored\x81",'Future Field'=>"ignored\x81"]);
cogCheck($record($ignored)->json()===$r->json()&&$record($ignored,'Windows-1252')->json()===$r->json(),'invalid bytes in excluded cells never persist or require decoding');
$multiline=$record(array_replace($base,['Address_Line1'=>"Unit, one\nsecond line"]));
cogCheck($multiline->values()['Address_Line1']==="Unit, one\nsecond line",'approved original comma/newline text preserved');
cogThrows(fn()=>ApprovedRecord::fromRow('cognito_waivers',array_merge($headers,['Name']),array_fill(0,count($headers)+1,'')),'UNRECOGNIZED_CSV_HEADER','mixed native and legacy aliases rejected');
$resolver=(new ReflectionClass(PersonResolver::class))->newInstanceWithoutConstructor();
$profile=$resolver->waiverProfile(['address_text'=>$v['Address'],'signer_print_name'=>$v['Print Name'],'source_name'=>$v['Name'],'contact_email'=>$v['Email'],'contact_phone'=>$v['Phone'],'student_birth_date'=>'2010-01-02','relationship_to_student'=>'Mother'],'signer');
cogCheck($profile['birth_date']===null&&$profile['name']==='Parent Example','student birth date is never signer evidence');
$name=getenv('BMA_COGNITO_TEST_DATABASE');
if(!$name){echo 'SKIP database checks: supply BMA_COGNITO_TEST_DATABASE and DB_* for a disposable database.'.PHP_EOL;echo 'Focused Cognito checks: '.$checks.PHP_EOL;exit;}
if(!preg_match('/\Abma_schema_test_[a-z0-9_]+\z/D',$name))throw new RuntimeException('Disposable database required.');
$db=new PDO('mysql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT').';dbname='.$name.';charset=utf8mb4',getenv('DB_USERNAME'),getenv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$db->exec("SET time_zone='+00:00'");
$query=function(string $sql,array $params=[])use($db){$q=$db->prepare($sql);$q->execute($params);return $q;};
$protected=['people','members','person_relationships','stripe_customers','stripe_guest_customers','stripe_subscriptions','stripe_transactions','cognito_waivers','identity_links','identity_match_candidates','audit_log'];
$columns=[];foreach($protected as $table)$columns[$table]=array_column($db->query('SHOW COLUMNS FROM '.$table)->fetchAll(),'Field');
$hashTables=function()use($db,$columns){$out=[];foreach($columns as $table=>$fields){$h=hash_init('sha256');$q=$db->query('SELECT `'.implode('`,`',$fields).'` FROM '.$table.' ORDER BY id');while($row=$q->fetch())hash_update($h,json_encode($row));$out[$table]=hash_final($h);}return $out;};
$initial=$hashTables();$pending=Migrations::run($db,dirname(__DIR__).'/sql/migrations',false);
cogCheck($pending===['009_cognito_native_export']||$pending===[],'only expected Cognito migration is pending on current test database');
if($pending)cogCheck(Migrations::run($db,dirname(__DIR__).'/sql/migrations',true)===['009_cognito_native_export'],'forward migration applied through existing mechanism');
cogCheck(Migrations::run($db,dirname(__DIR__).'/sql/migrations',false)===[]&&$hashTables()===$initial,'migration preserves all prior business data and decisions');
$tag=bin2hex(random_bytes(6));$root='cog-'.$tag;$filename='cognito-test-'.$tag;$directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.$filename;mkdir($directory,0700);
$config=['APP_ENV'=>'test','IMPORT_TEMP_DIR'=>$directory,'COGNITO_FORM_NAMESPACE'=>$root];$service=new ImportService($db,$config);
$run=function(array $rows,string $suffix,array $options=[],?array $customHeaders=null,?array $customConfig=null)use($headers,$service,$filename,$db,$config){$hs=$customHeaders??$headers;$stream=fopen('php://temp','w+b');fputcsv($stream,$hs,',','"','');foreach($rows as $row)fputcsv($stream,array_map(fn($h)=>$row[$h]??'',$hs),',','"','');rewind($stream);try{return ($customConfig?new ImportService($db,$customConfig):$service)->run($stream,'cognito_waivers',$filename.'-'.$suffix.'.csv',$options);}finally{fclose($stream);}};
$auditIds=[];
try{
 $countsBefore=[(int)$db->query('SELECT COUNT(*) FROM people')->fetchColumn(),(int)$db->query('SELECT COUNT(*) FROM members')->fetchColumn(),(int)$db->query('SELECT COUNT(*) FROM identity_links')->fetchColumn()];
 $dry=$run([$base],'dry',['dry_run'=>true]);
 cogCheck($dry['skipped_count']===1&&$dry['inserted_count']===0&&$dry['error_count']===0,'valid native dry run is skipped, not a failed identity match');
 cogCheck((int)$query('SELECT COUNT(*) FROM cognito_waivers WHERE form_namespace LIKE ?',[$root.'%'])->fetchColumn()===0,'dry run writes no normalized waivers');
 $second=array_replace($base,[CognitoSource::ID=>'2']);$third=array_replace($base,[CognitoSource::ID=>'3','StudentName_First'=>'Sibling']);
 $rows=[$base,$second,$third,$base];$actual=$run($rows,'actual');
 cogCheck($actual['inserted_count']===3&&$actual['skipped_count']===1&&$actual['error_count']===0,'native ID duplicate skips but same-name/different IDs remain separate');
 $saved=$query('SELECT * FROM cognito_waivers WHERE form_namespace=? AND source_record_id=?',[$root.'.BloomsburgMartialArtsAcademy2','1'])->fetch();
 cogCheck($saved['source_format']==='bma2_v1'&&$saved['source_name_role']==='student'&&$saved['source_student_first_name']==='Student'&&$saved['source_student_last_name']==='Example','verified source student label and name components persist');
 cogCheck($saved['signer_print_name']==='Parent Example'&&$saved['source_name']==='Student Example'&&$saved['student_birth_date']==='2010-01-02','normalized student/signer/birthdate roles remain distinct');
 cogCheck($saved['submitted_at']===null&&$saved['source_timezone']===null&&$saved['student_person_id']===null&&$saved['signer_person_id']===null,'unknown timezone and person associations remain NULL');
 $raw=json_decode($query('SELECT raw_values FROM raw_cognito_waivers WHERE id=?',[$saved['latest_raw_id']])->fetchColumn(),true);
 cogCheck($raw['Entry_DateSubmitted']===$base['Entry_DateSubmitted']&&$raw['Address_Line2']==='Unit 2'&&!array_key_exists('Considerations',$raw),'approved original native keys and values preserved without sensitive fields');
 cogCheck($query('SELECT source_record_id FROM raw_cognito_waivers WHERE id=?',[$saved['latest_raw_id']])->fetchColumn()==='1','raw native entry ID retained as submission metadata');
 $repeat=$run($rows,'renamed');$reordered=$run(array_reverse($rows),'reordered');
 cogCheck($repeat['duplicate_of']===$actual['batch_id']&&$repeat['skipped_count']===4&&$reordered['inserted_count']===0&&$reordered['updated_count']===0&&$reordered['skipped_count']===4,'renamed and reordered native exports are idempotent');
 $changed=array_replace($base,['Phone'=>'5705557777']);$update=$run([$changed],'changed');
 cogCheck($update['updated_count']===1&&$query('SELECT contact_phone FROM cognito_waivers WHERE id=?',[$saved['id']])->fetchColumn()==='5705557777','unassociated same-ID changed approved source updates existing submission');
 $ignoredUpdate=$run([array_replace($changed,['Goals'=>'changed ignored text'])],'ignored-only');
 cogCheck($ignoredUpdate['skipped_count']===1&&$ignoredUpdate['updated_count']===0,'ignored-only changes cannot change approved content hash');
 $person=(int)$db->query('SELECT person_id FROM members LIMIT 1')->fetchColumn();$actor=(int)$db->query("SELECT id FROM app_users WHERE role='admin' AND is_active=1 LIMIT 1")->fetchColumn();
 (new IdentityAssociations($db))->decide('cognito','waiver',$saved['source_locator'],'student',$person,'confirmed',$actor);
 (new IdentityAssociations($db))->decide('cognito','waiver',$saved['source_locator'],'signer',$person,'rejected',$actor);
 $auditIds=$query("SELECT id FROM audit_log WHERE entity_type='cognito_waivers' AND entity_id=?",[(string)$saved['id']])->fetchAll(PDO::FETCH_COLUMN);
 $reviewed=$query('SELECT * FROM cognito_waivers WHERE id=?',[$saved['id']])->fetch();$links=$query('SELECT * FROM identity_links WHERE external_id=? ORDER BY id',[$saved['source_locator']])->fetchAll();
 require_once dirname(__DIR__).'/app/Members/MemberProfileRepository.php';$profileWaivers=(new MemberProfileRepository($db))->waivers($person);$matching=array_values(array_filter($profileWaivers,fn($row)=>$row['source_locator']===$saved['source_locator']));cogCheck(count($matching)===1&&$matching[0]['original_submitted_at']===$base['Entry_DateSubmitted'],'accepted member waiver exposes the original native source time without conversion');
 $held=$run([array_replace($changed,['StudentName_First'=>'Different'])],'reviewed-change');
 cogCheck($held['skipped_count']===1&&$query('SELECT * FROM cognito_waivers WHERE id=?',[$saved['id']])->fetch()===$reviewed&&$query('SELECT * FROM identity_links WHERE external_id=? ORDER BY id',[$saved['source_locator']])->fetchAll()===$links,'changed source with confirmed/rejected decisions is held without overwriting reviewed evidence');
 cogCheck((int)$query("SELECT COUNT(*) FROM import_errors WHERE import_batch_id=? AND error_code='WAIVER_UPDATE_REVIEW_REQUIRED' AND severity='warning'",[$held['batch_id']])->fetchColumn()===1,'protected source change has explicit review diagnostic');
 $invalidReviewed=$run([array_replace($changed,['Entry_DateSubmitted'=>'2/30/2026 1:00 PM'])],'reviewed-invalid');cogCheck($invalidReviewed['error_count']===1&&$invalidReviewed['skipped_count']===0,'invalid source remains an error even when identity evidence is protected');
 $invalids=[array_replace($base,[CognitoSource::ID=>'']),array_replace($base,[CognitoSource::ID=>'bad']),array_replace($base,[CognitoSource::ID=>'4','Entry_DateSubmitted'=>'2/30/2026 1:00 PM']),array_replace($base,[CognitoSource::ID=>'5','StudentName_First'=>"bad\xc3"])];
 $bad=$run($invalids,'invalid');cogCheck($bad['error_count']===4&&$bad['inserted_count']===0&&$bad['status']==='completed_with_errors','invalid native IDs/timestamp/encoding are real failed rows');
 cogCheck((int)$query("SELECT COUNT(*) FROM import_errors WHERE import_batch_id=? AND severity='error'",[$bad['batch_id']])->fetchColumn()===4,'failed-row diagnostics have error severity');
 $retry=$run([array_replace($base,[CognitoSource::ID=>'4']),array_replace($base,[CognitoSource::ID=>'5'])],'retry');cogCheck($retry['inserted_count']===2&&$retry['error_count']===0,'partial failure can be deliberately retried');
 $cp=$run([array_replace($encoded,[CognitoSource::ID=>'6'])],'cp',['cognito_encoding'=>'Windows-1252']);cogCheck($cp['inserted_count']===1&&$cp['error_count']===0,'explicit Windows-1252 native import succeeds');
 $newline=$run([array_replace($base,[CognitoSource::ID=>'7','Address_Line1'=>"Unit, one\nsecond line"])],'newline');cogCheck($newline['row_count']===1&&$newline['inserted_count']===1,'CSV embedded commas/newlines retain correct record count');
 $tz=array_replace($config,['COGNITO_SOURCE_TIMEZONE'=>'America/New_York']);
 $dst=$run([array_replace($base,[CognitoSource::ID=>'8','Entry_DateSubmitted'=>'11/1/2026 1:30 AM'])],'dst',[],null,$tz);
 cogCheck($dst['inserted_count']===1&&$dst['error_count']===0&&(int)$query("SELECT COUNT(*) FROM import_errors WHERE import_batch_id=? AND error_code='SOURCE_TIME_AMBIGUOUS' AND severity='warning'",[$dst['batch_id']])->fetchColumn()===1,'valid native entry with DST ambiguity is imported with an unresolved time warning');
 $legacyConfig=array_replace($config,['COGNITO_FORM_NAMESPACE'=>$root.'.legacy']);
 $oldRow=array_combine($legacyHeaders,$legacyCells);$old=$run([$oldRow],'legacy',[],$legacyHeaders,$legacyConfig);
 cogCheck($old['inserted_count']===1&&$old['error_count']===0,'previously supported legacy CSV still imports');
 $oldSaved=$query("SELECT * FROM cognito_waivers WHERE form_namespace=?",[$root.'.legacy'])->fetch();
 $legacyChanged=$run([array_replace($oldRow,['Phone'=>'5705559999'])],'legacy-change',[],array_merge($legacyHeaders,['Phone']),$legacyConfig);
 cogCheck($legacyChanged['skipped_count']===1&&$query('SELECT * FROM cognito_waivers WHERE id=?',[$oldSaved['id']])->fetch()===$oldSaved,'changed legacy fingerprint remains held under its original locator');
 $transition=$run([$base],'reconcile',[],null,$legacyConfig);
 cogCheck($transition['skipped_count']===1&&$transition['inserted_count']===0&&(int)$query("SELECT COUNT(*) FROM import_errors WHERE import_batch_id=? AND error_code='LEGACY_RECONCILIATION_REQUIRED'",[$transition['batch_id']])->fetchColumn()===1,'native transition is held for reviewed reconciliation when prior fingerprint records exist');
 $ambigLegacy=$run([['Name'=>'Only name']],'legacy-invalid',[],['Name'],$legacyConfig);
 cogCheck($ambigLegacy['error_count']===1&&(int)$query("SELECT COUNT(*) FROM import_errors WHERE import_batch_id=? AND error_code='IDENTITY_AMBIGUOUS' AND severity='error'",[$ambigLegacy['batch_id']])->fetchColumn()===1,'legacy identity exception is counted and shown as an error, not a warning');
 $badJson=false;try{$query('INSERT INTO raw_cognito_waivers(import_batch_id,source_row_number,raw_values,row_hash) VALUES(?,?,?,?)',[$actual['batch_id'],99999,'{"Considerations":"DO_NOT_STORE_12345"}',str_repeat('a',64)]);}catch(PDOException){$badJson=true;}cogCheck($badJson,'database still rejects sensitive JSON keys');
 $badJson=false;try{$query('INSERT INTO raw_cognito_waivers(import_batch_id,source_row_number,raw_values,row_hash) VALUES(?,?,?,?)',[$actual['batch_id'],99999,'{"Future Field":"DO_NOT_STORE_12345"}',str_repeat('a',64)]);}catch(PDOException){$badJson=true;}cogCheck($badJson,'database still rejects unknown JSON keys');
 $stripe=ApprovedRecord::fromRow('stripe_customers',['id','Name'],['cus_'.$tag,"bad\xc3"],'Windows-1252');cogCheck($stripe->values()['Name']==="bad\xc3",'Cognito adapter never transcodes Stripe values');
 // Actual export: default UTF-8 failure counts and explicitly confirmed Windows-1252 dry run.
 $path=getenv('BMA_COGNITO_TEST_EXPORT');
 if($path){$actualConfig=array_replace($config,['COGNITO_FORM_NAMESPACE'=>$root.'.actual']);$actualService=new ImportService($db,$actualConfig);$actualLinkCount=(int)$db->query('SELECT COUNT(*) FROM identity_links')->fetchColumn();foreach(['UTF-8','Windows-1252'] as $encoding){$stream=fopen($path,'rb');try{$result=$actualService->run($stream,'cognito_waivers',$filename.'-actual-'.($encoding==='UTF-8'?'utf8':'cp1252').'.csv',['dry_run'=>true,'cognito_encoding'=>$encoding]);}finally{fclose($stream);}echo 'ACTUAL '. $encoding.' rows='.$result['row_count'].' skipped='.$result['skipped_count'].' errors='.$result['error_count'].PHP_EOL;if($encoding==='UTF-8')cogCheck($result['row_count']===1074&&$result['error_count']===4&&$result['skipped_count']===1070,'default UTF-8 rejects only the four records with invalid approved bytes');if($encoding==='Windows-1252'){cogCheck($result['row_count']===1074&&$result['skipped_count']===1074&&$result['error_count']===0&&$result['inserted_count']===0,'actual 1074-row native export validates with explicit lossless encoding');cogCheck($result['unknown_headers']===[],'all actual headers explicitly approved or ignored');$issues=$query('SELECT error_code,severity,COUNT(*) n FROM import_errors WHERE import_batch_id=? GROUP BY error_code,severity',[$result['batch_id']])->fetchAll();echo 'ACTUAL diagnostics '.json_encode($issues).PHP_EOL;cogCheck(count($query('SELECT id FROM import_errors WHERE import_batch_id=? ORDER BY id LIMIT 501 OFFSET 500',[$result['batch_id']])->fetchAll())===501,'diagnostics page two retains a Next page beyond the first 500');cogCheck(count($query('SELECT id FROM import_errors WHERE import_batch_id=? ORDER BY id LIMIT 500 OFFSET 1000',[$result['batch_id']])->fetchAll())===74,'diagnostics final page reaches all 1074 issues');cogCheck(count($issues)===1&&$issues[0]['error_code']==='TIMEZONE_UNRESOLVED'&&(int)$issues[0]['n']===1074,'actual valid native export has only unresolved source-timezone warnings');cogCheck((int)$db->query('SELECT COUNT(*) FROM identity_links')->fetchColumn()===$actualLinkCount&&(int)$query('SELECT COUNT(*) FROM cognito_waivers WHERE form_namespace LIKE ?',[$root.'.actual%'])->fetchColumn()===0,'actual dry run creates no normalized records or identity links');}}}
 $batches=$query('SELECT id FROM import_batches WHERE filename LIKE ?',[$filename.'%'])->fetchAll(PDO::FETCH_COLUMN);$clean=true;
 foreach($batches as $batch){$q=$query('SELECT raw_values FROM raw_cognito_waivers WHERE import_batch_id=?',[$batch]);while($json=$q->fetchColumn()){if(str_contains($json,'DO_NOT_STORE_12345')||array_intersect(['Considerations','Goals','EmergencyContact','Phone2','Entry_Status','Entry_DateUpdated','Entry_DateCreated'],array_keys(json_decode($json,true))))$clean=false;}}
 cogCheck($clean,'all actual and synthetic staging excludes sensitive/workflow/unknown values');
 $log=file_get_contents($directory.'/import-events.log');cogCheck(!str_contains($log,'DO_NOT_STORE_12345')&&!str_contains($log,$base['Email'])&&!str_contains($log,$base['StudentName_First']),'operational logs contain only batch/row/code metadata');
 cogCheck([(int)$db->query('SELECT COUNT(*) FROM people')->fetchColumn(),(int)$db->query('SELECT COUNT(*) FROM members')->fetchColumn()]===[ $countsBefore[0],$countsBefore[1]],'no automatic person/member creation');
}finally{
 if($db->inTransaction())$db->rollBack();
 $waivers=$query('SELECT id,source_locator FROM cognito_waivers WHERE form_namespace LIKE ?',[$root.'%'])->fetchAll();
 foreach($waivers as $waiver){$query("DELETE FROM identity_links WHERE source_system='cognito' AND external_id=?",[$waiver['source_locator']]);$query('UPDATE cognito_waivers SET student_person_id=NULL,signer_person_id=NULL WHERE id=?',[$waiver['id']]);$query('DELETE FROM cognito_waivers WHERE id=?',[$waiver['id']]);}
 foreach($auditIds as $auditId)$query('DELETE FROM audit_log WHERE id=?',[$auditId]);
 foreach($query('SELECT id FROM import_batches WHERE filename LIKE ?',[$filename.'%'])->fetchAll(PDO::FETCH_COLUMN) as $batch){$query('DELETE FROM import_errors WHERE import_batch_id=?',[$batch]);$query('DELETE FROM raw_cognito_waivers WHERE import_batch_id=?',[$batch]);$query('DELETE FROM import_batches WHERE id=?',[$batch]);}
 foreach(new DirectoryIterator($directory) as $file)if(!$file->isDot()&&$file->isFile())unlink($file->getPathname());rmdir($directory);
}
cogCheck($hashTables()===$initial,'existing business records and all manual decisions unchanged after test fixture cleanup');
echo 'Focused Cognito checks: '.$checks.PHP_EOL;

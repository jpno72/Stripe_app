<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once dirname(__DIR__).'/app/Import/bootstrap.php';
require_once dirname(__DIR__).'/app/Import/CognitoEmergencyBackfill.php';
require_once dirname(__DIR__).'/src/People.php';
require_once dirname(__DIR__).'/src/IdentityAssociations.php';
require_once dirname(__DIR__).'/app/Members/MemberProfileRepository.php';
$checks=0;
function ecCheck(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException('FAIL '.$label);++$checks;echo 'PASS '.$label.PHP_EOL;}
function ecThrows(callable $fn,string $label,?string $code=null):void{try{$fn();}catch(Throwable $e){ecCheck($code===null||$e->getMessage()===$code,$label);return;}ecCheck(false,$label);}
$maps=require dirname(__DIR__).'/config/import-mappings.php';$headers=array_keys($maps['cognito_waivers_bma2_v1']);
$base=[CognitoSource::ID=>'1','StudentName_First'=>'Student','StudentName_Last'=>'Fixture','PrintName'=>'Signer Fixture','Entry_DateSubmitted'=>'10/15/2026 10:17 PM','Date'=>'10/15/2026','Email'=>'fixture@example.invalid','EmergencyContact'=>'Emergency Fixture','Phone2'=>'(570) 555-0100 ext. 9','Considerations'=>'DO_NOT_STORE_12345','Goals'=>'DO_NOT_STORE_12345'];
$record=fn(array $v,string $encoding='UTF-8')=>ApprovedRecord::fromRow('cognito_waivers',$headers,array_map(fn($h)=>$v[$h]??'',$headers),$encoding);
$r=$record($base);$contact=CognitoSource::emergencyContacts($r);
ecCheck(count($r->values())===18&&$contact['emergency_contact_phone']===$base['Phone2'],'native 18-field contract preserves phone formatting and extension');
foreach(['EmergencyContact','Phone2'] as $key)ecCheck($maps['cognito_waivers_bma2_v1'][$key]['action']==='OPTIONAL'&&!$maps['cognito_waivers_bma2_v1'][$key]['identity_score']&&!$maps['cognito_waivers_bma2_v1'][$key]['log_value'],'emergency field optional and excluded from scoring/logging');
ecCheck(!str_contains($r->json(),'DO_NOT_STORE_12345')&&!array_key_exists('EmergencyContact',CognitoSource::canonical($r)),'excluded waiver text stays excluded; emergency values absent from canonical identity profile');
$encoded=$record(array_replace($base,['EmergencyContact'=>"Jos\xe9\x92s Contact"]),'Windows-1252');
ecCheck(CognitoSource::emergencyContacts($encoded)['emergency_contact_name']==='José’s Contact','emergency Windows-1252 text converts losslessly');
ecThrows(fn()=>$record(array_replace($base,['Phone2'=>"bad\x81"]),'Windows-1252'),'undefined approved Windows-1252 bytes rejected');
ecThrows(fn()=>CognitoSource::emergencyContacts($record(array_replace($base,['Phone2'=>str_repeat('1',101)]))),'overlength phones rejected without truncation');
ecCheck(CognitoSource::emergencyContacts($record(array_replace($base,['EmergencyContact'=>'   ','Phone2'=>''])))===['emergency_contact_name'=>null,'emergency_contact_phone'=>null],'blank emergency fields normalize to NULL');
ecCheck(CognitoSource::preEmergencyContentHash($r)!==$r->contentHash(),'new contract has a distinct full hash and exact old-hash projection');
ecCheck(CognitoSource::identity($r,'bma-waiver')['source_locator']===CognitoSource::identity($record(array_replace($base,['EmergencyContact'=>'Other'])),'bma-waiver')['source_locator'],'emergency text never changes native record identity');
$without=array_values(array_diff($headers,['EmergencyContact','Phone2']));$missing=ApprovedRecord::fromRow('cognito_waivers',$without,array_map(fn($h)=>$base[$h]??'',$without));
ecCheck(CognitoSource::emergencyContacts($missing)===['emergency_contact_name'=>null,'emergency_contact_phone'=>null],'optional missing emergency columns normalize to NULL');
$legacy=ApprovedRecord::fromRow('cognito_waivers',['Name','Print Name','Date Submitted','Emergency Contact'],['Student','Signer','1/2/2026 10:00','DO_NOT_STORE_12345']);
ecCheck(count($legacy->values())===10&&!str_contains($legacy->json(),'DO_NOT_STORE_12345'),'legacy mapping remains unchanged');
if(getenv('BMA_EMERGENCY_DB_TEST')!=='1'){echo 'SKIP integration: set BMA_EMERGENCY_DB_TEST=1, local DB_* and BMA_COGNITO_TEST_EXPORT.'.PHP_EOL;echo 'Emergency checks: '.$checks.PHP_EOL;exit;}
if(!in_array(getenv('DB_HOST'),['localhost','127.0.0.1'],true))throw new RuntimeException('Local disposable database required.');
$db=new PDO('mysql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT').';charset=utf8mb4',getenv('DB_USERNAME'),getenv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$name='bma_schema_test_emergency_'.bin2hex(random_bytes(6));$directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.$name;mkdir($directory,0700);mkdir($directory.'/migrations',0700);
$db->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$db->exec('USE `'.$name.'`');$db->exec("SET time_zone='+00:00'");
$query=function(string $sql,array $args=[])use($db){$q=$db->prepare($sql);$q->execute($args);return $q;};
$config=['APP_ENV'=>'test','PUBLIC_WEB_ROOT'=>dirname(__DIR__).'/public','IMPORT_TEMP_DIR'=>$directory,'COGNITO_FORM_NAMESPACE'=>'bma-waiver'];
$namespace='bma-waiver.BloomsburgMartialArtsAcademy2';
try{
 foreach(glob(dirname(__DIR__).'/sql/migrations/00*.sql') as $file)copy($file,$directory.'/migrations/'.basename($file));
 ecCheck(count(Migrations::run($db,$directory.'/migrations',true))===9,'migrations 001 through 009 initialize disposable pre-change schema');
 $batch=function(string $file,int $rows)use($query){
  $options=['dry_run'=>false,'mapping_hash'=>str_repeat('a',64),'cognito_namespace'=>'bma-waiver','cognito_format'=>'cognito_waivers_bma2_v1','input_encoding'=>'Windows-1252'];
  $query("INSERT INTO import_batches(source_type,filename,file_hash,source_headers,import_options,status,row_count,inserted_count,completed_at) VALUES ('cognito_waivers','private-original.csv',?,'[]',?,'completed',?,?,UTC_TIMESTAMP(6))",[hash_file('sha256',$file),json_encode($options),$rows,$rows]);
  return (int)$GLOBALS['db']->lastInsertId();
 };
 $file=getenv('BMA_COGNITO_TEST_EXPORT');
 if(!$file||!Paths::isAbsolute($file)||!is_file($file))throw new RuntimeException('Original private 1074-row CSV required for integration test.');
 $originalBatch=$batch($file,1074);$stream=fopen($file,'rb');$actualHeaders=fgetcsv($stream,1048576,',','"','');$count=0;$actualContacts=[];
 while(($cells=fgetcsv($stream,1048576,',','"',''))!==false){
  $recordActual=ApprovedRecord::fromRow('cognito_waivers',$actualHeaders,$cells,'Windows-1252');$id=CognitoSource::identity($recordActual,'bma-waiver');
  $old=$recordActual->values();unset($old['EmergencyContact'],$old['Phone2']);$hash=CognitoSource::preEmergencyContentHash($recordActual);++$count;
  $query('INSERT INTO raw_cognito_waivers(import_batch_id,source_row_number,source_record_id,raw_values,row_hash,source_locator,content_hash,processing_status) VALUES (?,?,?,?,?,?,?,?)',[$originalBatch,$count,$id['source_record_id'],json_encode($old,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$hash,$id['source_locator'],$hash,'inserted']);$raw=(int)$db->lastInsertId();
  $query("INSERT INTO cognito_waivers(source_record_id,source_id_kind,form_namespace,source_locator,content_hash,latest_raw_id,source_format,source_name_role) VALUES (?,'external',?,?,?,?,'bma2_v1','student')",[$id['source_record_id'],$namespace,$id['source_locator'],$hash,$raw]);
  $actualContacts[(int)$db->lastInsertId()]=CognitoSource::emergencyContacts($recordActual);
 }
 fclose($stream);ecCheck($count===1074&&count($actualContacts)===1074,'actual CSV yields 1074 unique source-ID fixtures under historical raw contract');
 $beforeRaw=$db->query('SELECT * FROM raw_cognito_waivers ORDER BY id')->fetchAll();$beforeWaivers=$db->query('SELECT * FROM cognito_waivers ORDER BY id')->fetchAll();
 $q=$query("INSERT INTO app_users(email,email_normalized,display_name,password_hash,role) VALUES ('admin@example.invalid','admin@example.invalid','Test admin',?,'admin')",[password_hash(bin2hex(random_bytes(24)),PASSWORD_DEFAULT)]);$actor=(int)$db->lastInsertId();
 $backfill=new CognitoEmergencyBackfill($db,$config);
 ecThrows(fn()=>$backfill->run($file,$namespace,$originalBatch,$actor),'pre-010 schema blocks backfill','BACKFILL_SCHEMA_REQUIRED');
 ecCheck(Migrations::run($db,dirname(__DIR__).'/sql/migrations',false)===['010_cognito_emergency_contacts'],'only migration 010 pending with all old checksums intact');
 ecCheck(Migrations::run($db,dirname(__DIR__).'/sql/migrations',true)===['010_cognito_emergency_contacts'],'additive migration applies against 1074 populated historical waivers');
 $after=$db->query('SELECT * FROM cognito_waivers ORDER BY id')->fetchAll();$allNull=true;foreach($after as &$row){$allNull=$allNull&&$row['emergency_contact_name']===null&&$row['emergency_contact_phone']===null;unset($row['emergency_contact_name'],$row['emergency_contact_phone']);}unset($row);ecCheck($allNull,'all 1074 new contact pairs start NULL');
 ecCheck($after===$beforeWaivers&&$beforeRaw===$db->query('SELECT * FROM raw_cognito_waivers ORDER BY id')->fetchAll(),'migration preserves every historical normalized value/raw snapshot/hash');
 ecCheck(Migrations::run($db,dirname(__DIR__).'/sql/migrations',false)===[],'migration history reports zero pending and valid checksums');
 $person=(new People($db))->create('Fixture','Member');$query("INSERT INTO members(person_id,member_status) VALUES (?,'active')",[$person['id']]);
 $first=$db->query('SELECT id,source_locator FROM cognito_waivers ORDER BY id LIMIT 1')->fetch();
 (new IdentityAssociations($db))->decide('cognito','waiver',$first['source_locator'],'student',(int)$person['id'],'confirmed',$actor);
 (new IdentityAssociations($db))->decide('cognito','waiver',$first['source_locator'],'signer',(int)$person['id'],'rejected',$actor);
 $query("UPDATE cognito_waivers SET interpretation_status='verified' WHERE id=?",[$first['id']]);
 $hashTables=function(array $tables)use($db){$out=[];foreach($tables as $table)$out[$table]=hash('sha256',json_encode($db->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll()));return $out;};
 $protected=['people','members','person_relationships','identity_links','identity_match_candidates','raw_cognito_waivers','import_batches','import_errors'];$stable=$hashTables($protected);
 $waiverSnapshot=function()use($db){$rows=$db->query('SELECT * FROM cognito_waivers ORDER BY id')->fetchAll();foreach($rows as &$row)unset($row['emergency_contact_name'],$row['emergency_contact_phone'],$row['updated_at']);return $rows;};$unrelated=$waiverSnapshot();
 $auditBefore=(int)$db->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
 $dry=$backfill->run($file,$namespace,$originalBatch,$actor);
 ecCheck($dry['csv_rows']===1074&&$dry['unique_ids']===1074&&$dry['matched_rows']===1074&&$dry['unmatched_rows']===0&&$dry['failures']===0,'all 1074 actual native IDs match exactly without demographic matching');
 ecCheck($dry['updated_rows']===0&&$dry['proposed_updates']+$dry['unchanged_rows']===1074,'dry run reports proposed changes and blanks without writes');
 ecCheck((int)$db->query('SELECT COUNT(*) FROM audit_log')->fetchColumn()===$auditBefore&&$hashTables($protected)===$stable,'dry run leaves audit and protected data unchanged');
 $cliConfig=array_replace($config,['DB_HOST'=>(string)getenv('DB_HOST'),'DB_PORT'=>(string)getenv('DB_PORT'),'DB_DATABASE'=>$name,'DB_USERNAME'=>(string)getenv('DB_USERNAME'),'DB_PASSWORD'=>(string)getenv('DB_PASSWORD')]);
 $configPath=$directory.'/cli-config.php';file_put_contents($configPath,'<?php return '.var_export($cliConfig,true).';');chmod($configPath,0600);
 $cli=[PHP_BINARY,dirname(__DIR__).'/bin/backfill-cognito-emergency-contacts.php','--config='.$configPath,'--file='.$file,'--namespace='.$namespace,'--batch='.$originalBatch,'--actor-user='.$actor];
 $execute=function(array $args):array{$p=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($p),$out,$err];};
 [$status,$out,$err]=$execute($cli);$cliDry=json_decode($out,true);
 ecCheck($status===0&&$err===''&&$cliDry===$dry,'CLI defaults to dry run and outputs only aggregate counts');
 ecCheck((int)$db->query('SELECT COUNT(*) FROM audit_log')->fetchColumn()===$auditBefore,'default CLI is read-only');
 [$status,$out,$err]=$execute(array_merge($cli,['--dry-run','--apply']));ecCheck($status!==0&&!str_contains($err,$file),'CLI rejects conflicting modes without leaking private paths');
 [$status,$out,$err]=$execute(array_replace($cli,[3=>'--file='.dirname(__DIR__).'/public/not-private.csv']));ecCheck($status!==0&&!str_contains($out,$base['EmergencyContact']),'CLI rejects unavailable/public source safely');
 $apply=$backfill->run($file,$namespace,$originalBatch,$actor,1074,true);
 ecCheck($apply['updated_rows']===$dry['proposed_updates']&&$apply['failures']===0,'apply fills only proposed contacts, including associated verified waiver');
 foreach($actualContacts as $id=>$expected){$saved=$query('SELECT emergency_contact_name,emergency_contact_phone FROM cognito_waivers WHERE id=?',[$id])->fetch();if($saved!==$expected)throw new RuntimeException('FAIL actual contacts did not match');}
 ecCheck(true,'every actual emergency field equals losslessly decoded source, with blank fields NULL');
 ecCheck($waiverSnapshot()===$unrelated&&$hashTables($protected)===$stable,'source IDs/hashes/raw history/timezones/member and manual identity decisions unchanged');
 $repeat=$backfill->run($file,$namespace,$originalBatch,$actor,1074,true);
 ecCheck($repeat['updated_rows']===0&&$repeat['unchanged_rows']===1074&&(int)$db->query('SELECT COUNT(*) FROM audit_log')->fetchColumn()===$auditBefore+$apply['updated_rows'],'repeat backfill has zero writes and no duplicate audit');
 $audits=$query("SELECT metadata FROM audit_log WHERE action='cognito_emergency_backfilled'")->fetchAll(PDO::FETCH_COLUMN);$safe=true;foreach($audits as $json){$meta=json_decode($json,true);if(isset($meta['emergency_contact_name'],$meta['emergency_contact_phone'])||str_contains($json,'new_value'))$safe=false;}
 ecCheck($safe&&count($audits)===$apply['updated_rows'],'contact-free audit records identify actor/batch/reason and changed field names');
 $profile=(new MemberProfileRepository($db))->waivers((int)$person['id']);ecCheck(count($profile)===1&&!array_key_exists('emergency_contact_name',$profile[0])&&!array_key_exists('emergency_contact_phone',$profile[0]),'ordinary member profile projection does not expose emergency contacts');
 $service=new ImportService($db,$config);$stream=fopen($file,'rb');$reimport=$service->run($stream,'cognito_waivers','native-reimport.csv',['cognito_encoding'=>'Windows-1252']);fclose($stream);
 ecCheck($reimport['row_count']===1074&&$reimport['skipped_count']===1074&&$reimport['updated_count']===0&&$reimport['error_count']===0,'old original CSV safely reimports under new mapping without replacing normalized evidence');
 ecCheck((int)$query("SELECT COUNT(*) FROM import_errors WHERE import_batch_id=? AND error_code='EMERGENCY_CONTACT_BACKFILL_REQUIRED'",[$reimport['batch_id']])->fetchColumn()===1074,'mapping/hash transition is explicitly diagnosed, not silently reconciled');
 ecCheck($waiverSnapshot()===$unrelated,'reviewed waivers and original content hashes remain unchanged after reimport');
 $stream=fopen($file,'rb');$again=$service->run($stream,'cognito_waivers','renamed.csv',['cognito_encoding'=>'Windows-1252']);fclose($stream);ecCheck($again['duplicate_of']===$reimport['batch_id']&&$again['skipped_count']===1074,'new mapping hash correctly recognizes repeated identical file');
 $query("UPDATE cognito_waivers SET emergency_contact_name='Manual correction' WHERE id=?",[$first['id']]);$snap=$hashTables(['cognito_waivers','audit_log']);
 ecThrows(fn()=>$backfill->run($file,$namespace,$originalBatch,$actor,1074,true),'conflicting existing emergency value aborts whole apply','EXISTING_EMERGENCY_CONFLICT');ecCheck($hashTables(['cognito_waivers','audit_log'])===$snap,'conflict causes zero partial writes and preserves correction');
 $publicConfig=array_replace($config,['PUBLIC_WEB_ROOT'=>dirname($file)]);ecThrows(fn()=>(new CognitoEmergencyBackfill($db,$publicConfig))->run($file,$namespace,$originalBatch,$actor),'existing CSV inside a served root is rejected');
 ecThrows(fn()=>$backfill->run($file,'wrong.BloomsburgMartialArtsAcademy2',$originalBatch,$actor),'wrong namespace rejected','INVALID_BACKFILL_SCOPE');
 ecThrows(fn()=>$backfill->run($file,$namespace,$originalBatch,$actor,1073),'unexpected record count rejected','ORIGINAL_BATCH_MISMATCH');
 $query('UPDATE app_users SET is_active=0 WHERE id=?',[$actor]);ecThrows(fn()=>$backfill->run($file,$namespace,$originalBatch,$actor),'inactive administrator denied','BACKFILL_ADMIN_REQUIRED');$query('UPDATE app_users SET is_active=1 WHERE id=?',[$actor]);
 $query("UPDATE app_users SET role='viewer' WHERE id=?",[$actor]);ecThrows(fn()=>$backfill->run($file,$namespace,$originalBatch,$actor),'viewer cannot backfill','BACKFILL_ADMIN_REQUIRED');$query("UPDATE app_users SET role='admin' WHERE id=?",[$actor]);
 // Small independent synthetic sources exercise strict aborts and future import behavior.
 $make=function(array $rows,string $label)use($directory,$headers){$path=$directory.'/'.$label.'.csv';$s=fopen($path,'wb');fputcsv($s,$headers,',','"','');foreach($rows as $row)fputcsv($s,array_map(fn($h)=>$row[$h]??'',$headers),',','"','');fclose($s);return $path;};
 foreach([['duplicate',[array_replace($base,[CognitoSource::ID=>'7777777']),array_replace($base,[CognitoSource::ID=>'7777777'])]],['invalid',[array_replace($base,[CognitoSource::ID=>'01'])]],['encoding',[array_replace($base,['EmergencyContact'=>"bad\x81"])]],['unmatched',[array_replace($base,[CognitoSource::ID=>'9999999'])]]] as [$label,$rows]){
  $path=$make($rows,$label);$b=$batch($path,count($rows));ecThrows(fn()=>$backfill->run($path,$namespace,$b,$actor,count($rows),true),$label.' source aborts with no partial write',['duplicate'=>'DUPLICATE_NATIVE_ID','invalid'=>'INVALID_IDENTIFIER','encoding'=>'INVALID_ENCODING','unmatched'=>'UNEXPECTED_MATCH_COUNTS'][$label]);
 }
 $path=$make([array_replace($base,[CognitoSource::ID=>'9999998'])],'new-source');ecThrows(fn()=>$backfill->run($path,$namespace,$originalBatch,$actor),'CSV hash different from original import rejected','ORIGINAL_BATCH_MISMATCH');
 $future=array_replace($base,[CognitoSource::ID=>'8888888']);$path=$make([$future],'future');$s=fopen($path,'rb');$fresh=$service->run($s,'cognito_waivers','future.csv',['cognito_encoding'=>'Windows-1252']);fclose($s);
 ecCheck($fresh['inserted_count']===1&&$fresh['error_count']===0,'future native import stores emergency fields');$futureSaved=$query('SELECT * FROM cognito_waivers WHERE form_namespace=? AND source_record_id=?',[$namespace,'8888888'])->fetch();
 ecCheck($futureSaved['emergency_contact_name']===$base['EmergencyContact']&&$futureSaved['emergency_contact_phone']===$base['Phone2'],'future normalized contacts retain useful source formatting');
 $s=fopen($path,'rb');$again=$service->run($s,'cognito_waivers','future-renamed.csv',['cognito_encoding'=>'Windows-1252']);fclose($s);ecCheck($again['skipped_count']===1&&$again['inserted_count']===0,'future import repeats idempotently');
 $changed=$make([array_replace($future,['EmergencyContact'=>'Different contact'])],'future-conflict');$s=fopen($changed,'rb');$held=$service->run($s,'cognito_waivers','future-changed.csv',['cognito_encoding'=>'Windows-1252']);fclose($s);ecCheck($held['skipped_count']===1&&$query('SELECT emergency_contact_name FROM cognito_waivers WHERE id=?',[$futureSaved['id']])->fetchColumn()===$base['EmergencyContact'],'ordinary reimport never silently overwrites existing emergency correction');
 (new IdentityAssociations($db))->decide('cognito','waiver',$futureSaved['source_locator'],'student',(int)$person['id'],'confirmed',$actor);
 $reviewedBefore=$query('SELECT * FROM cognito_waivers WHERE id=?',[$futureSaved['id']])->fetch();
 $changed=$make([array_replace($future,['StudentName_First'=>'Different source','EmergencyContact'=>'Replacement'])],'reviewed-future');
 $s=fopen($changed,'rb');$held=$service->run($s,'cognito_waivers','reviewed-future.csv',['cognito_encoding'=>'Windows-1252']);fclose($s);
 ecCheck($held['skipped_count']===1&&$query('SELECT * FROM cognito_waivers WHERE id=?',[$futureSaved['id']])->fetch()===$reviewedBefore,'linked future waiver remains protected from unrelated and emergency edits');
 foreach(['Considerations','Goals','Future Field'] as $key)ecThrows(fn()=>$query('INSERT INTO raw_cognito_waivers(import_batch_id,source_row_number,raw_values,row_hash) VALUES (?,?,?,?)',[$originalBatch,99999,json_encode([$key=>'DO_NOT_STORE_12345']),str_repeat('a',64)]),'database still rejects unrelated JSON key');
 ecThrows(fn()=>$query('INSERT INTO raw_cognito_waivers(import_batch_id,source_row_number,raw_values,row_hash) VALUES (?,?,?,?)',[$originalBatch,99999,'{"Name":"legacy","EmergencyContact":"forbidden mixed format"}',str_repeat('a',64)]),'legacy JSON contract not broadened');
 $log=file_get_contents($directory.'/import-events.log');ecCheck(!str_contains($log,$base['EmergencyContact'])&&!str_contains($log,$base['Phone2'])&&!str_contains($log,'DO_NOT_STORE_12345'),'operational logs never contain emergency or ignored cell values');
 ecCheck(str_contains(file_get_contents(dirname(__DIR__).'/bin/backfill-cognito-emergency-contacts.php'),"if(PHP_SAPI!=='cli'){http_response_code(404);exit;}"),'backfill entry point rejects web execution');
 echo 'ACTUAL CSV: '.json_encode($apply).PHP_EOL;
 // Reuse existing compatibility checks in this disposable database; no production or existing DB rebuild.
 putenv('BMA_COGNITO_TEST_DATABASE='.$name);
 $command=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/cognito-compatibility.php');exec($command,$output,$status);
 ecCheck($status===0,'existing Cognito compatibility regression passes with migration 010');echo end($output).PHP_EOL;
}finally{
 if($db->inTransaction())$db->rollBack();$db->exec('DROP DATABASE `'.$name.'`');
 foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry){if($entry->isDir())rmdir($entry->getPathname());else unlink($entry->getPathname());}rmdir($directory);
}
echo 'Emergency checks: '.$checks.PHP_EOL;

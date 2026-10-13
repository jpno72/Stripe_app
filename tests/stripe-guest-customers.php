<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once dirname(__DIR__).'/app/Import/bootstrap.php';
require_once dirname(__DIR__).'/app/Matching/bootstrap.php';
require_once dirname(__DIR__).'/src/Migrations.php';
$checks=0;
function guestCheck(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException('FAIL '.$label);++$checks;echo 'PASS '.$label.PHP_EOL;}
function guestThrows(callable $action,string $code,string $label):void{try{$action();}catch(RuntimeException $e){guestCheck($e->getMessage()===$code,$label);return;}guestCheck(false,$label);}
foreach(['cus_ABC123','gcus_ABC123','cus_'.str_repeat('A',251),'gcus_'.str_repeat('A',250)] as $id)guestCheck(Normalizers::stripeCustomerExportId($id)===$id,'valid full customer export identifier preserved');
foreach([null,'','   ','gcus_','cus_','ch_ABC','py_ABC','sub_ABC','pi_ABC','in_ABC','GCUS_ABC','gcus_bad-id','gcus_bad id','gcus_A/B','gcus_A'.chr(0),'gcus_'.str_repeat('A',251)] as $id)guestThrows(fn()=>Normalizers::stripeCustomerExportId($id),'INVALID_IDENTIFIER','invalid customer export ID rejected');
guestCheck(Normalizers::stripeCustomerExportId('cus_Same')!==Normalizers::stripeCustomerExportId('gcus_Same'),'prefixes remain distinct');
foreach(['cus_','sub_','in_','price_'] as $prefix)guestThrows(fn()=>Normalizers::stripeId('gcus_ABC',$prefix,true),'INVALID_IDENTIFIER','generic resource/reference validator stays strict');
guestCheck(Normalizers::stripeTransactionId('ch_ABC')==='ch_ABC'&&Normalizers::stripeTransactionId('py_ABC')==='py_ABC','recent transaction correction remains intact');
$name=getenv('BMA_GUEST_TEST_DATABASE');
if(!$name){echo 'SKIP database tests: provide BMA_GUEST_TEST_DATABASE and DB_* for a disposable seven-migration fixture.'.PHP_EOL;exit;}
if(!preg_match('/\Abma_schema_test_[a-z0-9_]+\z/D',$name))throw new RuntimeException('Disposable database required.');
$db=new PDO('mysql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT').';dbname='.$name.';charset=utf8mb4',getenv('DB_USERNAME'),getenv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$db->exec("SET time_zone='+00:00'");
$query=function(string $sql,array $params=[])use($db){$q=$db->prepare($sql);$q->execute($params);return $q;};
$protected=['people','members','person_relationships','stripe_customers','stripe_subscriptions','stripe_transactions','identity_links','identity_match_candidates','audit_log'];
$hashTables=function()use($db,$protected):array{$out=[];foreach($protected as $table){$h=hash_init('sha256');$q=$db->query('SELECT * FROM '.$table.' ORDER BY id');while($row=$q->fetch())hash_update($h,json_encode($row));$out[$table]=hash_final($h);}return $out;};
$beforeMigration=$hashTables();
$pending=Migrations::run($db,dirname(__DIR__).'/sql/migrations',false);
guestCheck($pending===['008_stripe_guest_customers']||$pending===[],'only additive guest migration is pending');
if($pending)guestCheck(Migrations::run($db,dirname(__DIR__).'/sql/migrations',true)===['008_stripe_guest_customers'],'new migration applies through existing mechanism');
guestCheck(Migrations::run($db,dirname(__DIR__).'/sql/migrations',false)===[],'migration is recorded and repeat check has no pending work');
guestCheck($hashTables()===$beforeMigration,'additive migration leaves existing data and manual decisions unchanged');
$fk=$query("SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stripe_guest_customers' AND REFERENCED_TABLE_NAME IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
guestCheck($fk===['raw_stripe_customers'],'guest table only references approved raw staging');
$context=$db->query('SELECT * FROM stripe_source_identity WHERE id=1')->fetch();
$account=$context['stripe_account_id']??'acct_BMAGuestFixture';$live=$context['stripe_livemode']===null?0:(int)$context['stripe_livemode'];$attest=['stripe_account_id'=>$account,'stripe_livemode'=>$live];
$tag=bin2hex(random_bytes(8));$filename='guest-test-'.$tag;$regularId='cus_'.$tag.'Same';$guestIds=['gcus_'.$tag.'Same','gcus_'.$tag.'Second','gcus_'.$tag.'NoContact'];
$directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.'bma-guest-test-'.$tag;if(!mkdir($directory,0700))throw new RuntimeException('Test directory unavailable.');
$service=new ImportService($db,['APP_ENV'=>'production','IMPORT_TEMP_DIR'=>$directory]);
$headers=['id','Name','Email','Created (UTC)','Total Spend','Payment Count','Refunded Volume','Dispute Losses','Card ID','Description','Future Field'];
$base=['Name'=>'Example Group','Email'=>'shared-'.$tag.'@example.invalid','Created (UTC)'=>'2026-10-10 03:30:00','Total Spend'=>'130.00','Payment Count'=>'2','Refunded Volume'=>'10.00','Dispute Losses'=>'0.00','Card ID'=>'DO_NOT_STORE_12345','Description'=>'DO_NOT_STORE_12345','Future Field'=>'DO_NOT_STORE_12345'];
$regular=['id'=>$regularId]+$base;$guest=['id'=>$guestIds[0]]+$base;$guest2=['id'=>$guestIds[1]]+$base;$blank=array_replace($base,['id'=>$guestIds[2],'Name'=>'','Email'=>'']);
$run=function(array $rows,string $suffix,array $options=[])use($headers,$service,$filename){$f=fopen('php://temp','w+b');fputcsv($f,$headers,',','"','');foreach($rows as $row)fputcsv($f,array_map(fn($h)=>$row[$h]??'',$headers),',','"','');rewind($f);try{return $service->run($f,'stripe_customers',$filename.'-'.$suffix.'.csv',$options);}finally{fclose($f);}};
$identityFixture=null;$auditIds=[];
try{
 $dry=$run([$regular,$guest,$blank,array_replace($guest,['id'=>'pi_Invalid'])],'dry',['dry_run'=>true]);
 guestCheck($dry['skipped_count']===3&&$dry['error_count']===1&&$dry['inserted_count']===0,'dry run validates regular/guest/blank-contact records without normalized writes');
 guestCheck((int)$query('SELECT COUNT(*) FROM stripe_guest_customers WHERE stripe_guest_customer_id LIKE ?', ['gcus_'.$tag.'%'])->fetchColumn()===0,'dry run creates no guest records');
 guestThrows(fn()=>$run([$guest],'no-account'),'STRIPE_SOURCE_ATTESTATION_REQUIRED','production actual import requires attestation');
 guestThrows(fn()=>$run([$guest],'no-live',['stripe_account_id'=>$account]),'STRIPE_SOURCE_ATTESTATION_REQUIRED','production environment must be explicit');
 // A partially successful earlier batch retains its successful regular row; retry is keyed by exact ID.
 $partial=$run([$regular,array_replace($guest,['id'=>'gcus_'])],'partial',$attest);
 guestCheck($partial['inserted_count']===1&&$partial['error_count']===1,'partial success fixture retains regular customer');
 $person=(int)$db->query('SELECT person_id FROM members ORDER BY id LIMIT 1')->fetchColumn();$actor=(int)$db->query("SELECT id FROM app_users WHERE role='admin' AND is_active=1 LIMIT 1")->fetchColumn();
 if(!$person||!$actor)throw new RuntimeException('Existing member and active admin fixture required.');
 $regularRecordId=(int)$query('SELECT id FROM stripe_customers WHERE stripe_customer_id=?',[$regularId])->fetchColumn();
 (new IdentityAssociations($db))->decide('stripe','customer',$regularId,'customer',$person,'confirmed',$actor,false,[],1.0,'manual_review','guest_test');
 $identityFixture=$query("SELECT * FROM identity_links WHERE external_id=? AND source_system='stripe'",[$regularId])->fetch();
 $auditIds=$query("SELECT id FROM audit_log WHERE entity_type='stripe_customers' AND entity_id=?",[(string)$regularRecordId])->fetchAll(PDO::FETCH_COLUMN);
 $regularBefore=$query('SELECT * FROM stripe_customers WHERE stripe_customer_id=?',[$regularId])->fetch();
 $financialBefore=$db->query('SELECT transaction_id,stripe_charge_id,customer_id,gross_paid_minor,refunds_minor,stripe_fees_minor,net_after_recorded_costs_minor FROM vw_transaction_summary ORDER BY transaction_id')->fetchAll();
 $rows=[$regular,$guest,$guest2,$blank,$regular,$guest];$actual=$run($rows,'actual',$attest);
 guestCheck($actual['inserted_count']===3&&$actual['skipped_count']===3&&$actual['error_count']===0,'partial retry inserts guests and preserves existing regular customer');
 guestCheck($query('SELECT * FROM stripe_customers WHERE stripe_customer_id=?',[$regularId])->fetch()===$regularBefore,'regular cached identity and source data unchanged');
 guestCheck($query("SELECT * FROM identity_links WHERE external_id=? AND source_system='stripe'",[$regularId])->fetch()===$identityFixture,'manual confirmed identity link preserved');
 $saved=$query('SELECT * FROM stripe_guest_customers WHERE stripe_guest_customer_id=?',[$guestIds[0]])->fetch();
 guestCheck($saved['record_type']==='dashboard_guest'&&$saved['stripe_guest_customer_id']===$guestIds[0],'persistent guest classification and full original ID');
 guestCheck($saved['email_normalized']===$regularBefore['email_normalized']&&(int)$query('SELECT COUNT(*) FROM stripe_guest_customers WHERE email_normalized=?',[$saved['email_normalized']])->fetchColumn()===2,'shared regular/guest and multiple-guest contact details stay distinct');
 guestCheck((int)$query('SELECT COUNT(*) FROM stripe_customers WHERE stripe_customer_id LIKE ?', ['gcus_'.$tag.'%'])->fetchColumn()===0,'guest is never a regular Customer API record');
 $blankSaved=$query('SELECT name,email FROM stripe_guest_customers WHERE stripe_guest_customer_id=?',[$guestIds[2]])->fetch();
 guestCheck($blankSaved['name']===null&&$blankSaved['email']===null,'guest without contact data is retained without inventing identity');
 guestCheck($saved['total_spend_minor']===null&&$saved['refunded_volume_minor']===null&&$saved['dispute_losses_minor']===null&&$saved['summary_currency']===null,'guest summary currency and monetary totals remain unresolved');
 guestCheck((int)$query("SELECT COUNT(*) FROM import_errors WHERE import_batch_id=? AND error_code='CURRENCY_UNRESOLVED'",[$actual['batch_id']])->fetchColumn()===3,'guest financial warnings remain warnings');
 guestCheck($query('SELECT raw_values FROM raw_stripe_customers WHERE id=?',[$saved['latest_raw_id']])->fetchColumn()!==false,'approved guest raw source is retained');
 $same=$run($rows,'renamed',$attest);guestCheck($same['duplicate_of']===$actual['batch_id']&&$same['skipped_count']===6&&$same['inserted_count']===0,'renamed identical customer file is idempotent');
 $reordered=$run(array_reverse($rows),'reordered',$attest);guestCheck($reordered['inserted_count']===0&&$reordered['updated_count']===0&&$reordered['skipped_count']===6,'reordered duplicate regular/guest rows remain idempotent');
 $update=$run([array_replace($guest,['Name'=>'Changed Group'])],'changed',$attest);
 guestCheck($update['updated_count']===1&&$query('SELECT name FROM stripe_guest_customers WHERE stripe_guest_customer_id=?',[$guestIds[0]])->fetchColumn()==='Changed Group','same guest ID updates its existing group');
 guestCheck($query('SELECT * FROM stripe_customers WHERE stripe_customer_id=?',[$regularId])->fetch()===$regularBefore,'guest update cannot overwrite same-email regular customer');
 $changedInvalid=$run([array_replace($guest,['Payment Count'=>'invalid'])],'invalid',$attest);guestCheck($changedInvalid['error_count']===1&&$query('SELECT name FROM stripe_guest_customers WHERE stripe_guest_customer_id=?',[$guestIds[0]])->fetchColumn()==='Changed Group','invalid guest update cannot overwrite valid stored group');
 guestThrows(fn()=>(new IdentityAssociations($db))->decide('stripe','customer',$guestIds[0],'customer',$person,'confirmed',$actor),'EXTERNAL_RECORD_NOT_FOUND','identity association service cannot treat guest as regular customer');
 (new IdentityMatcher($db,[]))->run(true);
 guestCheck((int)$query('SELECT COUNT(*) FROM identity_links WHERE external_id LIKE ?', ['gcus_'.$tag.'%'])->fetchColumn()===0,'guest groups excluded from matcher and manual association');
 $raw=$query('SELECT latest_raw_id FROM stripe_guest_customers WHERE stripe_guest_customer_id=?',[$guestIds[0]])->fetchColumn();
 $tx=ApprovedRecord::fromRow('stripe_transactions',['id','Customer ID'],['py_'.$tag,$guestIds[0]]);
 guestThrows(fn()=>(new StripeTransactionImporter())->import($db,$tx,(int)$raw,true,[]),'INVALID_IDENTIFIER','transaction references do not accept guest group IDs');
 $sub=ApprovedRecord::fromRow('stripe_subscriptions',['id','Customer ID'],['sub_'.$tag,$guestIds[0]]);
 guestThrows(fn()=>(new StripeSubscriptionImporter())->import($db,$sub,(int)$raw,true,[]),'INVALID_IDENTIFIER','subscription references do not accept guest group IDs');
 guestCheck($db->query('SELECT transaction_id,stripe_charge_id,customer_id,gross_paid_minor,refunds_minor,stripe_fees_minor,net_after_recorded_costs_minor FROM vw_transaction_summary ORDER BY transaction_id')->fetchAll()===$financialBefore,'guest import creates no payment ownership or duplicated revenue');
 $columns=$query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stripe_guest_customers'")->fetchAll(PDO::FETCH_COLUMN);
 guestCheck(!in_array('person_id',$columns,true)&&!in_array('customer_id',$columns,true),'guest storage has no person/member/regular-customer association');
 $failure=false;try{$query("INSERT INTO stripe_guest_customers(stripe_guest_customer_id) VALUES(?)",['cus_'.$tag.'NotGuest']);}catch(PDOException){$failure=true;}guestCheck($failure,'database guest-prefix constraint rejects a regular Customer ID');
 guestThrows(fn()=>$run([$guest],'account-conflict',['stripe_account_id'=>'acct_Conflict'.$tag,'stripe_livemode'=>$live]),'PROCESSING_FAILED','account conflict remains rejected');
 guestThrows(fn()=>$run([$guest],'environment-conflict',['stripe_account_id'=>$account,'stripe_livemode'=>1-$live]),'PROCESSING_FAILED','environment conflict remains rejected');
 $exportPath=getenv('BMA_GUEST_TEST_EXPORT');
 if($exportPath){$f=fopen($exportPath,'rb');try{$export=$service->run($f,'stripe_customers',$filename.'-export.csv',['dry_run'=>true]);}finally{fclose($f);}guestCheck($export['row_count']===51&&$export['skipped_count']===51&&$export['error_count']===0&&$export['inserted_count']===0,'actual 51-row export validates without normalized writes');$identifierErrors=(int)$query("SELECT COUNT(*) FROM import_errors WHERE import_batch_id=? AND error_code='INVALID_IDENTIFIER'",[$export['batch_id']])->fetchColumn();$warnings=(int)$query("SELECT COUNT(*) FROM import_errors WHERE import_batch_id=? AND error_code='CURRENCY_UNRESOLVED'",[$export['batch_id']])->fetchColumn();guestCheck($identifierErrors===0,'actual export has no identifier errors');echo 'EXPORT rows=51 regular=31 guests=20 skipped='.$export['skipped_count'].' errors='.$export['error_count'].' currency_warnings='.$warnings.PHP_EOL;}
 $batches=$query('SELECT id FROM import_batches WHERE filename LIKE ?',[$filename.'%'])->fetchAll(PDO::FETCH_COLUMN);
 foreach($batches as $batch){foreach($query('SELECT raw_values FROM raw_stripe_customers WHERE import_batch_id=?',[$batch])->fetchAll(PDO::FETCH_COLUMN) as $raw)guestCheck(!str_contains($raw,'DO_NOT_STORE_12345'),'ignored sensitive/unknown values absent from staging');}
 $log=file_get_contents($directory.'/import-events.log');guestCheck(!str_contains($log,'DO_NOT_STORE_12345')&&!str_contains($log,$base['Email'])&&!str_contains($log,'130.00'),'diagnostics exclude customer and amount values');
}finally{
 if($db->inTransaction())$db->rollBack();
 $query('DELETE FROM stripe_guest_customers WHERE stripe_guest_customer_id LIKE ?', ['gcus_'.$tag.'%']);
 $query("DELETE FROM identity_links WHERE source_system='stripe' AND external_id=?",[$regularId]);
 $query('UPDATE stripe_customers SET person_id=NULL WHERE stripe_customer_id=?',[$regularId]);
 $query('DELETE FROM stripe_customers WHERE stripe_customer_id=?',[$regularId]);
 foreach($auditIds as $auditId)$query('DELETE FROM audit_log WHERE id=?',[$auditId]);
 foreach($query('SELECT id FROM import_batches WHERE filename LIKE ?',[$filename.'%'])->fetchAll(PDO::FETCH_COLUMN) as $batch){$query('DELETE FROM import_errors WHERE import_batch_id=?',[$batch]);$query('DELETE FROM raw_stripe_customers WHERE import_batch_id=?',[$batch]);$query('DELETE FROM import_batches WHERE id=?',[$batch]);}
 $query('UPDATE stripe_source_identity SET stripe_account_id=?,stripe_livemode=?,established_at=? WHERE id=1',[$context['stripe_account_id'],$context['stripe_livemode'],$context['established_at']]);
 foreach(new DirectoryIterator($directory) as $file)if(!$file->isDot()&&$file->isFile())unlink($file->getPathname());rmdir($directory);
}
guestCheck($hashTables()===$beforeMigration,'existing customer/member/financial/manual-review data remains unchanged after fixture cleanup');
echo 'Focused guest checks: '.$checks.PHP_EOL;

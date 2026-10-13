<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once dirname(__DIR__).'/app/Import/bootstrap.php';
require_once dirname(__DIR__).'/app/Revenue/RevenueRepository.php';
require_once dirname(__DIR__).'/app/Members/MemberProfileRepository.php';
$checks=0;
function paymentCheck(bool $ok,string $label):void {global $checks;if(!$ok)throw new RuntimeException('FAIL '.$label);++$checks;echo 'PASS '.$label.PHP_EOL;}
function paymentThrows(callable $action,string $code,string $label):void {try{$action();}catch(RuntimeException $e){paymentCheck($e->getMessage()===$code,$label);return;}paymentCheck(false,$label);}
foreach(['ch_ABC123','py_ABC123','ch_'.str_repeat('A',252),'py_'.str_repeat('A',252)] as $id)paymentCheck(Normalizers::stripeTransactionId($id)===$id,'valid full transaction identifier preserved');
foreach([null,'','   ','ch_','py_','pi_ABC','cus_ABC','sub_ABC','in_ABC','PY_ABC','py_bad-id','py_bad id','py_A/B','py_A'.chr(0),'py_'.str_repeat('A',253)] as $invalid)paymentThrows(fn()=>Normalizers::stripeTransactionId($invalid),'INVALID_IDENTIFIER','missing/malformed/unsupported/oversized ID rejected');
foreach(['cus_','sub_','in_'] as $prefix){paymentCheck(Normalizers::stripeId($prefix.'ABC',$prefix,true)===$prefix.'ABC','other ID validator remains strict');paymentThrows(fn()=>Normalizers::stripeId('py_ABC',$prefix,true),'INVALID_IDENTIFIER','py_ cannot substitute for another resource');}
paymentCheck(Normalizers::stripeTransactionId('ch_Same')!==Normalizers::stripeTransactionId('py_Same'),'different prefixes cannot collide');
$database=getenv('BMA_PAYMENT_TEST_DATABASE');
if(!$database){echo 'SKIP DB checks: supply BMA_PAYMENT_TEST_DATABASE and DB_* for an existing disposable reporting database.'.PHP_EOL;echo 'Focused checks: '.$checks.PHP_EOL;exit;}
if(!preg_match('/\Abma_schema_test_[a-z0-9_]+\z/D',$database))throw new RuntimeException('Disposable test database required.');
$db=new PDO('mysql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT').';dbname='.$database.';charset=utf8mb4',getenv('DB_USERNAME'),getenv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$db->exec("SET time_zone='+00:00'");
$tag=bin2hex(random_bytes(8));$filename='payment-id-'.$tag;
$directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.'bma-payment-id-'.$tag;
if(!mkdir($directory,0700))throw new RuntimeException('Test temporary directory unavailable.');
$config=['APP_ENV'=>'production','IMPORT_TEMP_DIR'=>$directory];
$service=new ImportService($db,$config);
$context=$db->query('SELECT * FROM stripe_source_identity WHERE id=1')->fetch();
$account=$context['stripe_account_id']??'acct_BMAPaymentFixture';$live=$context['stripe_livemode']===null?0:(int)$context['stripe_livemode'];
$attestation=['stripe_account_id'=>$account,'stripe_livemode'=>$live];
$linked=$db->query("SELECT c.stripe_customer_id,s.member_id FROM stripe_customers c JOIN vw_payer_member_scope s ON s.payer_person_id=c.person_id JOIN identity_links l ON l.source_system='stripe' AND l.source_type='customer' AND l.source_role='customer' AND l.external_id=c.stripe_customer_id AND l.person_id=c.person_id AND l.match_status IN ('automatic','confirmed') LIMIT 1")->fetch();
if(!$linked)throw new RuntimeException('Existing accepted payer/member fixture required.');
$ids=['ch_'.$tag.'Same','py_'.$tag.'Same','py_'.$tag.'Failed'];
$headers=['id','Created date (UTC)','Amount','Amount Refunded','Currency','Captured','Converted Amount','Converted Amount Refunded','Converted Currency','Fee','Taxes On Fee','Status','Customer ID','Invoice ID','Future Field','Card ID'];
$base=['Created date (UTC)'=>'2026-10-10 12:00:00','Amount'=>'12.34','Amount Refunded'=>'0.00','Currency'=>'usd','Captured'=>'true','Converted Amount'=>'17.28','Converted Amount Refunded'=>'0.00','Converted Currency'=>'cad','Fee'=>'0.50','Taxes On Fee'=>'0.05','Status'=>'Paid','Customer ID'=>'','Invoice ID'=>'','Future Field'=>'DO_NOT_STORE_12345','Card ID'=>'DO_NOT_STORE_12345'];
$ch=['id'=>$ids[0]]+$base;
$py=array_replace($base,['id'=>$ids[1],'Amount Refunded'=>'2.34','Converted Amount Refunded'=>'3.27','Customer ID'=>$linked['stripe_customer_id'],'Invoice ID'=>'in_'.$tag]);
$failed=array_replace($base,['id'=>$ids[2],'Status'=>'Failed']);
$csvRun=function(array $rows,string $suffix,array $options=[])use($headers,$service,$filename):array{$stream=fopen('php://temp','w+b');fputcsv($stream,$headers,',','"','');foreach($rows as $row)fputcsv($stream,array_map(fn($header)=>$row[$header]??'',$headers),',','"','');rewind($stream);try{return $service->run($stream,'stripe_transactions',$filename.'-'.$suffix.'.csv',$options);}finally{fclose($stream);}};
$query=function(string $sql,array $params=[])use($db){$q=$db->prepare($sql);$q->execute($params);return $q;};
$marks=implode(',',array_fill(0,count($ids),'?'));
try{
 paymentCheck((int)$query('SELECT COUNT(*) FROM stripe_transactions WHERE stripe_charge_id IN ('.$marks.')',$ids)->fetchColumn()===0,'fixture IDs are new');
 $bad=array_replace($base,['id'=>'pi_'.$tag]);$missing=array_replace($base,['id'=>'']);
 $dry=$csvRun([$ch,$py,$bad,$missing],'dry',['dry_run'=>true]);
 paymentCheck($dry['skipped_count']===2&&$dry['error_count']===2&&$dry['inserted_count']===0,'dry run accepts ch_/py_ as skipped and rejects invalid IDs');
 paymentCheck((int)$query('SELECT COUNT(*) FROM stripe_transactions WHERE stripe_charge_id IN ('.$marks.')',$ids)->fetchColumn()===0,'dry run creates no normalized transactions');
 paymentCheck((int)$query("SELECT COUNT(*) FROM import_errors WHERE import_batch_id=? AND error_code='INVALID_IDENTIFIER'",[$dry['batch_id']])->fetchColumn()===2,'dry-run errors retain specific diagnostic codes');
 paymentThrows(fn()=>$csvRun([$py],'no-account'),'STRIPE_SOURCE_ATTESTATION_REQUIRED','production actual import requires account and environment');
 paymentThrows(fn()=>$csvRun([$py],'no-environment',['stripe_account_id'=>$account]),'STRIPE_SOURCE_ATTESTATION_REQUIRED','production environment must be explicit');
 paymentThrows(fn()=>$csvRun([$py],'bad-account',['stripe_account_id'=>'cus_Wrong','stripe_livemode'=>0]),'INVALID_STRIPE_CONTEXT','production account must have acct_ format');
 $rows=[$ch,$py,$failed,$ch,$py];$actual=$csvRun($rows,'actual',$attestation);
 paymentCheck($actual['inserted_count']===3&&$actual['skipped_count']===2&&$actual['error_count']===0,'actual import inserts both ID types and skips duplicate ch_/py_ rows');
 $saved=$query('SELECT * FROM stripe_transactions WHERE stripe_charge_id=?',[$ids[1]])->fetch();
 paymentCheck($saved['stripe_charge_id']===$ids[1]&&(int)$saved['amount_minor']===1234&&(int)$saved['amount_refunded_minor']===234&&$saved['currency']==='usd','py_ full ID and USD cents/refunds preserved');
 paymentCheck((int)$saved['converted_amount_minor']===1728&&(int)$saved['converted_amount_refunded_minor']===327&&$saved['converted_currency']==='cad','converted and original payment currencies remain distinct');
 paymentCheck($saved['fee_minor']===null&&$saved['fee_currency']===null&&$saved['taxes_on_fee_minor']===null,'unresolved fee data remains NULL');
 paymentCheck((int)$query("SELECT COUNT(*) FROM import_errors WHERE import_batch_id=? AND error_code='CURRENCY_UNRESOLVED'",[$actual['batch_id']])->fetchColumn()===3,'fee-currency warnings do not reject valid payments');
 paymentCheck($saved['stripe_invoice_id']==='in_'.$tag&&$saved['customer_id']!==null&&$query('SELECT stripe_customer_id FROM stripe_customers WHERE id=?',[$saved['customer_id']])->fetchColumn()===$linked['stripe_customer_id'],'customer and invoice references preserved by full Stripe ID');
 paymentCheck($query('SELECT customer_id FROM stripe_transactions WHERE stripe_charge_id=?',[$ids[0]])->fetchColumn()===null,'missing optional customer stays unlinked');
 paymentCheck((int)$query('SELECT COUNT(*) FROM stripe_transactions WHERE stripe_charge_id IN ('.$marks.')',$ids)->fetchColumn()===3,'same suffix ch_/py_ records coexist safely');
 $repeat=$csvRun($rows,'renamed',$attestation);
 paymentCheck($repeat['duplicate_of']===$actual['batch_id']&&$repeat['inserted_count']===0&&$repeat['skipped_count']===5,'renamed identical file is idempotent');
 $reordered=$csvRun(array_reverse($rows),'reordered',$attestation);
 paymentCheck($reordered['inserted_count']===0&&$reordered['updated_count']===0&&$reordered['skipped_count']===5,'reordered ch_/py_ reimport creates no duplicates');
 $changed=array_replace($py,['Amount'=>'13.34']);$update=$csvRun([$changed],'changed',$attestation);
 paymentCheck($update['updated_count']===1&&(int)$query('SELECT amount_minor FROM stripe_transactions WHERE stripe_charge_id=?',[$ids[1]])->fetchColumn()===1334,'changed py_ record updates same transaction');
 $invalidAmount=array_replace($changed,['Amount'=>'not-money']);$invalid=$csvRun([$invalidAmount],'invalid-amount',$attestation);
 paymentCheck($invalid['error_count']===1&&(int)$query('SELECT amount_minor FROM stripe_transactions WHERE stripe_charge_id=?',[$ids[1]])->fetchColumn()===1334,'invalid reimport cannot overwrite valid transaction');
 $invalidRefs=$csvRun([array_replace($ch,['Customer ID'=>'py_Wrong']),array_replace($ch,['id'=>'py_'.$tag.'InvalidInvoice','Invoice ID'=>'py_Wrong'])],'invalid-references',$attestation);
 paymentCheck($invalidRefs['error_count']===2,'customer and invoice validators remain strict during imports');
 $view=$query('SELECT * FROM vw_transaction_summary WHERE stripe_charge_id=?',[$ids[1]])->fetch();
 paymentCheck((int)$view['is_paid']===1&&(int)$view['gross_paid_minor']===1334&&(int)$view['refunds_minor']===234&&$view['stripe_fees_minor']===null&&$view['net_after_recorded_costs_minor']===null,'py_ is reporting-eligible without invented fees/net');
 $repo=new RevenueRepository($db);$filter=RevenueFilters::fromRequest(['from'=>'2026-10-10','to'=>'2026-10-10','currency'=>'usd'],null);
 $page=$repo->transactionPage($filter,$ids[1],0,25,0,'desc');
 paymentCheck($page['filtered']===1&&$page['rows'][0]['stripe_charge_id']===$ids[1],'revenue dashboard lookup supports py_');
 $history=(new MemberProfileRepository($db))->transactionPage((int)$linked['member_id'],'usd',$ids[1],0,25,0,'desc');
 paymentCheck($history['filtered']===1&&$history['rows'][0]['stripe_charge_id']===$ids[1],'member transaction history supports py_');
 paymentCheck((int)$query('SELECT is_paid FROM vw_transaction_summary WHERE stripe_charge_id=?',[$ids[2]])->fetchColumn()===0,'failed py_ does not become paid revenue');
 $established=$db->query('SELECT stripe_account_id,stripe_livemode FROM stripe_source_identity WHERE id=1')->fetch();
 paymentThrows(fn()=>$csvRun([$py],'account-conflict',['stripe_account_id'=>'acct_Conflict'.$tag,'stripe_livemode'=>$live]),'PROCESSING_FAILED','different account rejected by unchanged source identity guard');
 paymentThrows(fn()=>$csvRun([$py],'environment-conflict',['stripe_account_id'=>$account,'stripe_livemode'=>1-$live]),'PROCESSING_FAILED','different environment rejected by unchanged guard');
 paymentCheck($db->query('SELECT stripe_account_id,stripe_livemode FROM stripe_source_identity WHERE id=1')->fetch()===$established,'conflicting import cannot alter established account identity');
 $batches=$query('SELECT id FROM import_batches WHERE filename LIKE ?',[$filename.'%'])->fetchAll(PDO::FETCH_COLUMN);
 foreach($batches as $batch){foreach($query('SELECT raw_values FROM raw_stripe_transactions WHERE import_batch_id=?',[$batch])->fetchAll(PDO::FETCH_COLUMN) as $raw)paymentCheck(!str_contains($raw,'DO_NOT_STORE_12345'),'unknown/sensitive values absent from staging');}
 $log=file_get_contents($directory.'/import-events.log');
 paymentCheck(!str_contains($log,'DO_NOT_STORE_12345')&&!str_contains($log,$linked['stripe_customer_id'])&&!str_contains($log,'12.34'),'diagnostic log excludes CSV values');
 foreach([getenv('BMA_PAYMENT_EXPORT_22'),getenv('BMA_PAYMENT_EXPORT_23')] as $index=>$path){if(!$path)continue;$stream=fopen($path,'rb');try{$export=$service->run($stream,'stripe_transactions',$filename.'-export'.($index+22).'.csv',['dry_run'=>true]);}finally{fclose($stream);}paymentCheck($export['error_count']===0&&$export['inserted_count']===0&&$export['skipped_count']===$export['row_count'],'actual export '.($index+22).' dry-run validates without normalized writes');$warnings=(int)$query("SELECT COUNT(*) FROM import_errors WHERE import_batch_id=? AND error_code='CURRENCY_UNRESOLVED'",[$export['batch_id']])->fetchColumn();echo 'EXPORT '.($index+22).' rows='.$export['row_count'].' skipped='.$export['skipped_count'].' errors='.$export['error_count'].' currency_warnings='.$warnings.PHP_EOL;}
}finally{
 if($db->inTransaction())$db->rollBack();
 $query('DELETE FROM stripe_transactions WHERE stripe_charge_id IN ('.$marks.')',$ids);
 $batches=$query('SELECT id FROM import_batches WHERE filename LIKE ?',[$filename.'%'])->fetchAll(PDO::FETCH_COLUMN);
 foreach($batches as $batch){$query('DELETE FROM import_errors WHERE import_batch_id=?',[$batch]);$query('DELETE FROM raw_stripe_transactions WHERE import_batch_id=?',[$batch]);$query('DELETE FROM import_batches WHERE id=?',[$batch]);}
 $query('UPDATE stripe_source_identity SET stripe_account_id=?,stripe_livemode=?,established_at=? WHERE id=1',[$context['stripe_account_id'],$context['stripe_livemode'],$context['established_at']]);
 foreach(new DirectoryIterator($directory) as $file){if(!$file->isDot()&&$file->isFile())unlink($file->getPathname());}rmdir($directory);
}
paymentCheck((int)$query('SELECT COUNT(*) FROM stripe_transactions WHERE stripe_charge_id IN ('.$marks.')',$ids)->fetchColumn()===0,'disposable fixture records cleaned up');
echo 'Focused checks: '.$checks.PHP_EOL;

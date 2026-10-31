<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once dirname(__DIR__).'/app/Import/bootstrap.php';
require_once dirname(__DIR__).'/app/Import/TransactionFeeRepair.php';
require_once dirname(__DIR__).'/app/Revenue/RevenueRepository.php';
require_once dirname(__DIR__).'/src/Migrations.php';
$checks=0;
function ledgerCheck(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException('FAIL '.$label);++$checks;echo 'PASS '.$label.PHP_EOL;}
function ledgerThrows(callable $fn,string $label):void{try{$fn();}catch(Throwable){ledgerCheck(true,$label);return;}ledgerCheck(false,$label);}
$basic=['id'=>'txn_Fixture','object'=>'balance_transaction','type'=>'charge','source'=>'ch_Fixture','amount'=>1234,'currency'=>'usd','fee'=>50,'net'=>1184];
$p=BalanceFeeEvidence::object($basic);ledgerCheck($p['fee']===50&&$p['tax']===null,'verified balance integers/currency remain distinct from CSV');
$taxed=$basic;$taxed['fee']=55;$taxed['net']=1179;$taxed['fee_details']=[['type'=>'stripe_fee','currency'=>'usd','amount'=>50],['type'=>'tax','currency'=>'usd','amount'=>5]];
ledgerCheck(BalanceFeeEvidence::object($taxed)['tax']===5,'verified tax is a component of the total fee');
$zero=array_replace($basic,['fee'=>0,'net'=>1234,'fee_details'=>[]]);ledgerCheck(BalanceFeeEvidence::object($zero)['tax']===0,'verified zero fee/tax remain zero');
ledgerCheck(BalanceFeeEvidence::object($basic+['fee_details'=>[]])['tax']===null,'empty nonzero fee breakdown never invents zero tax');
foreach([['fee'=>0.5],['net'=>999],['currency'=>'USD'],['source'=>'pi_Wrong'],['id'=>'tr_Wrong'],['type'=>'refund'],['fee'=>-1]] as $change)ledgerThrows(fn()=>BalanceFeeEvidence::object(array_replace($basic,$change)),'malformed or unrelated balance evidence rejected');
ledgerThrows(fn()=>BalanceFeeEvidence::object($basic+['fee_details'=>[['type'=>'tax','currency'=>'cad','amount'=>50]]]),'fee-detail currency mismatch rejected');
ledgerCheck(Normalizers::amount('92233720368547758.07','usd')===PHP_INT_MAX,'largest signed BIGINT minor-unit amount is exact');
ledgerThrows(fn()=>Normalizers::amount('92233720368547758.08','usd'),'minor-unit overflow rejected');
ledgerThrows(fn()=>Normalizers::amount('1.001','usd'),'unsupported decimal precision rejected without rounding');
ledgerCheck(Normalizers::amount('0.10','usd')+Normalizers::amount('0.20','usd')===30,'integer arithmetic avoids floating-point rounding');
ledgerCheck(Normalizers::stripeTransactionId('ch_Exact')==='ch_Exact'&&Normalizers::stripeTransactionId('py_Exact')==='py_Exact','both transaction prefixes remain supported');
if(getenv('BMA_PAYMENT24_DB_TEST')!=='1'){echo 'SKIP integration: set BMA_PAYMENT24_DB_TEST=1 and DB_* on a local development server.'.PHP_EOL;echo 'Focused checks: '.$checks.PHP_EOL;exit;}
if(!in_array(getenv('DB_HOST'),['127.0.0.1','localhost'],true))throw new RuntimeException('Local disposable database server required.');
$db=new PDO('mysql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT').';charset=utf8mb4',getenv('DB_USERNAME'),getenv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$name='bma_schema_test_payment24_'.bin2hex(random_bytes(6));
if(!preg_match('/\Abma_schema_test_payment24_[a-f0-9]{12}\z/D',$name))throw new RuntimeException('Invalid disposable database name.');
$db->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.$name;mkdir($directory,0700);
try{
 $db->exec('USE `'.$name.'`');foreach(Migrations::statements(file_get_contents(dirname(__DIR__).'/sql/schema.sql')) as $sql)$db->exec($sql);
 $q=$db->prepare("INSERT INTO app_users(email,email_normalized,display_name,password_hash,role) VALUES ('fixture@example.invalid','fixture@example.invalid','Fixture admin',?,'admin')");$q->execute([password_hash(bin2hex(random_bytes(24)),PASSWORD_DEFAULT)]);$actor=(int)$db->lastInsertId();
 $opts=['stripe_account_id'=>'acct_OfflineFixture','stripe_livemode'=>0];
 $service=new ImportService($db,['APP_ENV'=>'production','IMPORT_TEMP_DIR'=>$directory]);$repair=new TransactionFeeRepair($db);
 $query=function(string $sql,array $args=[])use($db){$q=$db->prepare($sql);$q->execute($args);return $q;};
 $headers=['id','Created date (UTC)','Amount','Amount Refunded','Currency','Captured','Converted Amount','Converted Amount Refunded','Converted Currency','Fee','Taxes On Fee','Status','Customer ID','Invoice ID','Transfer','Refunded date (UTC)','Future Field','Card ID','Description'];
 $base=['id'=>'ch_Test','Created date (UTC)'=>'2026-10-31 12:00:00','Amount'=>'12.34','Amount Refunded'=>'0.00','Currency'=>'usd','Captured'=>'true','Converted Amount'=>'12.34','Converted Amount Refunded'=>'0.00','Converted Currency'=>'usd','Fee'=>'0.50','Taxes On Fee'=>'0.00','Status'=>'Paid','Customer ID'=>'','Invoice ID'=>'','Transfer'=>'','Refunded date (UTC)'=>'','Future Field'=>'DO_NOT_STORE_12345','Card ID'=>'DO_NOT_STORE_12345','Description'=>'Fixture'];
 $serial=0;
 $run=function(array $rows,array $extra=[])use($service,$opts,$headers,&$serial){$f=fopen('php://temp','w+b');fputcsv($f,$headers,',','"','');foreach($rows as $row)fputcsv($f,array_map(fn($h)=>$row[$h]??'',$headers),',','"','');rewind($f);try{return $service->run($f,'stripe_transactions','fixture-'.(++$serial).'.csv',$opts+$extra);}finally{fclose($f);}};
 $batchHash=fn($batch)=>$query('SELECT file_hash FROM import_batches WHERE id=?',[$batch])->fetchColumn();
 $proofDoc=function(array $objects){return ['stripe_account_id'=>'acct_OfflineFixture','stripe_livemode'=>0,'balance_transactions'=>$objects];};
 $proofHash=fn($doc)=>hash('sha256',json_encode($doc,JSON_THROW_ON_ERROR));
 // Source bytes are processed only into the disposable database and never printed/copied into source control.
 $path=getenv('BMA_PAYMENT24_EXPORT');
 if($path){
  $input=fopen($path,'rb');try{$actual=$service->run($input,'stripe_transactions','actual-payments24.csv',$opts);}finally{fclose($input);}
  ledgerCheck($actual['row_count']===50&&$actual['inserted_count']===47&&$actual['skipped_count']===3&&$actual['error_count']===0,'actual CSV fresh import: 50 read, 47 inserted, 3 canceled skips, 0 errors');
  $codes=$query('SELECT error_code,COUNT(*) n FROM import_errors WHERE import_batch_id=? GROUP BY error_code',[$actual['batch_id']])->fetchAll(PDO::FETCH_KEY_PAIR);
  ledgerCheck((int)($codes['CANCELED_WITHOUT_TRANSACTION_ID']??0)===3&&(int)($codes['FEE_CURRENCY_UNRESOLVED']??0)===47&&!isset($codes['INVALID_IDENTIFIER']),'actual diagnostics distinguish canceled attempts and unverified source fees');
  $aggregate=$db->query('SELECT COUNT(*) total,SUM(is_paid) paid_count,SUM(gross_paid_minor) gross,SUM(refunds_minor) refunds,COUNT(CASE WHEN is_paid=1 THEN stripe_fees_minor END) known_paid_fees FROM vw_transaction_summary')->fetch();
  ledgerCheck((int)$aggregate['total']===47&&(int)$aggregate['paid_count']===46&&(int)$aggregate['gross']===383950&&(int)$aggregate['refunds']===0&&(int)$aggregate['known_paid_fees']===0,'actual financial totals: 46 paid, 383950 gross minor units, explicit zero refunds, fees unknown');
  ledgerCheck((int)$db->query("SELECT COUNT(*) FROM vw_transaction_summary WHERE LOWER(status)='failed' AND is_paid=0")->fetchColumn()===1,'actual failed transaction remains in ledger and excluded from paid revenue');
  $input=fopen($path,'rb');try{$again=$service->run($input,'stripe_transactions','actual-renamed.csv',$opts);}finally{fclose($input);}
  ledgerCheck($again['duplicate_of']===$actual['batch_id']&&$again['skipped_count']===50&&(int)$db->query('SELECT COUNT(*) FROM stripe_transactions')->fetchColumn()===47,'unchanged renamed actual CSV cannot duplicate transactions');
  ledgerCheck((int)$query("SELECT COUNT(*) FROM import_errors WHERE import_batch_id=? AND error_code='CANCELED_WITHOUT_TRANSACTION_ID'",[$again['batch_id']])->fetchColumn()===3,'canceled rows retain diagnostic traceability on identical-file reimport');
  $before=$db->query('SELECT id,stripe_charge_id,customer_id,stripe_invoice_id,amount_minor,amount_refunded_minor,currency,status,captured,stripe_created_at,stripe_balance_transaction_id,balance_amount_minor,balance_fee_minor,balance_net_minor,balance_currency FROM stripe_transactions ORDER BY id')->fetchAll();
  // Simulate the historical completed-with-errors counters without modifying its source bytes.
  $query("UPDATE import_batches SET status='completed_with_errors',skipped_count=0,error_count=3 WHERE id=?",[$actual['batch_id']]);$historicalBatch=$query('SELECT * FROM import_batches WHERE id=?',[$actual['batch_id']])->fetch();
  $none=$repair->run($actual['batch_id'],$batchHash($actual['batch_id']),$actor);
  ledgerCheck($none['selected_count']===47&&$none['eligible_count']===0&&$none['blocked_missing_evidence']===47,'repair without evidence refuses to invent fees for the existing 47 rows');
  // SYNTHETIC proof objects test conditional repair behavior; they are not real Stripe evidence.
  $objects=[];$txn=0;$sourceRows=[];$f=fopen($path,'rb');$hs=fgetcsv($f,1048576,',','"','');$hs[0]=preg_replace('/^\xEF\xBB\xBF/','',$hs[0]);
  while(($cells=fgetcsv($f,1048576,',','"',''))!==false){$row=array_combine($hs,$cells);$sourceRows[]=$row;if(strtolower($row['Status'])!=='paid')continue;$amount=Normalizers::amount($row['Amount'],$row['Currency']);$fee=Normalizers::amount($row['Fee'],'usd');$objects[]=['id'=>'txn_ActualFixture'.(++$txn),'object'=>'balance_transaction','type'=>str_starts_with($row['id'],'py_')?'payment':'charge','source'=>$row['id'],'amount'=>$amount,'currency'=>'usd','fee'=>$fee,'net'=>$amount-$fee,'fee_details'=>[['type'=>'stripe_fee','currency'=>'usd','amount'=>$fee]]];}fclose($f);
  $doc=$proofDoc($objects);$hash=$proofHash($doc);
  $dry=$repair->run($actual['batch_id'],$batchHash($actual['batch_id']),$actor,false,$doc,$hash);
  ledgerCheck($dry['eligible_count']===46&&$dry['updated_count']===0&&$dry['blocked_missing_evidence']===1&&(int)$db->query('SELECT COUNT(*) FROM audit_log')->fetchColumn()===0,'dry repair identifies 46 evidenced paid rows, leaves failed row unresolved, and writes no audit/data');
  $fixed=$repair->run($actual['batch_id'],$batchHash($actual['batch_id']),$actor,true,$doc,$hash);
  ledgerCheck($fixed['updated_count']===46&&$fixed['selected_count']===47,'unchanged raw hashes do not prevent explicit fee-only repair');
  $after=$db->query('SELECT id,stripe_charge_id,customer_id,stripe_invoice_id,amount_minor,amount_refunded_minor,currency,status,captured,stripe_created_at,stripe_balance_transaction_id,balance_amount_minor,balance_fee_minor,balance_net_minor,balance_currency FROM stripe_transactions ORDER BY id')->fetchAll();
  ledgerCheck($after===$before,'repair preserves all original IDs, amounts, capture/status, relationships and balance fields');
  $sum=$db->query('SELECT SUM(gross_paid_minor) gross,SUM(refunds_minor) refunds,SUM(stripe_fees_minor) fee,SUM(net_after_recorded_costs_minor) net FROM vw_transaction_summary WHERE is_paid=1')->fetch();
  ledgerCheck((int)$sum['gross']===383950&&(int)$sum['refunds']===0&&(int)$sum['fee']===12668&&(int)$sum['net']===371282,'synthetic verified-balance scenario reconciles fees and net without double-counting');
  ledgerCheck((int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='transaction_fee_repaired'")->fetchColumn()===46,'every actual-fixture repair has an attributable audit event');
  $query("INSERT INTO audit_log(actor_user_id,action,entity_type,entity_id,metadata) VALUES(?,'prior_fixture_event','test','unchanged','{}')",[$actor]);$priorAuditId=(int)$db->lastInsertId();$priorAudit=$query('SELECT * FROM audit_log WHERE id=?',[$priorAuditId])->fetch();
  $repeat=$repair->run($actual['batch_id'],$batchHash($actual['batch_id']),$actor,true,$doc,$hash);
  ledgerCheck($repeat['updated_count']===0&&$repeat['unchanged_count']===46&&(int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='transaction_fee_repaired'")->fetchColumn()===46,'repeated repair changes no records and creates no duplicate audit entries');
  ledgerCheck($query('SELECT * FROM import_batches WHERE id=?',[$actual['batch_id']])->fetch()===$historicalBatch&&$query('SELECT * FROM audit_log WHERE id=?',[$priorAuditId])->fetch()===$priorAudit,'repair preserves historical batch counters and pre-existing audit history');
  $filter=RevenueFilters::fromRequest(['range'=>'all','currency'=>'usd'],null);$totals=(new RevenueRepository($db))->totalsByCurrency($filter);
  ledgerCheck(count($totals)===1&&(int)$totals[0]['gross_paid_minor']===383950&&(int)$totals[0]['net_after_recorded_costs_minor']===371282,'Revenue repository agrees with the transaction-grain fixture totals');
  $input=fopen($path,'rb');try{$drySource=$service->run($input,'stripe_transactions','actual-dry.csv',$opts+['dry_run'=>true]);}finally{fclose($input);}
  ledgerCheck($drySource['inserted_count']===0&&$drySource['updated_count']===0&&$drySource['skipped_count']===50,'post-repair source dry run preserves repaired fields and normalized counts');
  echo 'ACTUAL CSV: rows=50 inserted=47 canceled_skipped=3 errors=0 paid=46 gross_minor=383950; source fee currency remains unverified.'.PHP_EOL;
  echo 'SYNTHETIC BALANCE TEST ONLY: repaired_paid=46 fees_minor=12668 net_minor=371282; failed row remained unresolved.'.PHP_EOL;
 }
 $cancel=array_replace($base,['id'=>'','Status'=>'canceled','Captured'=>'','Fee'=>'','Taxes On Fee'=>'','Amount Refunded'=>'','Converted Currency'=>'','Converted Amount'=>'','Converted Amount Refunded'=>'','Customer ID'=>'cus_CanceledOnly']);
 $c=$run([$cancel,array_replace($cancel,['Status'=>'cancelled']),$cancel]);
 ledgerCheck($c['inserted_count']===0&&$c['skipped_count']===3&&$c['error_count']===0,'canceled/cancelled/duplicate unidentified attempts are intentionally skipped');
 ledgerCheck((int)$query('SELECT COUNT(*) FROM stripe_customers WHERE stripe_customer_id=?',['cus_CanceledOnly'])->fetchColumn()===0,'canceled attempts do not create customer stubs or ownership');
 foreach([['Status'=>'Paid'],['Status'=>'succeeded'],['Captured'=>'true'],['Amount Refunded'=>'1.00'],['Fee'=>'0.00'],['Converted Currency'=>'usd']] as $change){$bad=$run([array_replace($cancel,$change)]);ledgerCheck($bad['error_count']===1&&$bad['inserted_count']===0,'missing ID with non-canceled/contradictory evidence remains an error');}
 $dryCancel=$run([$cancel],['dry_run'=>true]);ledgerCheck($dryCancel['skipped_count']===1&&$dryCancel['error_count']===0,'canceled dry run remains traceable and nonfinancial');
 $new=$run([array_replace($base,['id'=>'ch_New']),array_replace($base,['id'=>'py_New','Status'=>'Failed'])]);
 ledgerCheck($new['inserted_count']===2&&$new['error_count']===0,'new ch_/py_ records retain existing paid/failed validation');
 $unknown=$run([array_replace($base,['id'=>'ch_NoFee','Fee'=>'','Taxes On Fee'=>'']),array_replace($base,['id'=>'ch_NoSettlement','Converted Currency'=>''])]);
 ledgerCheck($unknown['inserted_count']===2&&(int)$query("SELECT COUNT(*) FROM import_errors WHERE import_batch_id=? AND error_code='CURRENCY_UNRESOLVED'",[$unknown['batch_id']])->fetchColumn()===1,'missing fee stays unknown; missing converted currency gets its own precise warning');
 foreach([['Currency'=>'not-currency'],['Converted Currency'=>'USD!'],['Amount'=>'1.001'],['Amount'=>'92233720368547758.08']] as $change){$bad=$run([array_replace($base,['id'=>'ch_Invalid'.(++$serial)],$change)]);ledgerCheck($bad['error_count']===1,'malformed currency/precision/overflow remains an import error');}
 $make=function(string $id,array $change=[])use($run,$base,$query){$b=$run([array_replace($base,['id'=>$id],$change)]);if($b['inserted_count']!==1)throw new RuntimeException('Fixture insert failed.');return [$b,(int)$query('SELECT id FROM stripe_transactions WHERE stripe_charge_id=?',[$id])->fetchColumn()];};
 $object=function(string $source,int $fee=50,string $currency='usd',int $amount=1234):array{return ['id'=>'txn_'.substr($source,3),'object'=>'balance_transaction','type'=>'charge','source'=>$source,'amount'=>$amount,'currency'=>$currency,'fee'=>$fee,'net'=>$amount-$fee];};
 [$b,$id]=$make('ch_ManualFee');$query("UPDATE stripe_transactions SET fee_minor=77,fee_currency='usd',taxes_on_fee_minor=0 WHERE id=?",[$id]);$d=$proofDoc([$object('ch_ManualFee')]);$r=$repair->run($b['batch_id'],$batchHash($b['batch_id']),$actor,true,$d,$proofHash($d));
 ledgerCheck($r['blocked_existing_fee']===1&&(int)$query('SELECT fee_minor FROM stripe_transactions WHERE id=?',[$id])->fetchColumn()===77,'conflicting legitimate existing fee is never overwritten');
 [$b,$id]=$make('ch_StoredBalance',['Customer ID'=>'cus_ExistingReference','Invoice ID'=>'in_ExistingReference']);$query("UPDATE stripe_transactions SET stripe_balance_transaction_id='txn_StoredBalance',balance_amount_minor=1234,balance_fee_minor=70,balance_net_minor=1164,balance_currency='usd' WHERE id=?",[$id]);
 $d=$proofDoc([$object('ch_StoredBalance',50)]);$r=$repair->run($b['batch_id'],$batchHash($b['batch_id']),$actor,true,$d,$proofHash($d));ledgerCheck($r['blocked_balance_conflict']===1,'external evidence cannot overwrite higher-authority conflicting balance records');
 $r=$repair->run($b['batch_id'],$batchHash($b['batch_id']),$actor,true);$s=$query('SELECT * FROM stripe_transactions WHERE id=?',[$id])->fetch();ledgerCheck($r['updated_count']===1&&(int)$s['fee_minor']===70&&(int)$s['balance_fee_minor']===70&&$s['stripe_invoice_id']==='in_ExistingReference'&&$s['customer_id']!==null,'complete existing verified balance fields support repair without external evidence and preserve references');
 [$b,$id]=$make('ch_Tax');$d=$proofDoc([array_replace($taxed,['id'=>'txn_Tax','source'=>'ch_Tax'])]);$r=$repair->run($b['batch_id'],$batchHash($b['batch_id']),$actor,true,$d,$proofHash($d));$s=$query('SELECT * FROM vw_transaction_summary WHERE transaction_id=?',[$id])->fetch();ledgerCheck((int)$s['stripe_fees_minor']===55&&(int)$s['net_after_recorded_costs_minor']===1179&&(int)$query('SELECT taxes_on_fee_minor FROM stripe_transactions WHERE id=?',[$id])->fetchColumn()===5,'nonzero verified fee tax is retained and not subtracted twice');
 [$b,$id]=$make('ch_Zero',['Fee'=>'0.00']);$d=$proofDoc([array_replace($zero,['id'=>'txn_Zero','source'=>'ch_Zero'])]);$repair->run($b['batch_id'],$batchHash($b['batch_id']),$actor,true,$d,$proofHash($d));ledgerCheck($query('SELECT fee_minor FROM stripe_transactions WHERE id=?',[$id])->fetchColumn()!==null&&(int)$query('SELECT fee_minor FROM stripe_transactions WHERE id=?',[$id])->fetchColumn()===0,'verified zero fee is persisted as zero rather than unknown');
 [$b,$id]=$make('ch_ZeroConflict');$d=$proofDoc([array_replace($zero,['id'=>'txn_ZeroConflict','source'=>'ch_ZeroConflict'])]);$r=$repair->run($b['batch_id'],$batchHash($b['batch_id']),$actor,true,$d,$proofHash($d));ledgerCheck($r['blocked_balance_conflict']===1,'zero balance fee cannot erase evidence of nonzero source cost');
 [$b,$id]=$make('ch_Fx',['Converted Currency'=>'cad','Converted Amount'=>'17.28']);$d=$proofDoc([$object('ch_Fx',100,'cad',1728)]);$repair->run($b['batch_id'],$batchHash($b['batch_id']),$actor,true,$d,$proofHash($d));$s=$query('SELECT * FROM vw_transaction_summary WHERE transaction_id=?',[$id])->fetch();ledgerCheck($s['currency']==='usd'&&(int)$s['gross_paid_minor']===1234&&$s['stripe_fees_minor']===null&&$s['net_after_recorded_costs_minor']===null,'different verified settlement currency never mixes fees with original-currency revenue');
 [$b,$id]=$make('ch_AdminDrift');$query('UPDATE stripe_transactions SET amount_minor=9999 WHERE id=?',[$id]);$d=$proofDoc([$object('ch_AdminDrift')]);$r=$repair->run($b['batch_id'],$batchHash($b['batch_id']),$actor,true,$d,$proofHash($d));ledgerCheck($r['blocked_source_drift']===1&&(int)$query('SELECT amount_minor FROM stripe_transactions WHERE id=?',[$id])->fetchColumn()===9999,'administratively changed payment amount is preserved and blocked from repair');
 [$b,$id]=$make('ch_PreserveCache');$d=$proofDoc([$object('ch_PreserveCache')]);$repair->run($b['batch_id'],$batchHash($b['batch_id']),$actor,true,$d,$proofHash($d));$changed=$run([array_replace($base,['id'=>'ch_PreserveCache','Description'=>'Changed fixture description'])]);ledgerCheck($changed['updated_count']===1&&(int)$query('SELECT fee_minor FROM stripe_transactions WHERE id=?',[$id])->fetchColumn()===50,'later CSV updates do not erase previously repaired fee values');
 ledgerThrows(fn()=>$repair->run($b['batch_id'],str_repeat('0',64),$actor),'repair is pinned to exact source file hash');
 $badDoc=$d;$badDoc['stripe_account_id']='acct_Other';ledgerThrows(fn()=>$repair->run($b['batch_id'],$batchHash($b['batch_id']),$actor,false,$badDoc,$proofHash($badDoc)),'repair evidence from another account is rejected');
 $badDoc=$d;$badDoc['stripe_livemode']=1;ledgerThrows(fn()=>$repair->run($b['batch_id'],$batchHash($b['batch_id']),$actor,false,$badDoc,$proofHash($badDoc)),'repair evidence from another environment is rejected');
 $query('UPDATE app_users SET is_active=0 WHERE id=?',[$actor]);ledgerThrows(fn()=>$repair->run($b['batch_id'],$batchHash($b['batch_id']),$actor),'inactive actor cannot apply repairs');$query('UPDATE app_users SET is_active=1 WHERE id=?',[$actor]);
 // Exercise the real CLI's dry default, private manifest guard/confirmation and apply path.
 [$b,$id]=$make('ch_Cli');$cliDoc=$proofDoc([$object('ch_Cli')]);
 $cliConfig=$directory.'/config.php';$manifest=$directory.'/balances.json';
 file_put_contents($cliConfig,'<?php return '.var_export(['APP_ENV'=>'test','PUBLIC_WEB_ROOT'=>realpath(dirname(__DIR__).'/public'),'DB_HOST'=>getenv('DB_HOST'),'DB_PORT'=>getenv('DB_PORT'),'DB_DATABASE'=>$name,'DB_USERNAME'=>getenv('DB_USERNAME'),'DB_PASSWORD'=>getenv('DB_PASSWORD')],true).';');
 file_put_contents($manifest,json_encode($cliDoc,JSON_THROW_ON_ERROR));
 $invoke=function(array $flags)use($cliConfig,$manifest,$b,$batchHash,$actor,$name){$env=getenv();$env['DB_DATABASE']=$name;$pipes=[];$process=proc_open(array_merge([PHP_BINARY,dirname(__DIR__).'/bin/repair-transaction-fees.php','--config='.$cliConfig,'--batch='.$b['batch_id'],'--file-hash='.$batchHash($b['batch_id']),'--actor-user='.$actor,'--balance-evidence='.$manifest],$flags),[1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);if(!is_resource($process))throw new RuntimeException('CLI fixture unavailable.');$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($process),$out,$err];};
 [$status,$out,$err]=$invoke([]);$report=json_decode($out,true);
 ledgerCheck($status===0&&$err===''&&($report['mode']??null)==='dry_run'&&$report['eligible_count']===1&&$query('SELECT fee_minor FROM stripe_transactions WHERE id=?',[$id])->fetchColumn()===null,'CLI defaults to a real no-write dry run with private verified evidence');
 [$status,$out,$err]=$invoke(['--apply']);ledgerCheck($status===1&&$query('SELECT fee_minor FROM stripe_transactions WHERE id=?',[$id])->fetchColumn()===null,'CLI refuses unconfirmed external evidence on apply');
 [$status,$out,$err]=$invoke(['--apply','--confirm-stripe-evidence']);$report=json_decode($out,true);
 ledgerCheck($status===0&&$err===''&&($report['updated_count']??null)===1,'CLI applies a confirmed fee-only repair successfully');
 $ignored=true;foreach($db->query('SELECT raw_values FROM raw_stripe_transactions') as $row)if(str_contains($row['raw_values'],'DO_NOT_STORE_12345'))$ignored=false;ledgerCheck($ignored,'ignored card/metadata values never enter staging');
 $log=file_get_contents($directory.'/import-events.log');ledgerCheck(!str_contains($log,'DO_NOT_STORE_12345')&&!str_contains($log,'12.34'),'operational diagnostics exclude source cells and payment amounts');
 ledgerCheck((int)$db->query('SELECT COUNT(*) FROM people')->fetchColumn()===0&&(int)$db->query('SELECT COUNT(*) FROM members')->fetchColumn()===0,'payment corrections create no people or members');
}finally{
 if($db->inTransaction())$db->rollBack();
 $db->exec('DROP DATABASE `'.$name.'`');
 foreach(new DirectoryIterator($directory) as $file)if(!$file->isDot()&&$file->isFile())unlink($file->getPathname());rmdir($directory);
}
echo 'Focused import/repair checks: '.$checks.PHP_EOL;

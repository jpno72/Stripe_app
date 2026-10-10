<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once dirname(__DIR__).'/src/AppTime.php';
require_once dirname(__DIR__).'/src/Retention.php';
require_once dirname(__DIR__).'/src/CognitoIdentity.php';
require_once dirname(__DIR__).'/src/StripeContext.php';
require_once dirname(__DIR__).'/app/Import/Normalizers.php';
require_once dirname(__DIR__).'/app/Revenue/RevenueRepository.php';
$count=0;
function timeCheck(bool $ok,string $label): void {global $count;if(!$ok)throw new RuntimeException('FAIL '.$label);$count++;echo 'PASS '.$label.PHP_EOL;}
AppTime::configure([]);
timeCheck(AppTime::name()==='America/New_York','default display timezone');
foreach ([
 ['2026-01-15 03:30:00','Jan 14, 2026, 10:30 PM EST'],
 ['2026-07-15 03:30:00','Jul 14, 2026, 11:30 PM EDT'],
 ['2026-10-10 03:30:00','Oct 9, 2026, 11:30 PM EDT'],
 ['2026-03-08 06:59:00','Mar 8, 2026, 1:59 AM EST'],
 ['2026-03-08 07:00:00','Mar 8, 2026, 3:00 AM EDT'],
 ['2026-11-01 05:30:00','Nov 1, 2026, 1:30 AM EDT'],
 ['2026-11-01 06:30:00','Nov 1, 2026, 1:30 AM EST'],
 ['2026-10-10 03:03:05','Oct 9, 2026, 11:03 PM EDT'],
] as [$utc,$expected])timeCheck(AppTime::display($utc)===$expected,'UTC instant display '.$utc);
timeCheck(AppTime::display('2026-10-10 03:03:05.123456')==='Oct 9, 2026, 11:03 PM EDT','fractional-second application timestamp display');
timeCheck(AppTime::display(null)==='Unknown'&&AppTime::display('invalid')==='Unknown','missing/invalid timestamp is unresolved');
timeCheck(AppTime::display('2026-10-10')==='Unknown','date-only value never converted as an instant');
timeCheck(AppTime::boundary('2026-03-08')==='2026-03-08 05:00:00'&&AppTime::boundary('2026-03-08',true)==='2026-03-09 04:00:00','spring business day has 23 hours');
timeCheck(AppTime::boundary('2026-11-01')==='2026-11-01 04:00:00'&&AppTime::boundary('2026-11-01',true)==='2026-11-02 05:00:00','fall business day has 25 hours');
timeCheck(AppTime::boundary('2026-10-01')==='2026-10-01 04:00:00'&&AppTime::boundary('2026-10-31',true)==='2026-11-01 04:00:00','Eastern month boundaries');
timeCheck(AppTime::boundary('2026-01-01')==='2026-01-01 05:00:00'&&AppTime::boundary('2026-12-31',true)==='2027-01-01 05:00:00','Eastern year boundaries');
timeCheck(AppTime::boundary('2026-10-05')==='2026-10-05 04:00:00'&&AppTime::boundary('2026-10-11',true)==='2026-10-12 04:00:00','Monday-through-Sunday local week boundaries');
$raw='1/15/2024 20:35';
$record=ApprovedRecord::fromRow('cognito_waivers',['Date Submitted','Name','Print Name'],[$raw,'Student Example','Parent Example']);
$before=$record->json();$identity=CognitoIdentity::identify($record,'time-fixture');
$unresolved=CognitoIdentity::submissionTime($raw,null);
timeCheck($unresolved['utc']===null&&$unresolved['timezone']===null&&$unresolved['warning']==='TIMEZONE_UNRESOLVED','naive Cognito time remains unresolved');
timeCheck($record->json()===$before&&json_decode($before,true)['Date Submitted']===$raw,'original Cognito source string preserved');
timeCheck(CognitoIdentity::identify($record,'time-fixture')===$identity,'Cognito identity fingerprint stable');
$ambiguous=CognitoIdentity::submissionTime('11/1/2026 1:30','America/New_York');
timeCheck($ambiguous['utc']===null&&$ambiguous['warning']==='AMBIGUOUS_OR_INVALID_LOCAL_TIME','ambiguous source local time not guessed');
$explicit=CognitoIdentity::submissionTime('2026-10-10T03:30:00+00:00',null);
timeCheck($explicit['utc']==='2026-10-10 03:30:00','explicit source offset is honored');
$stripe='2026-10-10 03:30:00';
$r=ApprovedRecord::fromRow('stripe_transactions',['Created date (UTC)'],[$stripe]);
$rawStripe=$r->json();
timeCheck(Normalizers::utc($stripe)===$stripe&&json_decode($r->json(),true)['Created date (UTC)']===$stripe&&$r->json()===$rawStripe,'Stripe UTC source remains byte-for-byte unchanged');
$f=RevenueFilters::fromRequest(['from'=>'2026-10-01','to'=>'2026-10-31'],null);
timeCheck($f->label()==='October 2026 · America/New_York','revenue period label agrees with calendar policy');
try{AppTime::configure(['APP_DISPLAY_TIMEZONE'=>'invalid']);timeCheck(false,'invalid timezone');}catch(RuntimeException){timeCheck(true,'invalid timezone rejected');}
AppTime::configure(['APP_DISPLAY_TIMEZONE'=>'America/New_York']);
// Optional read-only existing disposable DB + session-local temporary transaction view.
if(getenv('BMA_TIME_TEST_DATABASE')) {
 $name=getenv('BMA_TIME_TEST_DATABASE');
 if(!preg_match('/\Abma_schema_test_[a-z0-9_]+\z/D',$name))throw new RuntimeException('Disposable database required.');
 $db=new PDO('mysql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT').';dbname='.$name.';charset=utf8mb4',getenv('DB_USERNAME'),getenv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
 $db->exec("SET time_zone='+00:00'");
 $sourceHash=function()use($db):string {$hash=hash_init('sha256');foreach(['raw_stripe_customers','raw_stripe_subscriptions','raw_stripe_transactions','raw_cognito_waivers','stripe_customers','stripe_subscriptions','stripe_transactions','cognito_waivers'] as $table){$q=$db->query('SELECT * FROM '.$table.' ORDER BY id');while($row=$q->fetch())hash_update($hash,json_encode($row));}return hash_final($hash);};
 $originalHash=$sourceHash();
 $repo=new RevenueRepository($db);
 $all=RevenueFilters::fromRequest(['range'=>'all'],null);
 $baseline=$db->query('SELECT currency,SUM(gross_paid_minor) gross FROM vw_transaction_summary WHERE is_paid=1 AND stripe_created_at IS NOT NULL GROUP BY currency ORDER BY currency')->fetchAll();
 $totals=$repo->totalsByCurrency($all);
 $amountBaseline=$db->query('SELECT currency,SUM(gross_paid_minor) gross_paid_minor,CASE WHEN SUM(refund_known)=COUNT(*) THEN SUM(refunds_minor) ELSE NULL END refunds_minor,CASE WHEN SUM(fee_known)=COUNT(*) THEN SUM(stripe_fees_minor) ELSE NULL END stripe_fees_minor,CASE WHEN SUM(refund_known)=COUNT(*) AND SUM(fee_known)=COUNT(*) THEN SUM(net_after_recorded_costs_minor) ELSE NULL END net_after_recorded_costs_minor FROM vw_transaction_summary WHERE is_paid=1 AND stripe_created_at IS NOT NULL GROUP BY currency ORDER BY currency')->fetchAll();
 foreach(['refunds_minor','stripe_fees_minor','net_after_recorded_costs_minor'] as $key)timeCheck(array_column($totals,$key)===array_column($amountBaseline,$key),'all-time financial completeness and totals unchanged: '.$key);
 timeCheck(array_column($totals,'gross_paid_minor')===array_column($baseline,'gross'),'all-time gross totals unchanged at transaction grain');
 timeCheck($repo->monthlyTrend(RevenueFilters::fromRequest(['from'=>'1800-01-01','to'=>'1800-01-02'],null))===[],'empty local period remains empty');
 require_once dirname(__DIR__).'/app/Membership/MembershipRepository.php';
 require_once dirname(__DIR__).'/app/Demographics/DemographicRepository.php';
 require_once dirname(__DIR__).'/app/Members/MemberProfileRepository.php';
 $membership=(new MembershipRepository($db))->overview(MembershipFilters::fromRequest([]));
 timeCheck($membership['period']['as_of']===AppTime::today(),'membership local calendar queries execute');
 $demographic=(new DemographicRepository($db,new AgeBrackets()))->overview(DemographicFilters::fromRequest([],new AgeBrackets()));
 timeCheck($demographic['as_of']===AppTime::today(),'demographic local age/calendar queries execute');
 $profile=new MemberProfileRepository($db);
 $id=$db->query('SELECT id FROM members LIMIT 1')->fetchColumn();
 if($id){$m=$profile->find(new MemberProfileIdentifier((int)$id,null));$profile->waivers((int)$m['person_id']);$profile->trend((int)$id,'usd');timeCheck(true,'profile local trend and approved original-waiver queries execute');}
 $db->exec('CREATE TEMPORARY TABLE vw_transaction_summary AS SELECT * FROM vw_transaction_summary WHERE 1=0');
 foreach($db->query('SHOW COLUMNS FROM vw_transaction_summary')->fetchAll() as $column){$db->exec('ALTER TABLE vw_transaction_summary MODIFY `'.$column['Field'].'` '.$column['Type'].' NULL');}
 try {
  $q=$db->prepare('INSERT INTO vw_transaction_summary(transaction_id,stripe_charge_id,stripe_created_at,currency,is_paid,gross_paid_minor,refund_known,fee_known,refunds_minor,stripe_fees_minor,net_after_recorded_costs_minor,status) VALUES(?,?,?,?,1,?,1,1,0,10,?,?)');
  foreach([[1,'2026-10-01 03:59:59',100],[2,'2026-10-01 04:00:00',200],[3,'2026-11-01 03:59:59',300],[4,'2026-11-01 04:00:00',400],[5,'2026-11-01 05:30:00',500],[6,'2026-11-01 06:30:00',600]] as [$id,$date,$amount])$q->execute([$id,'ch_time'.$id,$date,'usd',$amount,$amount-10,'paid']);
  $filter=RevenueFilters::fromRequest(['from'=>'2026-10-01','to'=>'2026-10-31'],null);
  $total=$repo->totalsByCurrency($filter)[0];$chart=$repo->monthlyTrend($filter);$page=$repo->transactionPage($filter,'',0,100,0,'asc');
  timeCheck((int)$total['gross_paid_minor']===500&&(int)$total['net_after_recorded_costs_minor']===480,'Eastern October includes correct boundary charges and unchanged financial arithmetic');
  timeCheck(count($chart)===1&&$chart[0]['month_start_local']==='2026-10-01'&&(int)$chart[0]['gross_paid_minor']===500&&$page['total']===2,'local cards, chart and table agree');
  $trend=$repo->monthlyTrend($all);
  timeCheck(count($trend)===3&&array_map('intval',array_column($trend,'gross_paid_minor'))===[100,500,1500],'monthly grouping uses Eastern month across DST offsets');
  $day=RevenueFilters::fromRequest(['from'=>'2026-11-01','to'=>'2026-11-01'],null);
  timeCheck((int)$repo->totalsByCurrency($day)[0]['gross_paid_minor']===1500,'fall-back day counts both repeated local hours once');
  $params=[];$expr=AppTime::localSql('t.stripe_created_at','2026-01-01 00:00:00','2026-12-31 23:59:59',$params);
  $s=$db->prepare('SELECT stripe_created_at,'.$expr.' local_at FROM vw_transaction_summary t ORDER BY transaction_id');$s->execute($params);
  foreach($s->fetchAll() as $row)timeCheck(substr($row['local_at'],0,19)===AppTime::utc($row['stripe_created_at'])->setTimezone(AppTime::zone())->format('Y-m-d H:i:s'),'MariaDB transition expression agrees with PHP timezone rules');
 } finally {$db->exec('DROP TEMPORARY TABLE vw_transaction_summary');}
 timeCheck($sourceHash()===$originalHash,'existing raw and normalized imported records unchanged');
} else echo 'SKIP optional disposable database checks: supply BMA_TIME_TEST_DATABASE and DB_* runtime settings.'.PHP_EOL;
echo 'Focused time checks passed: '.$count.PHP_EOL;

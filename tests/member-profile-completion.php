<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/database.php';
require dirname(__DIR__).'/app/Members/MemberProfileService.php';
require dirname(__DIR__).'/app/Revenue/RevenueRepository.php';
require dirname(__DIR__).'/app/Membership/MembershipRepository.php';
require dirname(__DIR__).'/app/Demographics/DemographicRepository.php';
$config=bmaConfig();
if(($config['APP_ENV']??'')!=='test'||$config['DB_HOST']!=='127.0.0.1'||!str_starts_with($config['DB_DATABASE'],'bma_schema_test_'))throw new RuntimeException('Local disposable database required.');
$db=bmaDatabase($config);$repo=new MemberProfileRepository($db);$service=new MemberProfileService($repo);$checks=0;
function completeCheck(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException('FAIL '.$message);++$checks;echo 'PASS '.$message.PHP_EOL;}
$people=$db->query('SELECT id,bma_person_id,birth_date FROM people ORDER BY id')->fetchAll();
foreach($people as $person){$found=$repo->find(new MemberProfileIdentifier(null,null,(int)$person['id']));if(!$found||$found['bma_person_id']!==$person['bma_person_id']||$found['birth_date']!==null)throw new RuntimeException('Person route or restricted DOB projection failed');}
completeCheck(true,'all existing people can be opened, including people without membership or identity links; default projection excludes DOB');
if($people!==[]){$found=$repo->searchPeople($people[0]['bma_person_id']);completeCheck(count($found)===1&&(int)$found[0]['id']===(int)$people[0]['id'],'existing BMA ID search opens the same person');}
completeCheck(count($repo->searchPeople(''))<=50&&$repo->searchPeople("%' OR 1=1 --")===[],'people search is bounded and uses literal prepared search values');
$samples=$db->query("SELECT p.id FROM people p LEFT JOIN cognito_waivers w ON w.student_person_id=p.id GROUP BY p.id ORDER BY COUNT(w.id) DESC,p.id LIMIT 8")->fetchAll(PDO::FETCH_COLUMN);
foreach($samples as $id){
 $profile=$service->profile(new MemberProfileIdentifier(null,null,(int)$id),'',true);$waivers=$profile['waivers'];
 $q=$db->prepare("SELECT COUNT(DISTINCT w.id) FROM cognito_waivers w JOIN identity_links l ON l.external_id=w.source_locator AND l.source_system='cognito' AND l.source_type='waiver' AND l.source_role='student' AND l.match_status IN ('confirmed','automatic') WHERE l.person_id=?");$q->execute([$id]);
 completeCheck($profile['waiver_history']['count']===(int)$q->fetchColumn()&&count(array_unique(array_column($waivers,'source_locator')))===count($waivers),'waiver history includes each accepted submission once');
 $q=$db->prepare('SELECT birth_date FROM people WHERE id=?');$q->execute([$id]);$dob=$q->fetchColumn();
 completeCheck($profile['member']['birth_date']===$dob,'authorized DOB comes from the person record');
 if($dob!==null&&$dob<=AppTime::today())completeCheck((int)$profile['member']['age_years']===(new DateTimeImmutable($dob))->diff(new DateTimeImmutable(AppTime::today()))->y,'age calculated from DOB on the application local date');
}
$w=static fn($id,$date,$email,$phone)=>['source_record_id'=>$id,'submitted_at'=>$date,'contact_email'=>$email,'contact_phone'=>$phone,'address_text'=>null,'source_timezone'=>null];
$history=MemberProfileService::waiverHistory([$w('latest','2026-01-01 10:00:00','',null),$w('middle','2025-01-01 10:00:00','household@example.invalid','5705550123'),$w('old','2024-01-01 10:00:00','different@example.invalid','5705550123')]);
completeCheck($history['contacts']['contact_email']['source_record_id']==='middle'&&$history['contacts']['contact_email']['conflicting_values'],'latest nonblank source contact retains attribution and exposes historical conflict');
completeCheck(!$history['contacts']['contact_phone']['conflicting_values']&&$history['contacts']['address_text']===null,'same contacts and missing addresses are not invented conflicts');
completeCheck($history['first']['source_record_id']==='old'&&$history['last']['source_record_id']==='latest'&&$history['first']['source_timezone']===null,'waiver chronology retains unresolved timezone');
completeCheck(MemberProfileService::waiverHistory([])['count']===0,'empty waiver history remains empty');

// Use a transaction with rollback; no imported production source values are changed.
require_once dirname(__DIR__).'/src/People.php';
require_once dirname(__DIR__).'/src/IdentityAssociations.php';
try{(new IdentityAssociations($db))->transaction(function(IdentityAssociations $identity)use($db,$service):void{
 $allocator=new People($db);$payer=$allocator->createInTransaction('Completion','Payer');$child=$allocator->createInTransaction('Different','Student');$sibling=$allocator->createInTransaction('Other','Student');
 $db->prepare('INSERT INTO stripe_customers(stripe_customer_id) VALUES (?)')->execute(['cus_completion_fixture']);$customer=(int)$db->lastInsertId();
 $actor=(int)$db->query("SELECT id FROM app_users WHERE role='admin' AND is_active=1 ORDER BY id LIMIT 1")->fetchColumn();
 $identity->decide('stripe','customer','cus_completion_fixture','customer',(int)$payer['id'],'confirmed',$actor,false,[[(int)$payer['id'],(int)$child['id'],'payer'],[(int)$payer['id'],(int)$sibling['id'],'payer']]);
 foreach([['paid',10000,1000],['failed',4000,0],['refunded',5000,5000]] as $index=>[$status,$amount,$refund])$db->prepare('INSERT INTO stripe_transactions(stripe_charge_id,customer_id,status,amount_minor,amount_refunded_minor,currency,captured,description) VALUES (?,?,?,?,?,?,1,?)')->execute(['ch_completion_'.$index,$customer,$status,$amount,$refund,'usd','Fixture lesson']);
 $first=$service->profile(new MemberProfileIdentifier(null,null,(int)$child['id']),'usd',false);$second=$service->profile(new MemberProfileIdentifier(null,null,(int)$sibling['id']),'usd',false);$t=$first['totals'][0];
 completeCheck(!$first['exclusive']&&!$second['exclusive']&&$first['member']['person_id']!==$second['member']['person_id'],'siblings remain distinct; shared payer context is not student-attributed revenue');
 completeCheck((int)$t['transaction_count']===3&&(int)$t['payment_count']===1&&(int)$t['failed_count']===1&&(int)$t['fully_refunded_count']===1,'unique successful, failed and fully refunded transaction counts');
 completeCheck((int)$t['gross_paid_minor']===10000&&(int)$t['refunds_minor']===6000&&(int)$t['collections_before_fees_minor']===9000,'gross excludes failed/refunded records; collections do not subtract full refunds twice');
 completeCheck($t['stripe_fees_minor']===null&&$t['net_minor']===null,'unavailable fees and final net remain unknown');
 completeCheck(count($first['account_totals'])===1&&$first['account_totals'][0]['stripe_customer_id']==='cus_completion_fixture','financial summary identifies its verified payer account');
 completeCheck((int)$first['charge_descriptions'][0]['occurrence_count']===1,'description aggregation counts successful source charges only');
 completeCheck($first['member']['member_id']===null&&$first['subscriptions']===[],'waiver/person and payer evidence creates no membership or subscription assignment');
 throw new RuntimeException('COMPLETION_FIXTURE_ROLLBACK');
});}catch(RuntimeException $e){if($e->getMessage()!=='COMPLETION_FIXTURE_ROLLBACK')throw $e;}
$revenue=new RevenueRepository($db);$all=RevenueFilters::fromRequest(['range'=>'all'],'2026-01-01');$totals=$revenue->totalsByCurrency($all);
$sql=$db->query("SELECT currency,SUM(CASE WHEN LOWER(status) IN ('paid','succeeded') AND COALESCE(captured,1)=1 THEN amount_minor ELSE 0 END) gross FROM stripe_transactions GROUP BY currency")->fetchAll();
foreach($sql as $row){$match=array_values(array_filter($totals,static fn($t)=>$t['currency']===$row['currency']));completeCheck(count($match)===1&&(string)$match[0]['gross_paid_minor']===(string)$row['gross'],'revenue dashboard gross agrees with independent source SQL, including unassigned payments');}
completeCheck($revenue->transactionPage($all,'',0,1,0,'desc')['total']===(int)$db->query('SELECT COUNT(*) FROM stripe_transactions WHERE stripe_created_at IS NOT NULL')->fetchColumn(),'revenue table retains every dated transaction exactly once');
$trend=$revenue->monthlyTrend($all);completeCheck(array_sum(array_column($trend,'gross_paid_minor'))===array_sum(array_column($totals,'gross_paid_minor')),'all historical monthly chart totals agree with cards');
$failed=RevenueFilters::fromRequest(['range'=>'all','status'=>'failed'],'2026-01-01');completeCheck($revenue->totalsByCurrency($failed)===[]&&$revenue->transactionPage($failed,'',0,1,0,'desc')['total']===(int)$db->query("SELECT COUNT(*) FROM stripe_transactions WHERE status='failed' AND stripe_created_at IS NOT NULL")->fetchColumn(),'failed filter retains attempts in ledger without producing successful revenue');
$empty=RevenueFilters::fromRequest(['from'=>'1900-01-01','to'=>'1900-01-02'],'2026-01-01');completeCheck($revenue->totalsByCurrency($empty)===[]&&$revenue->monthlyTrend($empty)===[],'empty historical revenue range preserves unavailable metrics');
if($trend!==[]){$month=$trend[0]['month_start_local'];$f=RevenueFilters::fromRequest(['from'=>$month,'to'=>(new DateTimeImmutable($month))->modify('last day of this month')->format('Y-m-d')],'2026-01-01');completeCheck(array_sum(array_column($revenue->totalsByCurrency($f),'gross_paid_minor'))===array_sum(array_column($revenue->monthlyTrend($f),'gross_paid_minor')),'historical date filter scopes cards and chart consistently');}
$membership=(new MembershipRepository($db))->overview(new MembershipFilters());
completeCheck((int)$membership['members']['member_count']===(int)$db->query('SELECT COUNT(*) FROM members')->fetchColumn(),'membership counts members independently from people');
completeCheck((int)$membership['subscriptions']['subscription_count']===(int)$db->query('SELECT COUNT(*) FROM stripe_subscriptions')->fetchColumn(),'subscription counts use subscription records');
completeCheck((int)$membership['members']['unknown_starts']===(int)$db->query('SELECT COUNT(*) FROM members WHERE member_since IS NULL')->fetchColumn(),'unknown member starts stay unknown');
$brackets=new AgeBrackets();$demographics=new DemographicRepository($db,$brackets);$demo=$demographics->overview(new DemographicFilters());
$q=$db->prepare('SELECT COUNT(*) n,COUNT(CASE WHEN birth_date<=? THEN 1 END) known FROM members m JOIN people p ON p.id=m.person_id');$q->execute([AppTime::today()]);$expected=$q->fetch();
completeCheck((int)$demo['stats']['total_members']===(int)$expected['n']&&(int)$demo['stats']['known_ages']===(int)$expected['known'],'demographic charts count distinct actual members and known DOB, not waiver submissions or payers');
foreach(['age','youth','cities','zips'] as $key)completeCheck(array_sum(array_column($demo[$key],'count'))===(int)$expected['n'],'demographic '.$key.' groups partition the same member cohort');
$coverage=$demographics->participantCoverage();$expected=(int)$db->query("SELECT COUNT(DISTINCT w.student_person_id) FROM cognito_waivers w JOIN identity_links l ON l.external_id=w.source_locator AND l.person_id=w.student_person_id AND l.source_system='cognito' AND l.source_type='waiver' AND l.source_role='student' AND l.match_status='confirmed'")->fetchColumn();completeCheck((int)$coverage['verified_students']===$expected,'waiver participant coverage counts distinct verified people separately from member charts');
$unknown=$demographics->overview(new DemographicFilters(age:'unknown'));completeCheck($unknown['stats']['average_age']===null&&$unknown['stats']['median_age']===null,'unknown age scope never invents average or median ages');
completeCheck(!preg_match('/birth_date|medical|considerations|contact_email|member_name/',json_encode($demo)),'aggregate demographic response excludes sensitive individual fields');
echo "$checks completion checks passed; local fixture writes rolled back.\n";

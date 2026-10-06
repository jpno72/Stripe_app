<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/database.php';require dirname(__DIR__).'/app/Demographics/DemographicRepository.php';require dirname(__DIR__).'/app/Demographics/DemographicMetrics.php';
$config=bmaConfig();if(($config['APP_ENV']??'')!=='test'||!str_starts_with((string)$config['DB_DATABASE'],'bma_schema_test_'))throw new RuntimeException('Disposable test database required.');
$db=bmaDatabase($config);$brackets=new AgeBrackets();$repo=new DemographicRepository($db,$brackets);$checks=0;
function demoCheck(bool $ok,string $name):void{global $checks;if(!$ok)throw new RuntimeException('FAIL '.$name);++$checks;echo 'PASS '.$name.PHP_EOL;}
$f=new DemographicFilters();$d=$repo->overview($f);$s=$d['stats'];
demoCheck((int)$s['total_members']===(int)$db->query('SELECT COUNT(*) FROM vw_member_demographics')->fetchColumn(),'one demographic subject per member');
$q=$db->query('SELECT AVG(age_years) average_age,COUNT(age_years) known_ages FROM vw_member_demographics')->fetch();
demoCheck((int)$s['known_ages']===(int)$q['known_ages']&&$s['average_age']===$q['average_age'],'age coverage and average agree with view');
foreach(['age','youth','cities','zips'] as $key)demoCheck(array_sum(array_column($d[$key],'count'))===(int)$s['total_members'],$key.' chart counts partition scoped members');
$geo=$repo->geographyPage($f,'',0,100,2,'desc');
demoCheck(array_sum(array_column($geo['rows'],'member_count'))===(int)$s['total_members'],'geographic joins do not multiply members');
demoCheck(abs(array_sum(array_column($geo['rows'],'percent'))-100)<0.001,'geographic denominator includes unknown city');
$options=$repo->options();if($options['programs']!==[]){$p=$options['programs'][0];$filtered=$repo->overview(new DemographicFilters(program:$p));$q=$db->prepare('SELECT COUNT(DISTINCT map.member_id) FROM subscription_members map JOIN vw_subscription_item_rates i ON i.subscription_id=map.subscription_id WHERE i.product_name=? AND (map.valid_from IS NULL OR map.valid_from<=CURRENT_DATE()) AND (map.valid_until IS NULL OR map.valid_until>=CURRENT_DATE())');$q->execute([$p]);demoCheck((int)$filtered['stats']['total_members']===(int)$q->fetchColumn(),'program filter uses current explicit member assignment');}
$active=$repo->overview(new DemographicFilters(status:'active'));demoCheck((int)$active['stats']['total_members']===(int)$db->query('SELECT COUNT(*) FROM vw_active_members')->fetchColumn(),'active filter agrees with member activity view');
$empty=$repo->overview(new DemographicFilters(city:'__no_such_city__'));demoCheck((int)$empty['stats']['total_members']===0&&$empty['stats']['average_age']===null&&$empty['stats']['median_age']===null,'empty scope has no invented age values');
demoCheck(!preg_match('/birth_date|person_id|member_name|medical|considerations|goals/',json_encode($d,JSON_THROW_ON_ERROR)),'aggregate result has no dates of birth, names, IDs or waiver free text');
demoCheck(AgeBrackets::median([['age_years'=>8,'count'=>2],['age_years'=>18,'count'=>1]])===8.0,'weighted odd-count median');
$invalid=false;try{new AgeBrackets([['key'=>'bad','label'=>'bad','min'=>1,'max'=>null]]);}catch(InvalidArgumentException){$invalid=true;}demoCheck($invalid,'invalid age configuration rejected');
$invalid=false;try{DemographicFilters::fromRequest(['from'=>'2026-02-30'],$brackets);}catch(InvalidArgumentException){$invalid=true;}demoCheck($invalid,'invalid cohort date rejected');
$db->beginTransaction();
try{
    $members=$db->query('SELECT id,person_id FROM members ORDER BY id LIMIT 4')->fetchAll();if(count($members)!==4)throw new RuntimeException('Existing four-member fixture required.');
    foreach($members as $index=>$member){$age=[7,8,17,18][$index];$q=$db->prepare("UPDATE people SET birth_date=DATE_SUB(CURRENT_DATE(),INTERVAL ? YEAR),city=' Testville ',state='PA',postal_code='00501' WHERE id=?");$q->execute([$age,$member['person_id']]);}
    $db->prepare("UPDATE members SET member_status='inactive' WHERE id=?")->execute([$members[0]['id']]);
    $counts=[];foreach(['active','inactive','unknown'] as $status){$counts[$status]=(int)$repo->overview(new DemographicFilters(status:$status))['stats']['total_members'];}
    demoCheck($counts['inactive']===1&&array_sum($counts)===4,'active inactive and unknown scopes partition members');
    $fixture=$repo->overview($f);demoCheck((float)$fixture['stats']['average_age']===12.5&&$fixture['stats']['median_age']===12.5,'known-age mean and even median across 7/8/17/18 boundaries');
    demoCheck((int)$fixture['stats']['youth_count']===3&&(int)$fixture['stats']['adult_count']===1,'youth/adult boundary is 18');
    demoCheck((int)$repo->overview(new DemographicFilters(age:'under_8'))['stats']['total_members']===1&&(int)$repo->overview(new DemographicFilters(age:'8_12'))['stats']['total_members']===1,'Under 8 and 8-12 brackets do not overlap');
    $scoped=$repo->overview(new DemographicFilters(city:'Testville',zip:'00501'));demoCheck((int)$scoped['stats']['total_members']===4&&$scoped['zips'][0]['label']==='00501','trimmed city and leading-zero ZIP filters');
    $geo=$repo->geographyPage($f,'Testville',0,25,0,'asc');demoCheck($geo['total']===1&&$geo['rows'][0]['gross_paid_minor']===null,'shared payers remain unavailable as geographic revenue');
    $db->prepare('UPDATE people SET birth_date=DATE_ADD(CURRENT_DATE(),INTERVAL 1 DAY) WHERE id=?')->execute([$members[0]['person_id']]);
    $unknown=$repo->overview(new DemographicFilters(age:'unknown'));demoCheck((int)$unknown['stats']['total_members']===1&&$unknown['stats']['average_age']===null,'future birth date remains unknown age');
    $db->exec('UPDATE members SET member_since=NULL');$db->prepare('UPDATE members SET member_since=CURRENT_DATE() WHERE id=?')->execute([$members[0]['id']]);
    $today=(string)$db->query('SELECT CURRENT_DATE()')->fetchColumn();$cohort=new DemographicFilters(from:$today,to:$today);
    demoCheck((int)$repo->overview($cohort)['stats']['total_members']===1&&array_sum(array_column($repo->geographyPage($cohort,'',0,25,2,'desc')['rows'],'member_count'))===1,'cohort filter uses member_since and excludes unknown starts');
    $exclusive=$db->query("SELECT fin.member_id,fin.person_id,fin.payer_person_id,p.gross_paid_minor,p.currency FROM vw_member_financial_summary fin JOIN vw_payer_financial_summary p ON p.payer_person_id=fin.payer_person_id WHERE fin.financial_attribution_status='shared_payer_unallocated' AND p.gross_paid_minor IS NOT NULL AND p.currency='usd' LIMIT 1")->fetch();
    if(!$exclusive)throw new RuntimeException('Existing shared USD payer fixture required.');
    $db->prepare("UPDATE person_relationships SET relationship_type='guardian' WHERE person_id=? AND related_person_id<>? AND relationship_type='payer'")->execute([$exclusive['payer_person_id'],$exclusive['person_id']]);
    $db->prepare("UPDATE people SET city='ExclusiveFixture' WHERE id=?")->execute([$exclusive['person_id']]);
    $revenue=$repo->geographyPage(new DemographicFilters(city:'ExclusiveFixture'),'',0,25,2,'desc')['rows'];
    demoCheck(count($revenue)===1&&$revenue[0]['gross_paid_minor']===$exclusive['gross_paid_minor']&&$revenue[0]['currency']==='usd','exclusive geographic gross equals payer view once');
}finally{$db->rollBack();}
echo 'Checks: '.$checks.PHP_EOL;echo 'Existing fixture coverage: '.json_encode($s,JSON_THROW_ON_ERROR).PHP_EOL;

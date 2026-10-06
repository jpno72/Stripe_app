<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/database.php';
require dirname(__DIR__).'/app/Membership/MembershipRepository.php';
require dirname(__DIR__).'/app/Membership/MembershipMetrics.php';
$config=bmaConfig();
if (($config['APP_ENV']??'')!=='test' || !str_starts_with((string)$config['DB_DATABASE'],'bma_schema_test_')) {throw new RuntimeException('Disposable test database required.');}
$db=bmaDatabase($config);$repo=new MembershipRepository($db);$count=0;
function membershipCheck(bool $passed,string $name):void {global $count;if(!$passed)throw new RuntimeException('FAIL '.$name);$count++;echo 'PASS '.$name.PHP_EOL;}
$f=new MembershipFilters();$result=$repo->overview($f);
membershipCheck((int)$result['members']['active_members']===(int)$db->query('SELECT COUNT(*) FROM vw_active_members')->fetchColumn(),'active members agree with member view');
membershipCheck((int)$result['subscriptions']['active_subscriptions']===(int)$db->query("SELECT COUNT(*) FROM vw_subscription_summary WHERE subscription_status='active'")->fetchColumn(),'active subscriptions agree with subscription view');
membershipCheck((int)$result['members']['member_count']===(int)$db->query('SELECT COUNT(*) FROM members')->fetchColumn(),'one member per normalized member record');
membershipCheck(array_sum(array_column($result['statuses'],'count'))===(int)$result['subscriptions']['subscription_count'],'status chart partitions subscriptions');
membershipCheck(array_sum(array_column($result['mix'],'count'))===(int)$result['subscriptions']['subscription_count'],'program buckets partition subscriptions without item multiplication');
$page=$repo->memberPage($f,'',0,100,1,'asc');
membershipCheck($page['total']===(int)$result['members']['member_count'] && count(array_unique(array_column($page['rows'],'bma_person_id')))===count($page['rows']),'member table has no multiplied rows');
membershipCheck((int)$result['members']['unknown_starts']===0 || $result['members']['new_members']===null,'unknown start dates block complete new-member KPI');
$cards=MembershipMetrics::cards($result);
$unknownActive=$db->query("SELECT COUNT(*) FROM vw_subscription_summary WHERE subscription_status='active' AND (currency IS NULL OR active_mrr_minor IS NULL)")->fetchColumn();
membershipCheck((int)$unknownActive===0 || $cards[5][1]==='Unavailable','unresolved MRR not invented');
$options=$repo->options();
if ($options['programs']!==[]) {
    $program=(string)$options['programs'][0];$scoped=$repo->overview(new MembershipFilters($program));
    $q=$db->prepare('SELECT COUNT(*) FROM vw_subscription_summary s WHERE EXISTS (SELECT 1 FROM vw_subscription_item_rates i WHERE i.subscription_id=s.subscription_id AND i.product_name=?)');$q->execute([$program]);
    membershipCheck((int)$scoped['subscriptions']['subscription_count']===(int)$q->fetchColumn(),'program filter counts subscriptions once even with multiple items');
}
$empty=new MembershipFilters('', '__no_such_member_status__');$emptyResult=$repo->overview($empty);
membershipCheck((int)$emptyResult['members']['member_count']===0 && (int)$emptyResult['subscriptions']['subscription_count']===0 && $repo->memberPage($empty,'',0,25,1,'asc')['total']===0,'empty filter scopes cards charts table');
if ($page['rows']!==[]) {
    $first=$page['rows'][0];$found=$repo->memberPage($f,$first['bma_person_id'],0,25,0,'desc');
    membershipCheck($found['filtered']===1,'table search by immutable BMA ID');
}
foreach ($page['rows'] as $row) {
    if ($row['financial_attribution_status']==='shared_payer_unallocated') {membershipCheck($row['last_payment_at']===null,'shared payer last payment not assigned to member');break;}
}
membershipCheck(MembershipMetrics::rate('1083.3333','usd')==='1,083.3333 USD','fractional monthly-equivalent minor units preserved');
$invalid=false;try {MembershipFilters::fromRequest(['program'=>['bad']]);}catch(InvalidArgumentException){$invalid=true;}
membershipCheck($invalid,'array filter input rejected');
$activeScope=$repo->overview(new MembershipFilters('','active'));
$q=$db->query("SELECT COUNT(DISTINCT map.subscription_id) FROM subscription_members map JOIN vw_member_summary m ON m.member_id=map.member_id WHERE m.member_status='active' AND (map.valid_from IS NULL OR map.valid_from<=CURRENT_DATE()) AND (map.valid_until IS NULL OR map.valid_until>=CURRENT_DATE())");
membershipCheck((int)$activeScope['subscriptions']['subscription_count']===(int)$q->fetchColumn(),'member-status filter scopes subscriptions through explicit current assignments');
$activeSubs=new MembershipFilters('','','active');$scoped=$repo->overview($activeSubs);
membershipCheck($repo->memberPage($activeSubs,'',0,100,1,'asc')['total']===(int)$scoped['members']['member_count'] && count($scoped['statuses'])<=1,'subscription-status filter scopes chart and member table consistently');
$db->beginTransaction();
try {
    $id=(int)$db->query('SELECT MIN(id) FROM stripe_subscriptions')->fetchColumn();
    $db->prepare("UPDATE stripe_subscriptions SET status='canceled',canceled_at=NULL WHERE id=?")->execute([$id]);
    $fixture=$repo->overview(new MembershipFilters());
    membershipCheck($fixture['subscriptions']['canceled_this_month']===null,'canceled status with missing date remains unresolved');
    $db->prepare('UPDATE stripe_subscriptions SET canceled_at=CURRENT_TIMESTAMP() WHERE id=?')->execute([$id]);
    $fixture=$repo->overview(new MembershipFilters());
    membershipCheck((int)$fixture['subscriptions']['known_canceled']>=1 && count($fixture['cancellations'])>=1,'dated cancellation enters this-month KPI and event chart');
    $id=(int)$db->query('SELECT MIN(id) FROM members')->fetchColumn();
    $db->prepare("UPDATE members SET member_since='2024-01-01',member_until='2025-01-01',member_status='inactive' WHERE id=?")->execute([$id]);
    $fixture=$repo->overview(new MembershipFilters());
    membershipCheck((int)$fixture['members']['completed_spells']===1 && (float)$fixture['members']['average_completed_days']===366.0,'completed membership length uses factual member dates');
} finally {$db->rollBack();}
echo 'Checks: '.$count.PHP_EOL;
echo 'Snapshot: '.json_encode(['members'=>$result['members'],'subscriptions'=>$result['subscriptions'],'mrr'=>$result['mrr'],'retention'=>$result['retention']],JSON_THROW_ON_ERROR).PHP_EOL;

<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
require dirname(__DIR__) . '/src/database.php';
require dirname(__DIR__) . '/src/People.php';
require dirname(__DIR__) . '/src/IdentityAssociations.php';

$config = bmaConfig();
if (($config['APP_ENV'] ?? '') !== 'test' || !str_starts_with((string)($config['DB_DATABASE'] ?? ''),'bma_schema_test_')) {
    throw new RuntimeException('Disposable test database required.');
}
$db = bmaDatabase($config);
$tag = bin2hex(random_bytes(5));
$checks = 0;
function reportCheck(bool $pass,string $name): void { global $checks; if (!$pass) { throw new RuntimeException($name); } $checks++; echo "PASS $name\n"; }
function reportValue(PDO $db,string $sql,array $params=[]): mixed { $q=$db->prepare($sql); $q->execute($params); return $q->fetchColumn(); }

$email = 'report-' . $tag . '@example.invalid';
$db->prepare("INSERT INTO app_users(email,email_normalized,display_name,password_hash,role) VALUES (?,?,?,?,'admin')")
   ->execute([$email,$email,'Reporting fixture',password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT)]);
$admin = (int)$db->lastInsertId();
$people = new People($db);
$payer = $people->create('Reporting','Payer');
$first = $people->create('Reporting','First');
$second = $people->create('Reporting','Second');
$customerExternal = 'cus_REPORT_' . $tag;
$db->prepare('INSERT INTO stripe_customers(stripe_customer_id,name) VALUES (?,?)')->execute([$customerExternal,'Reporting Payer']);
$customer = (int)$db->lastInsertId();
$identity = new IdentityAssociations($db);
$identity->decide('stripe','customer',$customerExternal,'customer',(int)$payer['id'],'confirmed',$admin);
foreach ([$first,$second] as $index=>$memberPerson) {
    $locator = 'cog-v1-' . hash('sha256','report-' . $tag . '-' . $index);
    $db->prepare('INSERT INTO cognito_waivers(source_id_kind,form_namespace,source_locator,content_hash,source_name) VALUES (?,?,?,?,?)')
       ->execute(['fingerprint','reporting-fixture',$locator,hash('sha256',$locator),'Reporting student']);
    $identity->decide('cognito','waiver',$locator,'student',(int)$memberPerson['id'],'confirmed',$admin,true,
        [[(int)$payer['id'],(int)$memberPerson['id'],'payer']]);
}
$db->prepare("UPDATE people SET birth_date='2010-01-01',city='Bloomsburg',state='PA',postal_code='17815' WHERE id=?")->execute([$first['id']]);
$db->prepare("UPDATE members SET member_status='active',member_since='2025-01-01' WHERE person_id=?")->execute([$first['id']]);
$firstMember = (int)reportValue($db,'SELECT id FROM members WHERE person_id=?',[$first['id']]);
$secondMember = (int)reportValue($db,'SELECT id FROM members WHERE person_id=?',[$second['id']]);

function addSubscription(PDO $db,int $customer,string $external,string $tag,array $items): int {
    $db->prepare("INSERT INTO stripe_subscriptions(stripe_subscription_id,customer_id,status) VALUES (?,?,'active')")->execute([$external,$customer]);
    $id=(int)$db->lastInsertId();
    foreach ($items as $n=>$item) {
        $db->prepare('INSERT INTO stripe_subscription_items(subscription_id,source_locator,product_name_snapshot,amount_minor,amount_basis,currency,billing_interval,billing_interval_count,quantity) VALUES (?,?,?,?,?,?,?,?,?)')
           ->execute([$id,'test-' . $tag . '-' . $n,$item[0],$item[1],$item[2],$item[3],$item[4],$item[5],$item[6]]);
    }
    return $id;
}
$multi = addSubscription($db,$customer,'sub_REPORT_MULTI_' . $tag,$tag . '-multi',[
    ['Program A',5000,'csv_reported','usd','month',1,null],
    ['Program B',4000,'csv_reported','usd','month',1,null],
]);
$annual = addSubscription($db,$customer,'sub_REPORT_ANNUAL_' . $tag,$tag . '-annual',[
    ['Annual Program',12000,'csv_reported','usd','year',1,null],
]);
$rates = addSubscription($db,$customer,'sub_REPORT_RATES_' . $tag,$tag . '-rates',[
    ['Quarterly',30000,'csv_reported','usd','quarter',1,null],
    ['Weekly',1000,'csv_reported','usd','week',1,null],
    ['Two units',2000,'unit','usd','month',1,2],
]);
$db->prepare('INSERT INTO subscription_members(subscription_id,member_id) VALUES (?,?)')->execute([$multi,$firstMember]);
$db->prepare('INSERT INTO subscription_members(subscription_id,member_id) VALUES (?,?)')->execute([$multi,$secondMember]);
$db->prepare('INSERT INTO subscription_members(subscription_id,member_id) VALUES (?,?)')->execute([$annual,$firstMember]);
$db->prepare('INSERT INTO stripe_transactions(stripe_charge_id,customer_id,subscription_id,stripe_created_at,amount_minor,amount_refunded_minor,currency,captured,status,fee_minor,fee_currency) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
   ->execute(['ch_REPORT_MULTI_' . $tag,$customer,$multi,'2026-09-01 12:00:00',9000,0,'usd',1,'Paid',300,'usd']);
$db->prepare('INSERT INTO stripe_transactions(stripe_charge_id,customer_id,subscription_id,stripe_created_at,amount_minor,amount_refunded_minor,currency,captured,status,fee_minor,fee_currency) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
   ->execute(['ch_REPORT_ANNUAL_' . $tag,$customer,$annual,'2026-09-02 12:00:00',12000,0,'usd',1,'Paid',200,'usd']);
$db->prepare('INSERT INTO stripe_transactions(stripe_charge_id,customer_id,stripe_created_at,amount_minor,currency,captured,status) VALUES (?,?,?,?,?,?,?)')
   ->execute(['ch_REPORT_FAILED_' . $tag,$customer,'2026-09-03 12:00:00',9999,'usd',0,'Failed']);

$q=$db->prepare('SELECT item_count,currency,active_mrr_minor,explicitly_linked_member_count FROM vw_subscription_summary WHERE subscription_id=?');
$q->execute([$multi]); $summary=$q->fetch();
reportCheck((int)$summary['item_count']===2 && $summary['currency']==='usd' && (float)$summary['active_mrr_minor']===9000.0
    && (int)$summary['explicitly_linked_member_count']===2,'multi-item subscription sums once and identifies two members');
reportCheck((float)reportValue($db,'SELECT active_mrr_minor FROM vw_subscription_summary WHERE subscription_id=?',[$annual])===1000.0,
    'annual interval divides by twelve');
$q=$db->prepare('SELECT product_name,monthly_equivalent_minor FROM vw_subscription_item_rates WHERE subscription_id=?');$q->execute([$rates]);
$itemRates=[];foreach($q as $row){$itemRates[$row['product_name']]=(float)$row['monthly_equivalent_minor'];}
reportCheck($itemRates['Quarterly']===10000.0 && abs($itemRates['Weekly']-4333.3333)<0.001 && $itemRates['Two units']===4000.0,
    'quarterly weekly and unit-quantity rates normalize correctly');
reportCheck((int)reportValue($db,"SELECT COUNT(*) FROM vw_transaction_summary WHERE stripe_charge_id IN (?,?,?)",['ch_REPORT_MULTI_' . $tag,'ch_REPORT_ANNUAL_' . $tag,'ch_REPORT_FAILED_' . $tag])===3,
    'transaction view retains one row per charge');
reportCheck((int)reportValue($db,"SELECT SUM(gross_paid_minor) FROM vw_transaction_summary WHERE stripe_charge_id IN (?,?,?)",['ch_REPORT_MULTI_' . $tag,'ch_REPORT_ANNUAL_' . $tag,'ch_REPORT_FAILED_' . $tag])===21000,
    'failed payment excluded from gross paid');
reportCheck((int)reportValue($db,"SELECT SUM(gross_paid_minor) FROM vw_revenue_by_program WHERE program_name='Annual Program' AND month_start_utc='2026-09-01'")===12000
    && (int)reportValue($db,"SELECT COUNT(*) FROM vw_revenue_by_program WHERE program_name IN ('Program A','Program B')")===0,
    'program revenue uses only direct single-item subscription references');
$q=$db->prepare('SELECT financial_attribution_status,gross_paid_minor,monthly_equivalent_minor,subscription_allocation_status FROM vw_member_financial_summary WHERE member_id=?');
$q->execute([$firstMember]);$a=$q->fetch();$q->execute([$secondMember]);$b=$q->fetch();
reportCheck($a['financial_attribution_status']==='shared_payer_unallocated' && $b['financial_attribution_status']==='shared_payer_unallocated'
    && $a['gross_paid_minor']===null && $b['gross_paid_minor']===null,
    'shared payer does not duplicate member revenue');
reportCheck($a['subscription_allocation_status']==='multiple_subscriptions' && $b['subscription_allocation_status']==='shared_subscription_unallocated'
    && $a['monthly_equivalent_minor']===null && $b['monthly_equivalent_minor']===null,
    'shared subscription and multiple subscriptions do not duplicate member MRR');
reportCheck((int)reportValue($db,'SELECT COUNT(*) FROM vw_active_members WHERE member_id=?',[$firstMember])===1
    && (int)reportValue($db,'SELECT retained_12_months FROM vw_member_retention WHERE member_id=?',[$firstMember])===1,
    'active membership and factual twelve-month retention');
reportCheck((string)reportValue($db,'SELECT adult_youth FROM vw_member_demographics WHERE member_id=?',[$firstMember])==='youth',
    'age category derives from birth date');
echo "Reporting integration checks passed: $checks.\n";

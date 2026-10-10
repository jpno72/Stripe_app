<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/database.php';
require dirname(__DIR__) . '/app/Revenue/RevenueRepository.php';
$config=bmaConfig();
if (($config['APP_ENV'] ?? '') !== 'test' || !str_starts_with((string)$config['DB_DATABASE'], 'bma_schema_test_')) { throw new RuntimeException('Disposable test database required.'); }
$db=bmaDatabase($config); $repo=new RevenueRepository($db); $checks=0;
function check(bool $ok,string $name): void { global $checks; ++$checks; if (!$ok) { throw new RuntimeException('FAIL: '.$name); } echo 'PASS: '.$name.PHP_EOL; }
$month=$repo->latestMonth();
check($month !== null,'latest month exists');
$last=(new DateTimeImmutable($month,new DateTimeZone('UTC')))->modify('last day of this month')->format('Y-m-d');
$f=RevenueFilters::fromRequest(['from'=>$month,'to'=>$last,'currency'=>'usd'],$month);
$actual=$repo->totalsByCurrency($f);
$q=$db->prepare('SELECT SUM(gross_paid_minor) gross_paid_minor,CASE WHEN SUM(refund_known)=COUNT(*) THEN SUM(refunds_minor) ELSE NULL END refunds_minor,CASE WHEN SUM(fee_known)=COUNT(*) THEN SUM(stripe_fees_minor) ELSE NULL END stripe_fees_minor,CASE WHEN SUM(refund_known)=COUNT(*) AND SUM(fee_known)=COUNT(*) THEN SUM(net_after_recorded_costs_minor) ELSE NULL END net_after_recorded_costs_minor,COUNT(*) transaction_count FROM vw_transaction_summary WHERE stripe_created_at>=? AND stripe_created_at<? AND currency=? AND is_paid=1');
$q->execute([AppTime::boundary($month),AppTime::boundary($last,true),'usd']); $view=$q->fetch();
check(count($actual)===1 && $view !== false,'month and currency produce one comparable row');
foreach (['gross_paid_minor','refunds_minor','stripe_fees_minor','net_after_recorded_costs_minor'] as $key) {
    check($actual[0][$key] === $view[$key] || (string)$actual[0][$key] === (string)$view[$key], 'KPI agrees with local transaction period: '.$key);
}
check((int)$actual[0]['paid_count']===(int)$view['transaction_count'],'paid charge count agrees with local transaction period');
$page=$repo->transactionPage($f,'',0,100,0,'desc');
$q=$db->prepare('SELECT COUNT(*) FROM vw_transaction_summary WHERE stripe_created_at>=? AND stripe_created_at<? AND currency=?');
$q->execute([AppTime::boundary($month),AppTime::boundary($last,true),'usd']);
$expected=(int)$q->fetchColumn();
check($page['total']===$expected,'transaction table preserves one charge per view row');
$status=RevenueFilters::fromRequest(['from'=>$month,'to'=>$last,'currency'=>'usd','status'=>'paid'],$month);
$statusRows=$repo->transactionPage($status,'',0,100,0,'desc');
check($statusRows['total'] <= $page['total'] && count($statusRows['rows'])===$statusRows['total'],'status filter scopes table');
check((int)$repo->totalsByCurrency($status)[0]['gross_paid_minor'] <= (int)$actual[0]['gross_paid_minor'],'status filter scopes cards');
$missing=RevenueFilters::fromRequest(['from'=>'1999-01-01','to'=>'1999-01-31','currency'=>'usd'],$month);
check($repo->totalsByCurrency($missing)===[] && $repo->monthlyTrend($missing)===[] && $repo->transactionPage($missing,'',0,20,0,'desc')['total']===0,'empty period yields empty cards charts table');
$mrr=$repo->currentMrr('usd');
check($mrr['amount_minor']===null,'unresolved active MRR stays null');
$unresolved=$db->query("SELECT COUNT(*) FROM vw_transaction_summary WHERE is_paid=1 AND stripe_fees_minor IS NULL")->fetchColumn();
check((int)$unresolved>0,'missing Stripe fees remain in view');
$unknown=$repo->totalsByCurrency(RevenueFilters::fromRequest(['range'=>'all'],$month));
check(count($unknown)===1 && $unknown[0]['stripe_fees_minor']===null && $unknown[0]['net_after_recorded_costs_minor']===null,'all-date totals keep incomplete fees and net unknown');
$program=RevenueFilters::fromRequest(['from'=>$month,'to'=>$last,'currency'=>'usd','program'=>'Annual Program'],$month);
$programRows=$repo->transactionPage($program,'',0,100,0,'desc');
check($programRows['total']===1 && (int)$repo->totalsByCurrency($program)[0]['gross_paid_minor']===12000,'program filter scopes table cards and chart source');
check(count($repo->programBreakdown($program))===1 && $repo->programBreakdown($program)[0]['program_name']==='Annual Program','program chart follows program filter');
$member=RevenueFilters::fromRequest(['from'=>$month,'to'=>$last,'currency'=>'usd','person'=>'BMA-000013'],$month);
check($repo->transactionPage($member,'',0,100,0,'desc')['total']===1,'member filter uses exclusive subscription assignment');
check((int)$repo->totalsByCurrency($member)[0]['gross_paid_minor']===12000,'member filter card agrees with transaction');
$payer=RevenueFilters::fromRequest(['from'=>$month,'to'=>$last,'currency'=>'usd','person'=>'BMA-000012'],$month);
check($repo->transactionPage($payer,'',0,100,0,'desc')['total']===3 && (int)$repo->totalsByCurrency($payer)[0]['gross_paid_minor']===21000,'shared payer charges counted once');
check($repo->transactionPage($f,'ANNUAL',0,100,0,'desc')['filtered']===1,'transaction search scopes table');
echo 'Checks: '.$checks.PHP_EOL;

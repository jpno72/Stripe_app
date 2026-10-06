<?php
declare(strict_types=1);
require_once __DIR__.'/admin/_common.php';
require_once dirname(__DIR__).'/app/Membership/MembershipRepository.php';
require_once dirname(__DIR__).'/app/Membership/MembershipMetrics.php';
$config=adminConfig();requireDashboardAccess('membership');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){http_response_code(405);header('Allow: GET');exit('Read-only report.');}
try {
    $repository=new MembershipRepository(bmaDatabase($config));
    $filters=MembershipFilters::fromRequest($_GET);$options=$repository->options();$data=$repository->overview($filters);
    $cards=MembershipMetrics::cards($data);$retention=MembershipMetrics::retention($data['retention']);$error=null;
} catch (InvalidArgumentException $exception) {http_response_code(400);$error=$exception->getMessage();}
catch (Throwable) {Security::log($config,'REPORT_UNAVAILABLE');http_response_code(503);$error='Membership reporting is unavailable. Check the database and reporting views.';}
uiLayoutStart('Membership','membership','Members, subscriptions and Stripe customers are separate records.', ['https://cdn.datatables.net/v/bs5/dt-3.1.3/datatables.min.css','/assets/css/membership.css']);
if ($error!==null) {uiAlert($error);uiEmptyState('Membership unavailable','Correct the filters or check the reporting database.');uiLayoutEnd();exit;}
?>
<form class="filter-bar membership-filter mb-4" method="get" action="/membership" aria-label="Membership filters"><div class="row g-3 align-items-end">
<?php foreach ([['program','Program / product',$options['programs'],$filters->program],['member_status','Member status',$options['member_statuses'],$filters->memberStatus],['subscription_status','Subscription status',$options['subscription_statuses'],$filters->subscriptionStatus]] as [$key,$label,$choices,$value]): ?>
<div class="col-12 col-md-3"><label class="form-label" for="membership-<?=uiEscape($key)?>"><?=uiEscape($label)?></label><select class="form-select" id="membership-<?=uiEscape($key)?>" name="<?=uiEscape($key)?>"><option value="">All</option><?php foreach ($choices as $choice): ?><option value="<?=uiEscape($choice)?>" <?=$choice===$value?'selected':''?>><?=uiEscape($choice)?></option><?php endforeach; ?></select></div>
<?php endforeach; ?><div class="col-12 col-md-3 d-flex gap-2"><button class="btn btn-primary" type="submit">Apply filters</button><a class="btn btn-outline-secondary" href="/membership">Reset</a></div></div></form>
<?php
uiAlert('Snapshot as of '.$data['period']['as_of'].' UTC. Selected scope: '.$data['members']['member_count'].' members, '.$data['subscriptions']['subscription_count'].' subscriptions, '.$data['subscriptions']['customer_count'].' distinct subscription customers. Product/subscription filters require explicit current member links.','info');
echo '<div class="row g-3 mb-4">';foreach ($cards as [$label,$value,$detail]) {echo '<div class="col-12 col-sm-6 col-xl-3">';uiKpiCard($label,$value,$detail);echo '</div>';}echo '</div>';
?>
<div class="row g-3 mb-4"><div class="col-12 col-xl-6"><?php uiChartCardStart('Active members trend','Monthly historical membership');uiEmptyState('Historical trend unavailable','Current status and isolated dates do not establish complete historical membership periods.');uiChartCardEnd(); ?></div>
<div class="col-12 col-xl-6"><?php uiChartCardStart('Recorded new members and canceled subscriptions','Last 12 months - separate entity counts; only recorded dates, incomplete history.'); ?><div class="membership-chart"><canvas id="membership-events" aria-label="Recorded new members and canceled subscriptions" role="img"></canvas></div><p id="membership-events-empty" hidden class="text-secondary membership-chart-empty">No dated member starts or subscription cancellations in this period.</p><?php uiChartCardEnd(); ?></div></div>
<div class="row g-3 mb-4"><div class="col-12 col-xl-6"><?php uiChartCardStart('Subscription / product mix','One bucket per subscription, including multi-item and unassigned subscriptions.'); ?><div class="membership-chart"><canvas id="membership-mix" aria-label="Subscription product mix" role="img"></canvas></div><p id="membership-mix-empty" hidden class="text-secondary membership-chart-empty">No subscriptions match these filters.</p><?php uiChartCardEnd(); ?></div>
<div class="col-12 col-xl-6"><?php uiChartCardStart('Subscription status distribution','Actual current Stripe status values; counts are subscriptions.'); ?><div class="membership-chart"><canvas id="membership-statuses" aria-label="Subscription status distribution" role="img"></canvas></div><p id="membership-statuses-empty" hidden class="text-secondary membership-chart-empty">No subscriptions match these filters.</p><?php uiChartCardEnd(); ?></div></div>
<?php
uiCardStart('Membership milestone retention','Known results from factual member start/end dates and explicit active status. This is milestone status, not a historical cohort curve.');
echo '<div class="row g-3">';foreach ($retention as $metric) {echo '<div class="col-12 col-md-4">';uiKpiCard($metric['label'],$metric['value'],$metric['detail']);echo '</div>';}echo '</div>';uiCardEnd();
echo '<div class="mt-4">';uiCardStart('Members','One row per member. Rates are currency minor units/month. Shared household payments and subscription rates remain unallocated; unknown facts stay unavailable.','table-card');
echo '<div class="table-responsive"><table id="membership-members" class="table align-middle w-100"><thead><tr><th>BMA ID</th><th>Member</th><th>Payer</th><th>Program</th><th>Member status</th><th>Member since</th><th>Subscription</th><th>Monthly equivalent</th><th>Last payment (UTC)</th></tr></thead><tbody></tbody></table></div>';uiCardEnd();echo '</div>';
?>
<script id="membership-data" type="application/json"><?=json_encode(['events'=>$data['events'],'cancellations'=>$data['cancellations'],'mix'=>$data['mix'],'statuses'=>$data['statuses'],'filters'=>$filters->query()],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR)?></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js" defer></script><script src="https://cdn.datatables.net/v/bs5/dt-3.1.3/datatables.min.js" defer></script><script src="/assets/js/membership.js" defer></script>
<?php uiLayoutEnd(); ?>

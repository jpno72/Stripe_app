<?php
declare(strict_types=1);
require_once __DIR__ . '/admin/_common.php';
require_once APP_ROOT . '/app/Revenue/RevenueRepository.php';
require_once APP_ROOT . '/app/Membership/MembershipMetrics.php';
$config = adminConfig();requireDashboardAccess('revenue');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){http_response_code(405);header('Allow: GET');exit('Read-only report.');}
try {
    $repository = new RevenueRepository(bmaDatabase($config));
    $filters = RevenueFilters::fromRequest($_GET, $repository->latestMonth());
    $options = $repository->options();
    $totals = $repository->totalsByCurrency($filters);
    $trend = $repository->monthlyTrend($filters);
    $programs = $repository->programBreakdown($filters);
    $displayCurrency = count($totals) === 1 ? (string)$totals[0]['currency'] : null;
    $mrr = $repository->currentMrr($displayCurrency ?? ($filters->currency !== '' ? $filters->currency : null));
    if ($filters->program !== '' || $filters->status !== '' || $filters->person !== '') {
        $mrr = ['amount_minor'=>null,'currency'=>null,'reason'=>'Not scoped to program, transaction status or payer filters'];
    }
    $error = null;
} catch (InvalidArgumentException $exception) {
    http_response_code(400);
    $error = $exception->getMessage();
} catch (Throwable) {Security::log($config,'REPORT_UNAVAILABLE');
    http_response_code(503);
    $error = 'Revenue data is unavailable. Check the reporting views and database connection.';
}
function revenueValue(mixed $value, ?string $currency): string {
    return MembershipMetrics::rate($value,$currency);
}
function revenueJson(mixed $value): string {
    return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
}
uiLayoutStart('Revenue', 'revenue', 'Paid Stripe charges, recorded refunds and fees, and the current recurring-rate snapshot.', [
    'https://cdn.datatables.net/v/bs5/dt-3.1.3/datatables.min.css', '/assets/css/revenue.css'
]);
if ($error !== null) {
    uiAlert($error);
    uiEmptyState('Revenue unavailable', 'Correct the filters or check the reporting database.');
    uiLayoutEnd(); exit;
}
$selected = $totals[0] ?? null;
$single = count($totals) === 1;
$gross = $single ? $selected['gross_paid_minor'] : null;
$refunds = $single ? $selected['refunds_minor'] : null;
$fees = $single ? $selected['stripe_fees_minor'] : null;
$net = $single ? $selected['net_after_recorded_costs_minor'] : null;
$period = $filters->label();
?>
<form class="filter-bar revenue-filter mb-4" method="get" action="/revenue" aria-label="Revenue filters">
  <div class="row g-3 align-items-end">
    <div class="col-6 col-md-3 col-xl-2"><label class="form-label" for="rev-from">From (UTC)</label><input class="form-control" type="date" id="rev-from" name="from" value="<?=uiEscape($filters->from)?>"></div>
    <div class="col-6 col-md-3 col-xl-2"><label class="form-label" for="rev-to">To (UTC)</label><input class="form-control" type="date" id="rev-to" name="to" value="<?=uiEscape($filters->to)?>"></div>
    <div class="col-6 col-md-3 col-xl-2"><label class="form-label" for="rev-currency">Currency</label><select class="form-select" id="rev-currency" name="currency"><option value="">All currencies</option><?php foreach ($options['currencies'] as $option): ?><option value="<?=uiEscape($option)?>" <?=$filters->currency === $option ? 'selected' : ''?>><?=uiEscape(strtoupper((string)$option))?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-3 col-xl-2"><label class="form-label" for="rev-program">Program / product</label><select class="form-select" id="rev-program" name="program"><option value="">All programs</option><?php foreach ($options['programs'] as $option): ?><option value="<?=uiEscape($option)?>" <?=$filters->program === $option ? 'selected' : ''?>><?=uiEscape($option)?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-3 col-xl-2"><label class="form-label" for="rev-status">Transaction status</label><select class="form-select" id="rev-status" name="status"><option value="">All statuses</option><?php foreach ($options['statuses'] as $option): ?><option value="<?=uiEscape($option)?>" <?=$filters->status === $option ? 'selected' : ''?>><?=uiEscape($option)?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-3 col-xl-2"><label class="form-label" for="rev-person">Payer / member</label><input class="form-control" type="search" id="rev-person" name="person" value="<?=uiEscape($filters->person)?>" placeholder="Name or BMA ID"></div>
    <div class="col-12 d-flex gap-2 align-items-center"><button class="btn btn-primary" type="submit">Apply filters</button><a class="btn btn-outline-secondary" href="/revenue">Reset</a><a class="btn btn-link" href="/revenue?range=all">All dates</a><span class="text-secondary small ms-auto"><?=uiEscape($period)?></span></div>
  </div>
</form>
<?php
if (count($totals) > 1) { uiAlert('Multiple currencies are present. Select one currency to see comparable monetary totals and charts.', 'warning'); }
if (count($totals) === 0) { uiAlert('No paid, dated charges match these filters. The transaction table may still contain unpaid records.', 'info'); }
echo '<div class="row g-3 mb-4">';
$cards = [
 ['Gross paid volume', revenueValue($gross,$displayCurrency), $period . ' - paid charges', '+'],
 ['Refunds recorded', revenueValue($refunds,$displayCurrency), $single && $refunds === null ? 'Refund data incomplete' : $period, 'R'],
 ['Stripe fees recorded', revenueValue($fees,$displayCurrency), $single && $fees === null ? 'Fee data incomplete or currency differs' : $period, 'F'],
 ['Net after recorded costs', revenueValue($net,$displayCurrency), $single && $net === null ? 'Requires complete refund and same-currency fee data' : $period . ' - excludes other expenses', 'N'],
 ['Active subscription MRR', revenueValue($mrr['amount_minor'],$mrr['currency']), 'Current snapshot - ' . $mrr['reason'], 'M'],
 ['Per active member', 'Unavailable', 'Payment allocation to members is unresolved', 'A'],
];
foreach ($cards as [$label,$value,$detail,$icon]) { echo '<div class="col-12 col-sm-6 col-xl-4">'; uiKpiCard($label,$value,$detail,$icon); echo '</div>'; }
echo '</div>';
?>
<div class="row g-3 mb-4">
  <div class="col-12 col-xl-8"><?php uiChartCardStart('Payment trend', 'Monthly gross and net after recorded costs - UTC - integer minor units'); ?><div class="revenue-chart-wrap"><canvas id="revenue-trend" aria-label="Monthly payment trend" role="img"></canvas></div><p id="revenue-trend-empty" class="text-secondary small mb-0" hidden>No comparable monthly values for these filters.</p><?php uiChartCardEnd(); ?></div>
  <div class="col-12 col-xl-4"><?php uiChartCardStart('Program composition', 'Top 12 directly linked, single-item products; unassigned charges excluded'); ?><div class="revenue-chart-wrap"><canvas id="revenue-programs" aria-label="Gross payments by program" role="img"></canvas></div><p id="revenue-programs-empty" class="text-secondary small mb-0" hidden>No directly attributed program charges for these filters.</p><?php uiChartCardEnd(); ?></div>
</div>
<div class="row g-3 mb-4"><div class="col-12"><?php uiChartCardStart('Stripe fee trend', 'Monthly fees where all included charges have a known same-currency fee'); ?><div class="revenue-chart-wrap revenue-chart-short"><canvas id="revenue-fees" aria-label="Monthly Stripe fee trend" role="img"></canvas></div><p id="revenue-fees-empty" class="text-secondary small mb-0" hidden>Fee amounts are unavailable for the selected period.</p><?php uiChartCardEnd(); ?></div></div>
<?php
uiCardStart('Recent transactions', 'One row per Stripe charge. Amounts are integer minor units; unknown components show Unavailable.', 'table-card');
echo '<div class="table-responsive"><table id="revenue-transactions" class="table align-middle w-100"><thead><tr><th>Date (UTC)</th><th>Member / payer</th><th>Program</th><th>Gross</th><th>Refund</th><th>Fee</th><th>Net</th><th>Status</th><th>Stripe charge ID</th></tr></thead><tbody></tbody></table></div>';
uiCardEnd();
?>
<script id="revenue-data" type="application/json"><?=revenueJson(['currency'=>$displayCurrency,'trend'=>$trend,'programs'=>$programs,'filters'=>$filters->query()])?></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js" defer></script>
<script src="https://cdn.datatables.net/v/bs5/dt-3.1.3/datatables.min.js" defer></script>
<script src="/assets/js/revenue.js" defer></script>
<?php uiLayoutEnd(); ?>

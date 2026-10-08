<?php
declare(strict_types=1);
require_once __DIR__ . '/admin/_common.php';
require_once APP_ROOT . '/app/Views/layout.php';

$config = adminConfig();
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){http_response_code(405);header('Allow: GET');exit('Read-only report.');}
$navigation = uiNavigation();
$page = (string) ($_GET['page'] ?? 'dashboard');
requireDashboardAccess($page==='dashboard'?'overview':($page==='sync'?'settings':$page));
if (in_array($page,['settings','users','sync'],true)) {if(($GLOBALS['bma_current_user']['role']??'')!=='admin'){http_response_code(403);exit('Administrator access is required.');}header('Location: /admin/'.($page==='users'?'users':'settings').'.php',true,303);exit;}
if ($page === 'demographics') { header('Location: /demographics', true, 302); exit; }
if ($page === 'membership') { header('Location: /membership', true, 302); exit; }
if ($page === 'revenue') { header('Location: /revenue', true, 302); exit; }
if (!isset($navigation[$page]) || in_array($page, ['imports', 'matches'], true)) {
    http_response_code(404);
    $page = 'dashboard';
}

$descriptions = [
    'dashboard' => 'A clear view of the academy, based on records you have actually imported.',
    'revenue' => 'Explore payment and revenue trends as reporting pages are added.',
    'membership' => 'Understand active membership and subscriptions in one place.',
    'demographics' => 'Explore member ages and locations when verified details are available.',
    'members' => 'Find and review the people who train at BMA.',
    'transactions' => 'Review imported Stripe payment activity.',
    'waivers' => 'Review approved Cognito waiver records.',
    'reports' => 'Save and revisit useful academy reports.',
    'settings' => 'Manage application preferences and reporting configuration.',
];
$title = $navigation[$page][0];
uiLayoutStart($title, $page, $descriptions[$page]);

if ($page !== 'dashboard') {
    echo '<p class="placeholder-note mb-3">This section is part of the dashboard shell. Its report tools will be added in a later stage.</p>';
    uiFilterBar();
    uiCardStart($title . ' workspace', 'The navigation and filter layout are ready.');
    uiEmptyState('Nothing to show here yet', 'This section is ready for its reporting workflow. No sample business values are displayed.');
    uiCardEnd();
    uiLayoutEnd();
    exit;
}

try {
    $db = bmaDatabase($config);
    $hasRecords = (int) $db->query('SELECT (SELECT COUNT(*) FROM members) + (SELECT COUNT(*) FROM stripe_subscriptions) + (SELECT COUNT(*) FROM stripe_transactions)')->fetchColumn() > 0;
    $activeMembers = (int) $db->query('SELECT COUNT(*) FROM vw_active_members')->fetchColumn();
    $activeSubscriptions = (int) $db->query('SELECT COUNT(*) FROM vw_subscription_summary WHERE is_active_subscription = 1')->fetchColumn();
    $paidCharges = (int) $db->query('SELECT COUNT(*) FROM vw_transaction_summary WHERE is_paid = 1')->fetchColumn();
    $latestMonth = $db->query('SELECT MAX(month_start_utc) FROM vw_monthly_revenue')->fetchColumn();
    $monthly = [];
    if ($latestMonth !== false && $latestMonth !== null) {
        $statement = $db->prepare('SELECT currency, gross_paid_minor FROM vw_monthly_revenue WHERE month_start_utc = ? ORDER BY currency');
        $statement->execute([$latestMonth]);
        $monthly = $statement->fetchAll();
    }
    $recentImports = $db->query('SELECT source_type, started_at, row_count, status FROM vw_import_health ORDER BY import_batch_id DESC LIMIT 4')->fetchAll();
} catch (Throwable) {Security::log($config,'REPORT_UNAVAILABLE');
    uiAlert('The overview could not load. Check the private database configuration and reporting views.');
    uiEmptyState('Overview unavailable', 'The application could not read the reporting database right now.');
    uiLayoutEnd();
    exit;
}

echo '<section class="hero-panel mb-4"><div class="hero-eyebrow mb-2">ACADEMY OVERVIEW</div><h2 class="mb-2">Your academy, in focus.</h2><p class="mb-3">Track the records behind BMA operations. Unknown values stay unknown until reliable source data is available.</p></section>';
if(canViewDashboard('reports')){echo '<a class="hero-link d-inline-block mb-4" href="/index.php?page=reports">Explore reports <span aria-hidden="true">→</span></a>';}

if (!$hasRecords) {
    uiCardStart('Getting started', 'The overview will use imported records when they are available.');
    if(canViewDashboard('imports')){uiEmptyState('No academy records yet','Import the approved CSV files to populate the overview.','/admin/imports.php','Go to imports');}else{uiEmptyState('No academy records yet','Ask an administrator to import the approved academy data.');}
    uiCardEnd();
    uiLayoutEnd();
    exit;
}

$monthlyValue = '—';
$monthlyDetail = 'No dated paid charges yet';
if (count($monthly) === 1) {
    $monthlyValue = uiFormatMinorUnits($monthly[0]['gross_paid_minor']);
    $monthlyDetail = strtoupper((string) $monthly[0]['currency']) . ' minor units · ' . gmdate('M Y', strtotime((string) $latestMonth . ' UTC'));
} elseif (count($monthly) > 1) {
    $monthlyDetail = 'Multiple currencies · view revenue separately';
}

echo '<div class="row g-3 mb-4">';
echo '<div class="col-12 col-sm-6 col-xl-3">'; uiKpiCard('Members marked active', number_format($activeMembers), 'Explicit active member status', '◈'); echo '</div>';
echo '<div class="col-12 col-sm-6 col-xl-3">'; uiKpiCard('Active subscriptions', number_format($activeSubscriptions), 'Stripe subscription status', '↻'); echo '</div>';
echo '<div class="col-12 col-sm-6 col-xl-3">'; uiKpiCard('Paid charges', number_format($paidCharges), 'Eligible paid transactions', '✓'); echo '</div>';
echo '<div class="col-12 col-sm-6 col-xl-3">'; uiKpiCard('Gross payments · latest month', $monthlyValue, $monthlyDetail, '↗'); echo '</div>';
echo '</div>';

echo '<div class="row g-3 mb-4"><div class="col-12 col-xl-8">';
uiChartCard('Payment trend', 'Trend visualization is being prepared');
echo '</div><div class="col-12 col-xl-4">';
uiCardStart('Workspace shortcuts', 'Continue with the records behind the overview.', 'h-100');
if(canViewDashboard('imports')){
echo '<a class="quick-link" href="/admin/imports.php"><span>Review imports</span><span aria-hidden="true">↗</span></a>';
}
if(canViewDashboard('matches')){echo '<a class="quick-link" href="/admin/matches.php"><span>Resolve identity matches</span><span aria-hidden="true">↗</span></a>';
}
if(canViewDashboard('reports')){echo '<a class="quick-link" href="/index.php?page=reports"><span>Open reports workspace</span><span aria-hidden="true">↗</span></a>';}
uiCardEnd(); echo '</div></div>';

uiTableCardStart('Recent imports', 'Latest processing attempts; counts are source rows.', ['Source', 'Started (UTC)', 'Rows', 'Status'], 'recent-imports');
if ($recentImports === []) {
    echo '<tr><td colspan="4" class="text-center text-secondary py-4">No import batches yet.</td></tr>';
} else {
    foreach ($recentImports as $batch) {
        $status = (string) $batch['status'];
        $tone = match ($status) { 'completed' => 'success', 'completed_with_errors' => 'warning', 'failed' => 'danger', default => 'neutral' };
        echo '<tr><td class="fw-semibold">' . uiEscape(str_replace('_', ' ', (string) $batch['source_type'])) . '</td>';
        $started = strtotime((string) $batch['started_at'] . ' UTC');
        echo '<td>' . uiEscape($started === false ? $batch['started_at'] : gmdate('M j, Y · H:i', $started)) . '</td><td>' . uiEscape($batch['row_count']) . '</td><td>';
        uiStatusBadge(str_replace('_', ' ', $status), $tone);
        echo '</td></tr>';
    }
}
uiTableCardEnd();
uiLayoutEnd();

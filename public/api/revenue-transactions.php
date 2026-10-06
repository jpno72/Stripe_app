<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/admin/_common.php';
require_once dirname(__DIR__, 2) . '/app/Revenue/RevenueRepository.php';
$config = adminConfig();requireDashboardAccess('revenue');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){http_response_code(405);header('Allow: GET');exit('Read-only report.');}
header('Content-Type: application/json; charset=utf-8');
function revenueCell(mixed $value, ?string $currency): string {
    return $value === null || $currency === null ? '—' : uiFormatMinorUnits($value) . ' ' . strtoupper($currency);
}
try {
    $repository = new RevenueRepository(bmaDatabase($config));
    $filters = RevenueFilters::fromRequest($_GET, $repository->latestMonth());
    $search = (string)($_GET['search']['value'] ?? '');
    $order = (int)($_GET['order'][0]['column'] ?? 0);
    $direction = (string)($_GET['order'][0]['dir'] ?? 'desc');
    $page = $repository->transactionPage($filters, $search, (int)($_GET['start'] ?? 0), (int)($_GET['length'] ?? 25), $order, $direction);
    $data = [];
    foreach ($page['rows'] as $row) {
        $person = $row['member_bma'] !== null
            ? '<a href="/members/' . rawurlencode((string)$row['member_bma']) . '">' . uiEscape($row['member_name'] ?: $row['member_bma']) . '</a><span class="d-block small text-secondary">Member · ' . uiEscape($row['member_bma']) . '</span>'
            : '';
        if ($row['payer_bma'] !== null) {
            $person .= '<span class="d-block small text-secondary">Payer · ' . uiEscape($row['payer_name'] ?: $row['payer_bma']) . ' (' . uiEscape($row['payer_bma']) . ')</span>';
        }
        if ($person === '') { $person = 'Unlinked'; }
        $currency = $row['currency'] === null ? null : (string)$row['currency'];
        $date = $row['stripe_created_at'] === null ? 'Unknown' : gmdate('M j, Y H:i', strtotime((string)$row['stripe_created_at'] . ' UTC'));
        $data[] = [
            uiEscape($date), $person, uiEscape($row['program_name'] ?? 'Unassigned'),
            uiEscape(revenueCell((int)$row['is_paid'] === 1 ? $row['gross_paid_minor'] : null, $currency)),
            uiEscape(revenueCell((int)$row['is_paid'] === 1 ? $row['refunds_minor'] : null, $currency)),
            uiEscape(revenueCell((int)$row['is_paid'] === 1 ? $row['stripe_fees_minor'] : null, $currency)),
            uiEscape(revenueCell((int)$row['is_paid'] === 1 ? $row['net_after_recorded_costs_minor'] : null, $currency)),
            uiEscape($row['status'] ?? 'Unknown'),
            uiEscape($row['stripe_charge_id'] ?? 'Unknown'),
        ];
    }
    echo json_encode(['draw'=>(int)($_GET['draw'] ?? 0),'recordsTotal'=>$page['total'],'recordsFiltered'=>$page['filtered'],'data'=>$data], JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $exception) {
    http_response_code(400);
    echo json_encode(['error'=>$exception->getMessage()]);
} catch (Throwable) {Security::log($config,'REPORT_UNAVAILABLE');
    http_response_code(503);
    echo '{"error":"Transaction data is unavailable."}';
}

<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/admin/_common.php';
require_once APP_ROOT . '/app/Membership/MembershipRepository.php';
require_once APP_ROOT . '/app/Membership/MembershipMetrics.php';
$config=adminConfig();requireDashboardAccess('membership');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){http_response_code(405);header('Allow: GET');exit('Read-only report.');}header('Content-Type: application/json; charset=utf-8');
try {
    $repo=new MembershipRepository(bmaDatabase($config));$filters=MembershipFilters::fromRequest($_GET);
    $page=$repo->memberPage($filters,(string)($_GET['search']['value']??''),(int)($_GET['start']??0),(int)($_GET['length']??25),(int)($_GET['order'][0]['column']??1),(string)($_GET['order'][0]['dir']??'asc'));
    $rows=[];
    foreach ($page['rows'] as $row) {
        $link='/members/'.rawurlencode((string)$row['bma_person_id']);
        $payer=$row['payer_name']??((int)$row['payer_count']>1?'Multiple payers':'Unlinked');
        $program=$row['membership_product']??'Unassigned / multiple items';
        $subscription=$row['stripe_subscription_id']??'Unlinked / multiple subscriptions';
        $sub=uiEscape($subscription).($row['subscription_status']!==null?'<span class="d-block small text-secondary">'.uiEscape($row['subscription_status']).'</span>':'');
        $rate=MembershipMetrics::rate($row['monthly_equivalent_minor'],$row['rate_currency']);
        $rate=uiEscape($rate).'<span class="d-block small text-secondary">'.uiEscape(str_replace('_',' ',$row['subscription_allocation_status'])).'</span>';
        $last=$row['last_payment_at']??'Unavailable';
        $last=uiEscape($last).'<span class="d-block small text-secondary">'.uiEscape(str_replace('_',' ',$row['financial_attribution_status'])).'</span>';
        $rows[]=[uiEscape($row['bma_person_id']),'<a href="'.uiEscape($link).'">'.uiEscape($row['member_name']?:$row['bma_person_id']).'</a>',uiEscape($payer),uiEscape($program),uiEscape($row['member_status']??'Unknown'),uiEscape($row['member_since']??'Unknown'),$sub,$rate,$last];
    }
    echo json_encode(['draw'=>(int)($_GET['draw']??0),'recordsTotal'=>$page['total'],'recordsFiltered'=>$page['filtered'],'data'=>$rows],JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $exception) {http_response_code(400);echo json_encode(['error'=>$exception->getMessage()]);}
catch (Throwable) {Security::log($config,'REPORT_UNAVAILABLE');http_response_code(503);echo '{"error":"Member data is unavailable."}';}

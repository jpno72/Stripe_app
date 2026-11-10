<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/admin/_common.php';
require_once APP_ROOT.'/app/Members/MemberProfileRepository.php';
$config=adminConfig();requireDashboardAccess('members');header('Cache-Control: private, no-store');header('Content-Type: application/json; charset=utf-8');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){http_response_code(405);header('Allow: GET');exit('{"error":"Read-only endpoint."}');}
try{
    $input=$_GET;$input['search']=$input['q']??($input['search']['value']??'');
    $page=(new MemberProfileRepository(bmaDatabase($config)))->peoplePage($input,(int)($_GET['start']??0),(int)($_GET['length']??25),(int)($_GET['order'][0]['column']??2),(string)($_GET['order'][0]['dir']??'asc'));
    $rows=[];foreach($page['rows'] as $p){$link='<a href="/member.php?person='.(int)$p['id'].'">'.e($p['bma_person_id']).'</a>';$rows[]=[$link,e($p['first_name']),e($p['last_name']),e($p['age_years']??'Unknown'),e($p['member_id']===null?'No member record':$p['member_status']),e($p['member_since']??'Unknown'),e($p['program']??'Unassigned'),e($p['customer_ids']??'None'),e($p['subscription_statuses']??'None'),e(AppTime::display($p['last_payment'],'Unavailable'))];}
    echo json_encode(['draw'=>(int)($_GET['draw']??0),'recordsTotal'=>$page['total'],'recordsFiltered'=>$page['filtered'],'data'=>$rows],JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
}catch(InvalidArgumentException){http_response_code(400);echo '{"error":"Invalid directory filters."}';}
catch(Throwable){Security::log($config,'REPORT_UNAVAILABLE');http_response_code(503);echo '{"error":"People directory unavailable."}';}

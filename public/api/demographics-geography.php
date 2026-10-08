<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/admin/_common.php';
require_once APP_ROOT . '/app/Demographics/DemographicRepository.php';
$config=adminConfig();requireDashboardAccess('demographics');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){http_response_code(405);header('Allow: GET');exit('Read-only report.');}header('Content-Type: application/json; charset=utf-8');
try {
    $brackets=new AgeBrackets();$f=DemographicFilters::fromRequest($_GET,$brackets);$repo=new DemographicRepository(bmaDatabase($config),$brackets);
    $page=$repo->geographyPage($f,(string)($_GET['search']['value']??''),(int)($_GET['start']??0),(int)($_GET['length']??25),(int)($_GET['order'][0]['column']??2),(string)($_GET['order'][0]['dir']??'desc'));
    $data=[];foreach($page['rows'] as $row){$amount=$row['gross_paid_minor']===null||$row['currency']===null?'Unavailable':uiFormatMinorUnits($row['gross_paid_minor']).' '.strtoupper($row['currency']);$data[]=[uiEscape($row['city']??'Unknown city'),uiEscape($row['state']??'Unknown'),uiEscape($row['member_count']),$row['percent']===null?'Unavailable':uiEscape(number_format($row['percent'],1).'%'),uiEscape($amount)];}
    echo json_encode(['draw'=>(int)($_GET['draw']??0),'recordsTotal'=>$page['total'],'recordsFiltered'=>$page['filtered'],'data'=>$data],JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
} catch(InvalidArgumentException $exception){http_response_code(400);echo json_encode(['error'=>$exception->getMessage()]);}
catch(Throwable){Security::log($config,'REPORT_UNAVAILABLE');http_response_code(503);echo '{"error":"Geographic reporting is unavailable."}';}

<?php
declare(strict_types=1);
require_once __DIR__.'/admin/_common.php';
require_once APP_ROOT . '/app/Demographics/DemographicRepository.php';
require_once APP_ROOT . '/app/Demographics/DemographicMetrics.php';
$config=adminConfig();requireDashboardAccess('demographics');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){http_response_code(405);header('Allow: GET');exit('Read-only report.');}
try {
    $brackets=new AgeBrackets();$filters=DemographicFilters::fromRequest($_GET,$brackets);
    $repo=new DemographicRepository(bmaDatabase($config),$brackets);$options=$repo->options();$data=$repo->overview($filters);$cards=DemographicMetrics::cards($data['stats']);$error=null;
} catch(InvalidArgumentException $exception){http_response_code(400);$error=$exception->getMessage();}
catch(Throwable){Security::log($config,'REPORT_UNAVAILABLE');http_response_code(503);$error='Demographics are unavailable. Check the reporting database and age-bracket configuration.';}
uiLayoutStart('Demographics','demographics','Member age and geography, with unknown information kept visible.', ['https://cdn.datatables.net/v/bs5/dt-3.1.3/datatables.min.css','/assets/css/demographics.css']);
if($error!==null){uiAlert($error);uiEmptyState('Demographics unavailable','Correct the filters or check the reporting configuration.');uiLayoutEnd();exit;}
?>
<form class="filter-bar demographics-filter mb-4" method="get" action="/demographics" aria-label="Demographic filters"><div class="row g-3 align-items-end">
<div class="col-6 col-md-3"><label class="form-label" for="demo-status">Member activity</label><select class="form-select" id="demo-status" name="status"><?php foreach([''=>'All members','active'=>'Active','inactive'=>'Inactive / non-active','unknown'=>'Unknown status'] as $value=>$label): ?><option value="<?=uiEscape($value)?>" <?=$filters->status===$value?'selected':''?>><?=uiEscape($label)?></option><?php endforeach; ?></select></div>
<div class="col-6 col-md-3"><label class="form-label" for="demo-program">Program / product</label><select class="form-select" id="demo-program" name="program"><option value="">All programs</option><?php foreach($options['programs'] as $value): ?><option value="<?=uiEscape($value)?>" <?=$filters->program===$value?'selected':''?>><?=uiEscape($value)?></option><?php endforeach; ?></select></div>
<div class="col-6 col-md-3"><label class="form-label" for="demo-age">Age bracket</label><select class="form-select" id="demo-age" name="age"><option value="">All ages</option><?php foreach(array_merge($brackets->definitions,[['key'=>'unknown','label'=>'Unknown age']]) as $row): ?><option value="<?=uiEscape($row['key'])?>" <?=$filters->age===$row['key']?'selected':''?>><?=uiEscape($row['label'])?></option><?php endforeach; ?></select></div>
<?php foreach([['city','City',$options['cities'],$filters->city],['zip','ZIP / postal code',$options['zips'],$filters->zip]] as [$key,$label,$choices,$selected]): ?><div class="col-6 col-md-3"><label class="form-label" for="demo-<?=uiEscape($key)?>"><?=uiEscape($label)?></label><select class="form-select" id="demo-<?=uiEscape($key)?>" name="<?=uiEscape($key)?>"><option value="">All</option><?php foreach($choices as $value): ?><option value="<?=uiEscape($value)?>" <?=$selected===$value?'selected':''?>><?=uiEscape($value)?></option><?php endforeach; ?></select></div><?php endforeach; ?>
<div class="col-6 col-md-3"><label class="form-label" for="demo-from">Member cohort from</label><input class="form-control" id="demo-from" type="date" name="from" value="<?=uiEscape($filters->from)?>"></div>
<div class="col-6 col-md-3"><label class="form-label" for="demo-to">Member cohort through</label><input class="form-control" id="demo-to" type="date" name="to" value="<?=uiEscape($filters->to)?>"></div>
<div class="col-12 col-md-3 d-flex gap-2"><button class="btn btn-primary" type="submit">Apply filters</button><a class="btn btn-outline-secondary" href="/demographics">Reset</a></div></div></form>
<?php
uiAlert($data['stats']['total_members'].' members in this scope, as of '.$data['as_of'].' '.AppTime::name().'. Ages use known member demographics only. Cohort dates refer to factual membership starts; applying a cohort filter excludes unknown starts.','info');
if((int)$data['stats']['total_members']===0){uiEmptyState('No members match these filters','Broaden the filters or review the available member records.');}
echo '<div class="row g-3 mb-4">';foreach($cards as [$label,$value,$detail]){echo '<div class="col-12 col-sm-6 col-xl-4">';uiKpiCard($label,$value,$detail);echo '</div>';}echo '</div>';
$charts=[['age','Age distribution','Configured age brackets, including unknown ages.'],['youth','Youth vs adult','Youth is under 18; unknown ages are a separate category.'],['cities','City distribution','Top 10 city/state groups plus other locations; unknown cities included.'],['zips','ZIP / postal distribution','Top 10 postal codes plus other locations; codes retain leading zeros.']];
echo '<div class="row g-3 mb-4">';foreach($charts as [$id,$title,$subtitle]){echo '<div class="col-12 col-xl-6">';uiChartCardStart($title,$subtitle);echo '<div class="demographic-chart"><canvas id="demographic-'.$id.'" role="img" aria-label="'.uiEscape($title).'"></canvas></div><p id="demographic-'.$id.'-empty" class="demographic-chart-empty text-secondary" hidden>No known '.($id==='age'||$id==='youth'?'age':'location').' data for these filters.</p>';uiChartCardEnd();echo '</div>';}echo '</div>';
uiCardStart('Geographic membership','Member counts by city/state. Percentages use every member in the selected scope, including unknown locations. Attributed gross payments are all-date integer currency minor units; incomplete or shared attribution stays unavailable.','table-card');
echo '<div class="table-responsive"><table id="demographic-geography" class="table align-middle w-100"><thead><tr><th>City</th><th>State</th><th>Members</th><th>Percent of members</th><th>Attributed gross paid (all dates)</th></tr></thead><tbody></tbody></table></div>';uiCardEnd();
echo '<div class="mt-4">';uiCardStart('Training goals / interests','Availability follows the approved field-retention contract.');uiEmptyState('Goals data unavailable','The current import mapping excludes goals free text. No interests are inferred from waiver or medical information.');uiCardEnd();echo '</div>';
?>
<script id="demographics-data" type="application/json"><?=json_encode(['age'=>$data['age'],'youth'=>$data['youth'],'cities'=>$data['cities'],'zips'=>$data['zips'],'available'=>['age'=>(int)$data['stats']['known_ages']>0,'youth'=>(int)$data['stats']['known_ages']>0,'cities'=>(int)$data['stats']['known_cities']>0,'zips'=>(int)$data['stats']['known_zips']>0],'filters'=>$filters->query()],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR)?></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js" defer></script><script src="https://cdn.datatables.net/v/bs5/dt-3.1.3/datatables.min.js" defer></script><script src="/assets/js/demographics.js" defer></script>
<?php uiLayoutEnd(); ?>

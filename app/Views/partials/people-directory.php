<?php
declare(strict_types=1);
require_once APP_ROOT.'/app/Membership/MembershipRepository.php';
try{$input=$_GET;$input['search']=$input['q']??'';$filters=PeopleDirectoryFilters::fromRequest($input);$options=(new MembershipRepository(bmaDatabase($config)))->options();$directoryError=null;}
catch(InvalidArgumentException){http_response_code(400);$directoryError='Invalid directory filters.';}
catch(Throwable){http_response_code(503);$directoryError='People directory unavailable.';}
uiLayoutStart('People & members','members','Browse people separately from actual membership records. Ages are calculated; household context does not establish enrollment.',['/assets/vendor/datatables/3.1.3/datatables.min.css']);
if($directoryError!==null){uiAlert($directoryError);uiLayoutEnd();return;}
?>
<form class="filter-bar mb-4" method="get" action="/member.php"><div class="row g-3 align-items-end">
<?php $select=static function(string $key,string $label,array $choices)use($filters):void{echo '<div class="col-12 col-sm-6 col-xl-3"><label class="form-label" for="directory-'.e($key).'">'.e($label).'</label><select class="form-select" id="directory-'.e($key).'" name="'.e($key).'">';foreach($choices as $value=>$text)echo '<option value="'.e($value).'" '.($filters[$key]===(string)$value?'selected':'').'>'.e($text).'</option>';echo '</select></div>';};
$select('scope','Record scope',['people'=>'All People','members'=>'Members Only']);
$select('member_status','Member status',[''=>'All']+array_combine($options['member_statuses'],$options['member_statuses']));
$select('program','Program (explicit assignments)',[''=>'All']+array_combine($options['programs'],$options['programs']));
$select('subscription_status','Subscription status',[''=>'All']+array_combine($options['subscription_statuses'],$options['subscription_statuses']));
foreach(['customer'=>'Stripe customer','subscription'=>'Subscription','payments'=>'Associated payments'] as $key=>$label)$select($key,$label,[''=>'All','yes'=>'Has '.$label,'no'=>'No '.$label]); ?>
<div class="col-12 col-sm-6 col-xl-3"><label for="person-search" class="form-label">Name / BMA ID / email / phone</label><input class="form-control" id="person-search" name="q" maxlength="100" value="<?=e($filters['search'])?>"></div>
<?php foreach(['age_min'=>'Minimum age','age_max'=>'Maximum age'] as $key=>$label): ?><div class="col-6 col-xl-2"><label class="form-label" for="directory-<?=e($key)?>"><?=e($label)?></label><input class="form-control" id="directory-<?=e($key)?>" name="<?=e($key)?>" type="number" min="0" max="130" value="<?=e($filters[$key])?>"></div><?php endforeach; ?>
<div class="col-12 col-xl-4 d-flex gap-2"><button class="btn btn-primary">Apply filters</button><a class="btn btn-outline-secondary" href="/member.php">Reset Filters</a></div></div></form>
<?php uiCardStart('Existing BMA records','One row per person. Stripe/payment columns show verified account context; a payer may cover multiple students. They are not per-student revenue.','table-card'); ?>
<div class="table-responsive"><table class="table w-100" id="people-directory"><thead><tr><?php foreach(['BMA person ID','First name','Last name','Age','Membership status','Member since','Program','Stripe customer','Subscription status','Last recorded payment'] as $label)echo '<th>'.e($label).'</th>'; ?></tr></thead><tbody></tbody></table></div>
<p id="directory-error" class="alert alert-danger" hidden>Directory could not load. Reload the page or check access.</p><noscript>Enable JavaScript to page, sort and filter the directory.</noscript>
<?php uiCardEnd(); ?>
<script id="directory-data" type="application/json"><?=json_encode($filters,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR)?></script>
<script src="/assets/vendor/datatables/3.1.3/datatables.min.js" defer></script><script src="/assets/js/people-directory.js" defer></script>
<?php uiLayoutEnd(); ?>

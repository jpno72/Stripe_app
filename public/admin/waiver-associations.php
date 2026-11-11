<?php
declare(strict_types=1);
require_once __DIR__.'/_common.php';
require_once APP_ROOT.'/app/Members/WaiverAssociationService.php';
$config=adminConfig('admin');requireDashboardAccess('members');header('Cache-Control: private, no-store');
$id=$_POST['person']??$_GET['person']??'';
if(!is_string($id)||!ctype_digit($id)||(int)$id<1){http_response_code(400);exit('Invalid person identifier.');}
$person=(int)$id;$db=bmaDatabase($config);$actor=adminReviewActor($db,$config);$service=new WaiverAssociationService($db);
$ready=WaiverAssociationService::ready($db);$error=null;$notice=null;$groups=[];$subject=null;
try{
    $subject=$service->subject($actor,$person);
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
        Security::csrf($_POST['csrf']??null);
        $action=$_POST['action']??'';if(!is_string($action))throw new InvalidArgumentException('INVALID_REVIEW');
        if($action==='discover'){$n=$service->discover($actor,$person);$notice='Discovery completed. Candidates are suggestions; no identities or relationships were confirmed.';}
        else{
            foreach(['review','role','digest','note','verified'] as $key)if(!is_string($_POST[$key]??null))throw new InvalidArgumentException('INVALID_REVIEW');
            if(!ctype_digit($_POST['review'])||$_POST['verified']!=='1')throw new InvalidArgumentException('EXPLICIT_REVIEW_REQUIRED');
            $service->decide($actor,$person,(int)$_POST['review'],$action,$_POST['role'],$_POST['digest'],trim($_POST['note']));$notice='Reviewed association decision saved.';
        }
    }
    $groups=$service->groups($actor,$person);
}catch(InvalidArgumentException){http_response_code(400);$error='Invalid review. Select a role, provide a short reason and acknowledge your decision.';}
catch(RuntimeException $e){http_response_code(409);$error='Decision not saved. Existing decisions, a changed source, or an identity conflict requires review. No identity was reassigned.';}
catch(Throwable){http_response_code(503);$error='Waiver association discovery is unavailable.';}
uiLayoutStart('Other Waiver Associations','members','Administrator only · source-backed suggestions do not establish identity, membership or legal parentage.');
?>
<a class="btn btn-outline-secondary mb-3" href="/member.php?person=<?=$person?>">Back to person profile</a>
<?php if($subject): ?><h2 class="h4"><?=e(trim(implode(' ',array_filter([$subject['first_name'],$subject['middle_name'],$subject['last_name']]))))?> · <?=e($subject['bma_person_id'])?></h2><p class="text-secondary">Person's own contact: <?=e(($subject['email']?:'Email unavailable').' / '.($subject['phone']?:'Phone unavailable'))?></p><?php endif; ?>
<?php if($error)uiAlert($error);if($notice)uiAlert($notice,'success'); ?>
<?php if(!$ready): ?>
<?php uiAlert('Migration 013 requires separate administrator approval before discovery storage and reviewed associations can be enabled. No migration has been applied automatically.','warning'); ?>
<?php else: ?>
<form method="post" class="mb-4"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="person" value="<?=$person?>"><button class="btn btn-primary" name="action" value="discover">Find Other Associations</button></form>
<p class="small text-secondary">Search covers all imported waivers. Directory proposals reflect the last requested search, not an automatic scan. Rejected suggestions remain suppressed. Shared household contacts alone are ambiguous. Repeated unverified students are grouped only with consistent name and birth date; otherwise submissions remain separate.</p>
<?php if(!$groups)uiEmptyState('No discovered associations','Select Find Other Associations. Missing names or contact evidence do not prove that someone is absent from a waiver.'); ?>
<?php foreach($groups as $group): ?>
<section class="surface-card card mb-4"><div class="card-body"><h2 class="h5"><?=e($group['student']?:'Student role unresolved')?></h2><p><?=e($group['bma']??'No verified student BMA ID')?> · <?=count($group['waivers'])?> distinct waiver submission(s)</p>
<details open><summary>Waiver history and roles</summary>
<?php foreach($group['waivers'] as $rows):$first=$rows[0]; ?>
<article class="border rounded p-3 my-3"><h3 class="h6">Waiver <?=e($first['source_record_id']??$first['source_locator'])?></h3><p class="small"><?=e($first['submitted_at']??'Date unknown')?><?= $first['source_timezone']?'':' · timezone unresolved' ?> · Relationship stated: <?=e($first['relationship_to_student']?:'Unspecified')?></p>
<?php foreach($rows as $r):$label=$r['accepted']?'Verified Association':($r['classification']==='strong'?'Strong Possible Association':($r['classification']==='ambiguous'?'Conflicting or Ambiguous':'Possible Association')); ?>
<div class="border-top pt-3 mt-3"><h4 class="h6"><?=e(ucfirst($r['source_role']))?> · <?=e($label)?></h4><p>Review status: <?=e($r['review_status'])?></p>
<p class="small">Evidence: <?=e(str_replace('_',' ',implode(' · ',json_decode($r['evidence'],true,512,JSON_THROW_ON_ERROR))))?></p><p class="small">Role source contact (ownership unverified): <?=e($r['source_contact']?:'Unavailable')?></p>
<?php if($r['source_role']==='signer'): ?><p>Signer (source): <?=e($r['signer_print_name'])?></p><?php elseif($r['source_role']==='emergency'): ?><p>Emergency contact (restricted source): <?=e($r['emergency_contact_name'])?></p><?php endif; ?>
<?php foreach($r['verified_relationships'] as $edge): ?><p>Verified <?=e($edge['relationship_type'])?> relationship to this student</p><?php endforeach; ?>
<?php if($r['review_status']==='held'): ?><p>Held for review. Resuming this discovery suggestion does not release any existing identity-matching hold or rejection.</p>
<form method="post" class="row g-2"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="person" value="<?=$person?>"><input type="hidden" name="review" value="<?=(int)$r['id']?>"><input type="hidden" name="digest" value="<?=e($r['current_digest'])?>"><input type="hidden" name="role" value="<?=e($r['source_role'])?>"><div class="col-12"><label>Reason for resuming <input class="form-control" name="note" minlength="3" maxlength="1000" required></label></div><div class="col-12"><label><input type="checkbox" name="verified" value="1" required> I explicitly resume this discovery review. No identity will be confirmed.</label></div><div class="col-12"><button class="btn btn-outline-secondary" name="action" value="resume">Resume Review</button></div></form>
<?php elseif($r['stale']):uiAlert('Source evidence changed since discovery. Rediscover before reviewing.','warning'); ?>
<?php elseif(!$r['accepted']&&$r['review_status']==='possible'): ?>
<form method="post" class="row g-2"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="person" value="<?=$person?>"><input type="hidden" name="review" value="<?=(int)$r['id']?>"><input type="hidden" name="digest" value="<?=e($r['current_digest'])?>">
<div class="col-md-6"><label class="form-label" for="role-<?=(int)$r['id']?>">Explicitly verified role</label><select class="form-select" name="role" id="role-<?=(int)$r['id']?>"><option value="<?=e($r['source_role'])?>"><?=e(ucfirst($r['source_role']))?> identity</option><?php if($r['student_person_id']!==null&&(int)$r['student_person_id']!==$person):foreach(['payer'=>'Payer to student','parent'=>'Verified parent (independent verification required)','guardian'=>'Verified guardian (independent verification required)'] as $value=>$text): ?><option value="<?=e($value)?>"><?=e($text)?></option><?php endforeach;endif; ?></select></div>
<div class="col-md-6"><label class="form-label" for="note-<?=(int)$r['id']?>">Reason (no medical information)</label><input class="form-control" name="note" id="note-<?=(int)$r['id']?>" minlength="3" maxlength="1000" required></div>
<div class="col-12"><label><input type="checkbox" name="verified" value="1" required> I reviewed the source evidence and the selected decision. Confirmation establishes only this explicit role; it creates no membership and does not allocate payments.</label></div>
<div class="col-12 d-flex flex-wrap gap-2"><button class="btn btn-primary" name="action" value="confirm">Confirm Association</button><button class="btn btn-outline-danger" name="action" value="reject">Not This Person</button><button class="btn btn-outline-secondary" name="action" value="hold">Leave Unresolved</button></div>
</form>
<?php endif; ?></div><?php endforeach; ?></article><?php endforeach; ?></details></div></section><?php endforeach;endif;uiLayoutEnd(); ?>

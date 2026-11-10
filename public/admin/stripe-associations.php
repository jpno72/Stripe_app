<?php
declare(strict_types=1);
require_once __DIR__.'/_common.php';
require_once APP_ROOT.'/app/Members/StripeAssociationService.php';
require_once APP_ROOT.'/app/Members/MemberProfileRepository.php';
$config=adminConfig('admin');requireDashboardAccess('members');header('Cache-Control: private, no-store');
$subject=$_POST['person']??$_GET['person']??'';
if(!is_string($subject)||!ctype_digit($subject)||(int)$subject<1){http_response_code(400);exit('Invalid person identifier.');}$subject=(int)$subject;
$db=bmaDatabase($config);$actor=adminReviewActor($db,$config);$service=new StripeAssociationService($db);$profile=(new MemberProfileRepository($db))->find(new MemberProfileIdentifier(null,null,$subject));
if(!$profile){http_response_code(404);exit('Person not found.');}
$preview=null;$error=null;$values=[];
foreach(['identifier','action','role','target_bma','mapping_id','reason','new_payer','new_first_name','new_last_name','acknowledge'] as $key){$v=$_POST[$key]??$_GET[$key]??'';$values[$key]=is_string($v)?$v:'';}
if($values['action']==='')$values['action']='link';if($values['role']==='')$values['role']='own';if($values['target_bma']==='')$values['target_bma']=$profile['bma_person_id'];
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    Security::csrf($_POST['csrf']??null);
    try{
        if(($_POST['phase']??'')==='confirm'){
            $digest=$_POST['digest']??'';$op=$_POST['operation']??'';if(!is_string($digest)||!is_string($op))throw new InvalidArgumentException('Invalid confirmation.');
            $service->apply($actor,$subject,$values,$digest,$op);$_SESSION['stripe_association_notice']='Reviewed Stripe association saved. Imported Stripe records were preserved.';header('Location: /member.php?person='.$subject.'#manage-stripe',true,303);exit;
        }
        if(($_POST['phase']??'')!=='preview')throw new InvalidArgumentException('Invalid review action.');
        $preview=$service->preview($actor,$subject,$values);
    }catch(PDOException){http_response_code(503);$error='Association review is unavailable. Check current associations before retrying.';Security::log($config,'STRIPE_REVIEW_UNAVAILABLE');}
    catch(InvalidArgumentException $e){http_response_code(400);$error=$e->getMessage();}
    catch(RuntimeException $e){http_response_code(409);$error=$e->getMessage()==='REVIEWED_CANDIDATE_CONFLICT'?'This correction conflicts with a previously reviewed identity match. Resolve that decision in Matches first; nothing was saved.':str_replace('_',' ',$e->getMessage()).'. Review the current associations before retrying.';}
    catch(Throwable){http_response_code(503);$error='Association review unavailable.';Security::log($config,'STRIPE_REVIEW_UNAVAILABLE');}
}
$links=$service->links($actor,$subject);$ready=StripeAssociationRepository::attributionReady($db);
uiLayoutStart('Manage Stripe Associations','members',$profile['member_name'].' · '.$profile['bma_person_id'].' · Imported records are read-only.');
?>
<a href="/member.php?person=<?=$subject?>" class="d-inline-block mb-3">← Back to profile</a>
<?php if($error!==null)uiAlert($error);if(!$ready)uiAlert('Direct payment attribution is unavailable until migration 012 is separately approved and applied. Customer and subscription review remain available.','info'); ?>
<?php uiCardStart('Existing reviewed associations','Removing a payer relationship detaches that payer’s account context from this student only; other students and account ownership are preserved.'); ?>
<div class="table-responsive"><table class="table align-middle"><thead><tr><th>Stripe ID</th><th>Type / role</th><th>Related BMA person</th><th>Source / verification</th><th>Actions</th></tr></thead><tbody>
<?php foreach($links as $link):$url='/admin/stripe-associations.php?'.http_build_query(['person'=>$subject,'identifier'=>$link['identifier'],'role'=>$link['role'],'target_bma'=>$link['bma_person_id'],'mapping_id'=>$link['mapping_id']]); ?><tr><td><code><?=e($link['identifier'])?></code></td><td><?=e($link['kind'].' / '.$link['role'])?></td><td><?=e($link['bma_person_id'])?></td><td><?=e($link['source'])?><span class="d-block"><?=e($link['match_status'])?></span></td><td><?php if($link['match_status']!=='historical'): ?><a class="btn btn-sm btn-outline-primary" href="<?=e($url.'&action=link')?>">Edit / Verify</a> <a class="btn btn-sm btn-outline-danger" href="<?=e($url.'&action=remove')?>">Remove Association</a><?php else: ?>Historical assignment retained<?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php if($links===[])uiEmptyState('No reviewed Stripe associations','Look up an existing imported Stripe ID below.');uiCardEnd(); ?>
<?php if($preview!==null): ?>
<section class="surface-card card my-4 border-primary"><div class="card-body"><h2 class="h5">Confirm reviewed change</h2>
<p><strong><?=e(ucfirst($preview['request']['action']).' '.$preview['kind'].' association')?></strong> · <?=e($preview['request']['identifier'])?></p>
<p>Profile: <?=e($profile['bma_person_id'])?>. Intended person: <?=e(($preview['target']['bma_person_id']??'NEW PERSON — allocated only on confirmation').' · '.$preview['target']['first_name'].' '.$preview['target']['last_name'])?>.</p>
<?php if($preview['kind']==='customer'&&$preview['request']['role']==='payer')uiAlert('Payer and student remain separate people. Only a payer relationship is reviewed; no parentage, guardianship or membership is inferred. Removing it affects all accounts belonging to this payer for this student.','warning'); ?>
<?php if($preview['kind']==='subscription')uiAlert('Only the explicit member assignment changes. Stripe customer ownership and billing stay unchanged. Other current students are preserved unless the displayed profile assignment is being edited. Historical assignments remain in the audit trail.','info'); ?>
<?php if($preview['kind']==='transaction')uiAlert('This reviews one payment allocation. Source identifiers, amounts, customer ownership and status are unchanged. One current allocation is permitted; shared payer context is never per-child revenue.','warning'); ?>
<h3 class="h6">Recorded imported information</h3><dl class="profile-facts">
<?php foreach($preview['record'] as $key=>$value):if(in_array($key,['id','customer_id','person_id','owner_person_id','updated_at'],true))continue; ?><div><dt><?=e(str_replace('_',' ',$key))?></dt><dd><?=e($value??'Unknown')?></dd></div><?php endforeach; ?></dl>
<?php if(isset($preview['details']['existing_owner'])): ?><p>Existing verified owner: <?=e($preview['details']['existing_owner']['bma_person_id'].' · '.$preview['details']['existing_owner']['first_name'].' '.$preview['details']['existing_owner']['last_name'])?></p><?php endif; ?>
<?php if(!empty($preview['details']['items'])): ?><h3 class="h6">Recorded subscription items</h3><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Product / price</th><th>Amount (minor units)</th><th>Currency</th><th>Quantity</th><th>Interval</th></tr></thead><tbody><?php foreach($preview['details']['items'] as $item): ?><tr><td><?=e(($item['product_name']??'Unknown').' · '.($item['stripe_price_id']??'Price unknown'))?></td><td><?=e($item['amount_minor']??'Unknown')?></td><td><?=e($item['currency']??'Unresolved')?></td><td><?=e($item['quantity']??'Unknown')?></td><td><?=e(($item['billing_interval_count']??'?').' '.($item['billing_interval']??'Unknown'))?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<?php if(!empty($preview['details']['assignments'])): ?><h3 class="h6">Existing member assignments</h3><?php foreach($preview['details']['assignments'] as $assignment): ?><p><?=e($assignment['bma_person_id'].' · '.($assignment['valid_from']??'Start unknown').' → '.($assignment['valid_until']??'No recorded end'))?></p><?php endforeach; endif; ?>
<?php if(!empty($preview['details']['allocation_person'])): ?><p>Current payment recipient: <?=e($preview['details']['allocation_person']['bma_person_id'])?> · <?=e($preview['details']['allocation']['attribution_role'])?></p><?php endif; ?>
<?php foreach($preview['conflicts'] as $conflict)uiAlert($conflict,'warning'); ?>
<form method="post" action="/admin/stripe-associations.php"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="person" value="<?=$subject?>"><input type="hidden" name="phase" value="confirm"><input type="hidden" name="digest" value="<?=e($preview['digest'])?>"><input type="hidden" name="operation" value="<?=e(bin2hex(random_bytes(16)))?>">
<?php foreach($preview['request'] as $key=>$value):if($key==='acknowledge')continue; ?><input type="hidden" name="<?=e($key)?>" value="<?=e($value)?>"><?php endforeach; ?>
<p>Reason: <?=e($preview['request']['reason'])?></p>
<?php if($preview['conflicts']!==[]): ?><div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="acknowledge" value="1" id="resolve-conflict" required><label class="form-check-label" for="resolve-conflict">I explicitly verified and approve the displayed conflict resolution / shared assignment.</label></div><?php endif; ?>
<button class="btn <?=$preview['request']['action']==='remove'?'btn-danger':'btn-primary'?>" type="submit">Confirm <?=e($preview['request']['action']==='remove'?'Removal':'Association')?></button> <a class="btn btn-outline-secondary" href="/admin/stripe-associations.php?person=<?=$subject?>">Cancel</a>
</form></div></section>
<?php endif; ?>
<?php uiCardStart('Look up an imported Stripe record','Enter a cus_, sub_, pi_, ch_ or supported py_ ID. No live Stripe API calls are made.'); ?>
<form method="post" action="/admin/stripe-associations.php" class="row g-3"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="person" value="<?=$subject?>"><input type="hidden" name="phase" value="preview"><input type="hidden" name="mapping_id" value="<?=e($values['mapping_id'])?>">
<div class="col-12 col-md-6"><label class="form-label" for="stripe-identifier">Stripe identifier</label><input class="form-control" id="stripe-identifier" name="identifier" maxlength="255" value="<?=e($values['identifier'])?>" required></div>
<div class="col-12 col-md-3"><label for="association-action" class="form-label">Action</label><select class="form-select" id="association-action" name="action"><?php foreach(['link'=>'Add / Edit / Verify','remove'=>'Remove Association'] as $key=>$label): ?><option value="<?=e($key)?>" <?=$values['action']===$key?'selected':''?>><?=e($label)?></option><?php endforeach; ?></select></div>
<div class="col-12 col-md-3"><label class="form-label" for="association-role">Identity / allocation role</label><select class="form-select" name="role" id="association-role"><option value="own" <?=$values['role']==='own'?'selected':''?>>Own account / direct allocation / member assignment</option><option value="payer" <?=$values['role']==='payer'?'selected':''?>>Payer account for this student</option></select></div>
<div class="col-12 col-md-6"><label class="form-label" for="association-person">Existing BMA person (payer, allocation recipient or member)</label><input class="form-control" id="association-person" list="association-person-options" name="target_bma" value="<?=e($values['target_bma'])?>" pattern="BMA-[0-9]+"><datalist id="association-person-options"></datalist><a href="/member.php" target="_blank" rel="noopener">Find an existing person</a><p class="small text-secondary">Own customer accounts must belong to this profile. For a payer account, select a separate payer. Subscriptions require an existing member.</p></div>
<div class="col-12 col-md-6"><label class="form-label" for="association-reason">Reason / verification evidence</label><textarea class="form-control" id="association-reason" name="reason" maxlength="2000" minlength="3" required><?=e($values['reason'])?></textarea></div>
<div class="col-12"><details><summary>Create a missing payer person (customer links only)</summary><p>No student, membership, parent or guardian is created. If a matching full name exists, select that person or resolve the ambiguity first.</p><label><input type="checkbox" name="new_payer" value="1" <?=$values['new_payer']==='1'?'checked':''?>> Create one new separate payer on confirmation</label><div class="row g-3 mt-1"><div class="col-md-6"><label class="form-label" for="payer-first">Payer first name</label><input class="form-control" id="payer-first" name="new_first_name" maxlength="100" value="<?=e($values['new_first_name'])?>"></div><div class="col-md-6"><label class="form-label" for="payer-last">Payer last name</label><input class="form-control" id="payer-last" name="new_last_name" maxlength="100" value="<?=e($values['new_last_name'])?>"></div></div></details></div>
<div class="col-12"><button class="btn btn-primary" type="submit">Preview association — no changes yet</button></div></form>
<?php uiCardEnd(); ?><script src="/assets/js/stripe-associations.js" defer></script><?php uiLayoutEnd(); ?>

<?php
declare(strict_types=1);
require_once __DIR__.'/_common.php';
$config=adminConfig('admin');requireDashboardAccess('settings');
$db=bmaDatabase($config);$service=new DashboardPermissionService($db);$actor=adminReviewActor($db,$config);$error=null;
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    Security::csrf($_POST['csrf']??null);
    try {
        if(!is_string($_POST['dashboard_key']??null))throw new InvalidArgumentException('Invalid dashboard.');
        if(($_POST['action']??'')==='dashboard') {
            if(!is_string($_POST['display_name']??null))throw new InvalidArgumentException('Invalid display name.');
            $roles=[];foreach(DashboardPermissionService::ROLES as $role){$v=$_POST['role_'.$role]??'0';if(!in_array($v,['0','1'],true))throw new InvalidArgumentException('Invalid permission.');$roles[$role]=$v==='1';}
            foreach(['enabled','navigation'] as $flag)if(!in_array($_POST[$flag]??'0',['0','1'],true))throw new InvalidArgumentException('Invalid flag.');
            $service->saveDashboard($actor,$_POST['dashboard_key'],$_POST['display_name'],($_POST['enabled']??'0')==='1',($_POST['navigation']??'0')==='1',$roles);
        } elseif(($_POST['action']??'')==='override') {
            $target=filter_var($_POST['user_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if(!$target||!is_string($_POST['permission']??null))throw new InvalidArgumentException('Invalid user override.');
            $service->saveOverride($actor,$target,$_POST['dashboard_key'],$_POST['permission']);
        } else throw new InvalidArgumentException('Invalid action.');
        header('Location: /admin/dashboard-access.php?user_id='.(int)($_POST['user_id']??0).'&saved=1',true,303);exit;
    }catch(InvalidArgumentException $e){$error=$e->getMessage();}catch(Throwable){Security::log($config,'DASHBOARD_ACCESS_CHANGE_FAILED');$error='Permissions could not be updated.';}
}
$users=$db->query('SELECT id,display_name,role FROM app_users WHERE is_active=1 ORDER BY display_name,id')->fetchAll();
$selected=filter_var($_GET['user_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$target=null;
foreach($users as $u)if((int)$u['id']===$selected)$target=$u;
$dashboards=$service->dashboardsForUser(['id'=>$actor]);$roles=$service->rolePermissions();
uiLayoutStart('Dashboard access','settings','Control dashboard availability, role access and individual overrides.');
if($error)uiAlert($error);if(isset($_GET['saved']))echo '<div class="alert alert-success" role="status">Access settings saved.</div>';
uiCardStart('Dashboard settings','Disabled dashboards are unavailable to everyone. Navigation visibility alone does not restrict direct access. Imports, Matches, Settings and User management always require an administrator.');
foreach($dashboards as $d): ?>
<form method="post" class="border-top py-3">
<input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="dashboard"><input type="hidden" name="dashboard_key" value="<?=e($d['dashboard_key'])?>">
<div class="row g-3 align-items-end"><div class="col-12 col-lg-3"><label class="form-label" for="name-<?=e($d['id'])?>">Display name <small class="text-secondary">(<?=e($d['dashboard_key'])?>)</small></label><input class="form-control" id="name-<?=e($d['id'])?>" name="display_name" maxlength="100" required value="<?=e($d['display_name'])?>"></div>
<div class="col-6 col-lg-2"><label class="form-check"><input class="form-check-input" type="checkbox" name="enabled" value="1" <?=$d['is_enabled']?'checked':''?>> Enabled</label><label class="form-check"><input class="form-check-input" type="checkbox" name="navigation" value="1" <?=$d['show_in_navigation']?'checked':''?>> In navigation</label></div>
<div class="col-12 col-lg-5"><div class="d-flex flex-wrap gap-3"><?php foreach(DashboardPermissionService::ROLES as $role): ?><label class="form-check text-nowrap"><input class="form-check-input" type="checkbox" name="role_<?=e($role)?>" value="1" <?=($roles[$d['id']][$role]??false)?'checked':''?>> <?=e(ucfirst($role))?></label><?php endforeach; ?></div></div>
<div class="col-12 col-lg-2"><button class="btn btn-outline-primary" type="submit">Save <?=e($d['dashboard_key'])?></button></div></div></form>
<?php endforeach;uiCardEnd();uiCardStart('User overrides','No override means role inheritance. A grant cannot bypass an admin-only restriction or globally disabled dashboard.'); ?>
<form method="get" class="row g-3 align-items-end mb-3"><div class="col-12 col-md-8"><label class="form-label" for="selected-user">Active user</label><select class="form-select" name="user_id" id="selected-user"><option value="">Choose a user</option><?php foreach($users as $u): ?><option value="<?=e($u['id'])?>" <?=($selected===(int)$u['id'])?'selected':''?>><?=e($u['display_name'])?> — <?=e($u['role'])?></option><?php endforeach; ?></select></div><div class="col"><button class="btn btn-primary">Show access</button></div></form>
<?php if($target): ?>
<p><?=e($target['display_name'])?> · Role: <strong><?=e($target['role'])?></strong></p>
<div class="table-responsive"><table class="table align-middle"><thead><tr><th>Dashboard</th><th>Role access</th><th>Override</th><th>Effective access</th></tr></thead><tbody>
<?php foreach($service->dashboardsForUser($target) as $d): ?><tr><td><?=e($d['display_name'])?></td><td><?=$d['role_access']?'Allowed':'Denied'?></td><td><form method="post" class="d-flex gap-2"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="override"><input type="hidden" name="user_id" value="<?=e($target['id'])?>"><input type="hidden" name="dashboard_key" value="<?=e($d['dashboard_key'])?>"><select class="form-select form-select-sm" name="permission" aria-label="<?=e($d['display_name'])?> override"><?php foreach(['INHERIT','ALLOW','DENY'] as $option): ?><option value="<?=e($option)?>" <?=($d['user_override']??'INHERIT')===$option?'selected':''?>><?=e(ucfirst(strtolower($option)))?></option><?php endforeach; ?></select><button class="btn btn-sm btn-outline-primary">Save</button></form></td><td><span class="badge text-bg-<?=$d['effective_access']?'success':'secondary'?>"><?=$d['effective_access']?'Allowed':'Denied'?></span><?=$d['is_enabled']?'':' · Disabled'?></td></tr><?php endforeach; ?>
</tbody></table></div><?php elseif($selected): uiAlert('Select an existing active user.');endif;uiCardEnd();uiLayoutEnd();

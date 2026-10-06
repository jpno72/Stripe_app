<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/src/database.php';
require dirname(__DIR__).'/app/Security/DashboardPermissionService.php';
require dirname(__DIR__).'/app/Security/UserManagement.php';
$config=bmaConfig();if(($config['APP_ENV']??'')!=='test'||!str_starts_with($config['DB_DATABASE'],'bma_schema_test_'))throw new RuntimeException('Test database required.');
$db=bmaDatabase($config);$service=new DashboardPermissionService($db);$count=0;$users=[];$snapshot=$db->query('SELECT * FROM dashboards')->fetchAll();$roles=$db->query('SELECT * FROM role_dashboard_permissions')->fetchAll();
function accessCheck(bool $ok,string $label):void{global $count;if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;$count++;}
function refused(callable $f):bool{try{$f();return false;}catch(InvalidArgumentException|RuntimeException){return true;}}
try {
 foreach(['admin','analyst','viewer','staff'] as $role){$tag=bin2hex(random_bytes(8));$db->prepare('INSERT INTO app_users(email,email_normalized,display_name,password_hash,role) VALUES (?,?,?,?,?)')->execute([$tag.'@example.invalid',$tag.'@example.invalid','Access test '. $role,password_hash(bin2hex(random_bytes(20)),PASSWORD_DEFAULT),$role]);$users[$role]=['id'=>(int)$db->lastInsertId(),'role'=>$role];}
 $a=$users['admin']['id'];$v=$users['viewer']['id'];
 accessCheck($service->canViewDashboard($users['admin'],'revenue'),'admin allowed');
 accessCheck($service->canViewDashboard($users['analyst'],'revenue'),'analyst allowed revenue');
 accessCheck(!$service->canViewDashboard($users['viewer'],'revenue')&&$service->canViewDashboard($users['viewer'],'membership'),'viewer default matrix');
 accessCheck(!$service->canViewDashboard($users['staff'],'revenue'),'reserved staff least privilege');
 accessCheck(!$service->canViewDashboard($users['admin'],'unknown'),'unknown key fails closed');
 $r=$service->rolePermissions();$d=array_column($snapshot,null,'dashboard_key');
 $service->saveDashboard($a,'revenue','<img src=x onerror=alert(1)>',true,false,$r[$d['revenue']['id']]);
 accessCheck($service->canViewDashboard($users['admin'],'revenue')&&!in_array('revenue',array_column($service->getVisibleDashboards($users['admin']),'dashboard_key'),true),'hidden navigation still permits authorized direct access');
 $service->saveOverride($a,$v,'revenue','ALLOW');accessCheck($service->canViewDashboard($users['viewer'],'revenue'),'ALLOW overrides role deny');
 $service->saveOverride($a,$v,'membership','DENY');accessCheck(!$service->canViewDashboard($users['viewer'],'membership'),'DENY overrides role allow');
 $service->saveOverride($a,$v,'membership','INHERIT');accessCheck($service->canViewDashboard($users['viewer'],'membership'),'INHERIT restores role permission');
 $q=$db->prepare('SELECT COUNT(*) FROM user_dashboard_overrides WHERE user_id=? AND dashboard_id=?');$q->execute([$v,$d['membership']['id']]);accessCheck((int)$q->fetchColumn()===0,'INHERIT represented by absent row');
 foreach(['imports','matches','settings','users'] as $key){$service->saveOverride($a,$v,$key,'ALLOW');accessCheck(!$service->canViewDashboard($users['viewer'],$key),'admin floor '.$key);}
 $service->saveDashboard($a,'revenue','Revenue',false,true,$r[$d['revenue']['id']]);accessCheck(!$service->canViewDashboard($users['admin'],'revenue')&&!$service->canViewDashboard($users['viewer'],'revenue'),'disabled denied including admin and overrides');
 $editedRoles=$r[$d['revenue']['id']];$editedRoles['viewer']=true;
 $service->saveDashboard($a,'revenue','Revenue',true,true,$editedRoles);$service->saveOverride($a,$v,'revenue','INHERIT');
 accessCheck($service->canViewDashboard($users['viewer'],'revenue'),'editable role matrix grants inherited access');
 $qAudit=$db->prepare("SELECT COUNT(*) FROM audit_log WHERE actor_user_id=? AND action='dashboard.role_permission_changed'");$qAudit->execute([$a]);accessCheck((int)$qAudit->fetchColumn()===1,'role permission change audited');
 accessCheck(refused(fn()=>$service->saveDashboard($a,'settings','Settings',false,true,$r[$d['settings']['id']])),'Settings lockout blocked');
 accessCheck(refused(fn()=>$service->saveDashboard($a,'users','Users',false,true,$r[$d['users']['id']])),'User management lockout blocked');
 accessCheck(refused(fn()=>$service->saveOverride($v,$a,'revenue','ALLOW')),'viewer cannot administer permissions');
 accessCheck(refused(fn()=>$service->saveOverride($a,$v,'unknown','ALLOW')),'unknown mutation rejected');
 accessCheck(refused(fn()=>$service->saveOverride($a,$v,'membership','MAYBE')),'invalid override rejected');
 accessCheck(refused(fn()=>$service->saveDashboard($a,'revenue','Revenue',true,true,['invented'=>true])),'invalid roles rejected');
 $db->prepare('UPDATE app_users SET is_active=0 WHERE id=?')->execute([$v]);accessCheck(!$service->canViewDashboard($users['viewer'],'overview'),'inactive canonical user denied despite stale supplied role');
 accessCheck(refused(fn()=>$service->saveOverride($a,$v,'revenue','ALLOW')),'inactive override target rejected');
 $db->prepare('UPDATE app_users SET is_active=1 WHERE id=?')->execute([$v]);
 $service->saveOverride($a,$v,'revenue','ALLOW');$service->saveOverride($a,$v,'revenue','ALLOW');$q->execute([$v,$d['revenue']['id']]);accessCheck((int)$q->fetchColumn()===1,'repeated override no duplicate');
 $q=$db->prepare("SELECT metadata FROM audit_log WHERE actor_user_id=? AND action='dashboard.user_override_changed' LIMIT 1");$q->execute([$a]);$audit=json_decode($q->fetchColumn(),true);accessCheck(isset($audit['previous'],$audit['new'],$audit['dashboard_key']),'audit actor previous new key persisted');
 $service->saveOverride($a,$a,'users','DENY');accessCheck(refused(fn()=>(new UserManagement($db))->update($a,$v,'viewer',true)),'user-management service enforces dashboard permission');
 echo 'Checks passed: '.$count.PHP_EOL;
}finally {
 foreach($snapshot as $d)$db->prepare('UPDATE dashboards SET display_name=?,is_enabled=?,show_in_navigation=?,updated_at=? WHERE id=?')->execute([$d['display_name'],$d['is_enabled'],$d['show_in_navigation'],$d['updated_at'],$d['id']]);
 foreach($roles as $r)$db->prepare('UPDATE role_dashboard_permissions SET can_view=? WHERE role=? AND dashboard_id=?')->execute([$r['can_view'],$r['role'],$r['dashboard_id']]);
 foreach($users as $u){$db->prepare('DELETE FROM user_dashboard_overrides WHERE user_id=?')->execute([$u['id']]);$db->prepare('DELETE FROM audit_log WHERE actor_user_id=?')->execute([$u['id']]);$db->prepare('DELETE FROM app_users WHERE id=?')->execute([$u['id']]);}
}

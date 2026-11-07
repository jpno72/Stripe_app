<?php
declare(strict_types=1);
require_once __DIR__.'/_common.php';
require_once APP_ROOT.'/app/Members/PeopleMemberFinalizationService.php';
require_once APP_ROOT.'/app/Views/member-finalization.php';
$config=adminConfig('admin');requireDashboardAccess('matches');$db=bmaDatabase($config);$actor=adminReviewActor($db,$config);$service=new PeopleMemberFinalizationService($db);$pending=null;$error='';
try{
 if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
  Security::csrf($_POST['csrf']??null);
  if(($_POST['phase']??null)==='preview'){
   $choices=PeopleMemberFinalizationService::choices(is_array($_POST['people']??null)?$_POST['people']:[]);$backup=is_string($_POST['backup_reference']??null)?trim($_POST['backup_reference']):'';if(mb_strlen($backup)>500)throw new RuntimeException('INVALID_BACKUP_REFERENCE');
   $p=$service->preview($actor,$choices);$pending=['actor'=>$actor,'operation'=>bin2hex(random_bytes(16)),'choices'=>$choices,'digest'=>$p['digest'],'count'=>count($p['changes']),'backup'=>$backup,'expires'=>time()+900];$_SESSION['member_finalization_preview']=$pending;
  }else{
   $pending=$_SESSION['member_finalization_preview']??null;if(!is_array($pending)||($pending['operation']??null)!==($_POST['operation_id']??null))$pending=$_SESSION['member_finalization_completed']??null;
   if(!is_array($pending)||$pending['actor']!==$actor||$pending['operation']!==($_POST['operation_id']??null)||$pending['expires']<time()||($_POST['acknowledge']??null)!=='1'||($_POST['confirm_count']??null)!==(string)$pending['count']||(($config['APP_ENV']??'production')==='production'&&$pending['backup']===''))throw new RuntimeException('INVALID_FINALIZATION');
   $service->confirm($actor,$pending['operation'],$pending['digest'],$pending['choices'],$pending['backup']);$_SESSION['member_finalization_completed']=$pending;unset($_SESSION['member_finalization_preview']);header('Location: finalize-members.php?saved=1',true,303);exit;
  }
 }else $p=$service->preview($actor);
}catch(Throwable){http_response_code(409);$error='Finalization is unavailable or the preview changed. No partial changes were saved. Verify the selected identities, evidence, outcome and required backup, then prepare a fresh preview.';unset($_SESSION['member_finalization_preview']);adminHead('Finalize People & Members');echo '<div class="alert alert-warning">'.e($error).'</div><a href="finalize-members.php">Fresh read-only preview</a>';adminFoot();exit;}
adminHead('Finalize People & Members',['/assets/css/match-review.css?v=5']);if(isset($_GET['saved']))echo '<div class="alert alert-success">Reviewed finalization saved and audited.</div>';renderMemberFinalization($p,$pending,$error);adminFoot();

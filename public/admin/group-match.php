<?php
declare(strict_types=1);
require_once __DIR__.'/_common.php';
require_once APP_ROOT.'/app/Matching/GroupedMatchReviewService.php';
require_once APP_ROOT.'/app/Views/grouped-match-review.php';
$config=adminConfig('admin');requireDashboardAccess('matches');$db=bmaDatabase($config);$actor=adminReviewActor($db,$config);$service=new GroupedMatchReviewService($db);
$error='';$p=null;$pending=null;
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    Security::csrf($_POST['csrf']??null);if(($_POST['phase']??null)==='preview'){
        try{$group=is_string($_POST['group']??null)?$_POST['group']:'';$action=is_string($_POST['decision']??null)?$_POST['decision']:'';if(!in_array($action,['manual_same','manual_relationship','different','defer'],true))throw new RuntimeException('INVALID_GROUP_ACTION');$input=GroupedMatchReviewService::reviewInput($_POST);$p=$service->preview($actor,$group,$action,$input);$pending=['actor'=>$actor,'operation'=>bin2hex(random_bytes(16)),'group'=>$group,'action'=>$action,'input'=>$input,'digest'=>$p['digest'],'selection'=>$p['selection'],'count'=>count($p['selection']),'expires'=>time()+900];$_SESSION['identity_group_preview']=$pending;adminHead('Preview group decision',['/assets/css/match-review.css?v=5']);renderGroupDecision($p,$pending);adminFoot();exit;}
        catch(Throwable){http_response_code(409);adminHead('Preview unavailable');echo '<div class="alert alert-warning">The preview could not be prepared. Check the choice, existing people and notes.</div><a href="matches.php">Back to groups</a>';adminFoot();exit;}
    }
$pending=$_SESSION['identity_group_preview']??null;
    if(!is_array($pending)||($pending['operation']??null)!==($_POST['operation_id']??null))$pending=$_SESSION['identity_group_completed']??null;
    try{
        if(!is_array($pending)||$pending['actor']!==$actor||$pending['operation']!==($_POST['operation_id']??null)||$pending['expires']<time()||($_POST['acknowledge']??null)!=='1'||($_POST['confirm_count']??null)!==(string)$pending['count'])throw new RuntimeException('INVALID_GROUP_PREVIEW');
        $service->confirm($actor,$pending['operation'],$pending['digest'],$pending['group'],$pending['action'],$pending['selection'],$pending['input']??[]);
        $_SESSION['identity_group_completed']=$pending;unset($_SESSION['identity_group_preview']);$_SESSION['match_review_notice']='Grouped decision saved. All affected candidate decisions were audited.';header('Location: matches.php',true,303);exit;
    }catch(Throwable){http_response_code(409);unset($_SESSION['identity_group_preview']);adminHead('Review changed',['/assets/css/match-review.css?v=5']);echo '<div class="alert alert-warning">This decision could not be applied. No partial changes were saved. Return to a fresh grouped preview.</div><a href="matches.php">Back to grouped review</a>';adminFoot();exit;}
}
try{
    if(($_GET['action']??null)==='resolve'){$id=is_string($_GET['group']??null)?$_GET['group']:'';$resolution=$service->resolution($actor,$id);adminHead('Resolve identity group',['/assets/css/match-review.css?v=5']);renderGroupResolver($resolution);adminFoot();exit;}
    $group=is_string($_GET['group']??null)?$_GET['group']:'';$action=is_string($_GET['action']??null)?$_GET['action']:'same';$p=$service->preview($actor,$group,$action);
    $pending=['actor'=>$actor,'operation'=>bin2hex(random_bytes(16)),'group'=>$group,'action'=>$action,'digest'=>$p['digest'],'selection'=>$p['selection'],'count'=>count(array_unique([...$p['selection'],...($p['signer_selection']??[])])),'expires'=>time()+900];$_SESSION['identity_group_preview']=$pending;
}catch(Throwable){http_response_code(404);adminHead('Group unavailable');echo '<div class="alert alert-warning">This group is unavailable or has changed. No records were modified.</div><a href="matches.php">Return to grouped review</a>';adminFoot();exit;}
adminHead('Review identity group',['/assets/css/match-review.css?v=5']);renderGroupDecision($p,$pending,$error);adminFoot();

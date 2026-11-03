<?php
declare(strict_types=1);
require_once __DIR__.'/_common.php';
require_once APP_ROOT.'/app/Matching/BulkExactMatchService.php';
require_once APP_ROOT.'/app/Views/bulk-match-review.php';
$config=adminConfig('admin');requireDashboardAccess('matches');
$db=bmaDatabase($config);$actor=adminReviewActor($db,$config);$bulk=new BulkExactMatchService($db);
$error=null;$success=null;$preview=null;
$messages=['BULK_PREVIEW_STALE'=>'Records changed since preview. Nothing was confirmed. Review a fresh preview.',
    'INVALID_BULK_PREVIEW'=>'This preview expired or is invalid. Review a fresh preview.',
    'BULK_BUSY'=>'Another bulk review is running. Nothing was changed; try again shortly.',
    'BULK_SCOPE_TOO_LARGE'=>'The review scope exceeds the safe limit. Ask the administrator to arrange a smaller reviewed batch.',
    'BULK_NO_ELIGIBLE'=>'No eligible candidates remain. Nothing was changed.'];
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    Security::csrf($_POST['csrf']??null);
    $operation=is_string($_POST['operation_id']??null)?$_POST['operation_id']:'';
    $pending=$_SESSION['bulk_exact_preview']??null;
    if(!is_array($pending)||($pending['operation']??'')!==$operation)$pending=$_SESSION['bulk_exact_completed']??null;
    try{
        if(($_POST['bulk_action']??null)!=='confirm'||($_POST['confirm_exact']??null)!=='1'||!is_array($pending)||($pending['actor']??null)!==$actor||($pending['operation']??'')!==$operation||($pending['expires']??0)<time()||($_POST['confirm_count']??null)!==(string)($pending['summary']['eligible_candidates']??''))throw new RuntimeException('INVALID_BULK_PREVIEW');
        $result=$bulk->confirm($actor,$operation,$pending['digest']);
        $_SESSION['bulk_exact_completed']=$pending;$_SESSION['bulk_exact_result']=$result;unset($_SESSION['bulk_exact_preview']);
        header('Location: bulk-matches.php?completed=1',true,303);exit;
    }catch(Throwable $e){
        http_response_code(409);$error=$messages[$e->getMessage()]??'The bulk review could not complete. All decisions in this operation were rolled back. Review a fresh preview.';
        unset($_SESSION['bulk_exact_preview']);Security::log($config,'BULK_EXACT_FAILED');
    }
}
if(isset($_GET['completed'],$_SESSION['bulk_exact_result'])){$success=$_SESSION['bulk_exact_result'];unset($_SESSION['bulk_exact_result']);}
try{
    $preview=$bulk->preview($actor);
    $_SESSION['bulk_exact_preview']=['actor'=>$actor,'operation'=>bin2hex(random_bytes(16)),'digest'=>$preview['digest'],'summary'=>$preview['summary'],'exclusions'=>$preview['exclusions'],'expires'=>time()+900];
}catch(Throwable $e){$error=$messages[$e->getMessage()]??'The read-only preview is unavailable. No records were changed.';unset($_SESSION['bulk_exact_preview']);http_response_code(503);}
adminHead('Bulk confirm exact matches',['/assets/css/match-review.css?v=3']);
renderBulkMatchPreview($preview,$_SESSION['bulk_exact_preview']??null,$error,$success);
adminFoot();

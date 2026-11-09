<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/app/Import/bootstrap.php';
require_once dirname(__DIR__).'/app/Import/ConsolidatedReconciliation.php';
$options=getopt('',['directory:','approvals:','admin-id:','dry-run','apply','approve-digest:','backup-reference:']);
try{
 $config=bmaConfig();$db=bmaDatabase($config);$actor=filter_var($options['admin-id']??null,FILTER_VALIDATE_INT);if(!$actor)throw new RuntimeException('ADMIN_REVIEW_REQUIRED');$directory=$options['directory']??ConsolidatedInputs::directory();$input=ConsolidatedInputs::read($directory,Paths::servedRoots($config));$approvals=[];
 if(isset($options['approvals'])){$path=realpath($options['approvals']);if(!$path||!is_file($path)||filesize($path)>1048576)throw new RuntimeException('APPROVALS_INVALID');Paths::assertPrivate($path,Paths::servedRoots($config));$approvals=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);if(!is_array($approvals))throw new RuntimeException('APPROVALS_INVALID');}
 $service=new ConsolidatedReconciliation($db);
 if(isset($options['apply'])){if(isset($options['dry-run']))throw new RuntimeException('MODE_INVALID');$result=$service->apply($actor,$input,$approvals,(string)($options['approve-digest']??''),(string)($options['backup-reference']??''));}
 else{$p=$service->preview($actor,$input,$approvals,HistoricalPaymentPreview::read($directory,Paths::servedRoots($config)));$result=['mode'=>'dry-run','summary'=>$p['summary'],'before_counts'=>$p['before_counts'],'approve_digest'=>$p['digest']];}
 fwrite(STDOUT,json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL);
}catch(Throwable){fwrite(STDERR,"Reconciliation failed; no source values are logged. Verify private inputs, administrator, references, approvals and preview digest.
");exit(1);}

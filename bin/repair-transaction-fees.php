<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/app/Import/bootstrap.php';
require_once dirname(__DIR__).'/app/Import/TransactionFeeRepair.php';
$options=getopt('',['help','config:','batch:','file-hash:','actor-user:','balance-evidence:','confirm-stripe-evidence','dry-run','apply']);
if(isset($options['help'])){echo "Usage: php bin/repair-transaction-fees.php --batch=ID --file-hash=SHA256 --actor-user=ADMIN_ID [--config=/private/config.php] [--balance-evidence=/private/verified-balances.json] [--dry-run | --apply] [--confirm-stripe-evidence]\nDefault: dry run. Apply with external evidence requires confirmation of its verified Stripe origin.\n";exit;}
try{
 if(isset($options['apply'],$options['dry-run']))throw new RuntimeException('INVALID_FEE_REPAIR_REQUEST');
 $batch=filter_var($options['batch']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
 $actor=filter_var($options['actor-user']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
 if(!$batch||!$actor||!is_string($options['file-hash']??null))throw new RuntimeException('INVALID_FEE_REPAIR_REQUEST');
 $config=bmaConfig(isset($options['config'])&&is_string($options['config'])?$options['config']:null);
 $document=null;$hash=null;$apply=isset($options['apply']);
 if(isset($options['balance-evidence'])){
  $input=$options['balance-evidence'];$path=is_string($input)&&Paths::isAbsolute($input)?realpath($input):false;
  if($path===false||!is_file($path)||!is_readable($path)||filesize($path)>10485760)throw new RuntimeException('INVALID_BALANCE_EVIDENCE');
  Paths::assertPrivate($path,Paths::servedRoots($config));
  if($apply&&!isset($options['confirm-stripe-evidence']))throw new RuntimeException('BALANCE_EVIDENCE_CONFIRMATION_REQUIRED');
  $bytes=file_get_contents($path);if($bytes===false)throw new RuntimeException('INVALID_BALANCE_EVIDENCE');
  $hash=hash('sha256',$bytes);$document=json_decode($bytes,true,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);unset($bytes);
  if(!is_array($document))throw new RuntimeException('INVALID_BALANCE_EVIDENCE');
 }
 $result=(new TransactionFeeRepair(bmaDatabase($config)))->run($batch,$options['file-hash'],$actor,$apply,$document,$hash);
 echo json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable){fwrite(STDERR,"Fee repair could not proceed. Check batch/hash, active administrator, account/environment and verified private evidence. No source contents or driver details logged.\n");exit(1);}

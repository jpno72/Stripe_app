<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/app/Import/bootstrap.php';
require_once dirname(__DIR__).'/app/Import/CognitoEmergencyBackfill.php';
$options=getopt('',['help','config:','file:','namespace:','batch:','actor-user:','expected-rows:','dry-run','apply']);
if(isset($options['help'])){
    echo "Usage: php bin/backfill-cognito-emergency-contacts.php --file=/private/original.csv --namespace=bma-waiver.BloomsburgMartialArtsAcademy2 --batch=ORIGINAL_ID --actor-user=ACTIVE_ADMIN_ID [--config=/private/config.php] [--expected-rows=1074] [--dry-run | --apply]\nDefault: read-only dry run. The CSV SHA-256 must match the original successful native import batch.\n";exit;
}
try{
    if(isset($options['apply'],$options['dry-run']))throw new RuntimeException('INVALID_OPTIONS');
    foreach(['file','namespace'] as $key)if(!is_string($options[$key]??null))throw new RuntimeException('INVALID_OPTIONS');
    $ints=[];
    foreach(['batch'=>null,'actor-user'=>null,'expected-rows'=>1074] as $key=>$default){
        $ints[$key]=filter_var($options[$key]??$default,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($ints[$key]===false)throw new RuntimeException('INVALID_OPTIONS');
    }
    $config=bmaConfig(isset($options['config'])&&is_string($options['config'])?$options['config']:null);
    $result=(new CognitoEmergencyBackfill(bmaDatabase($config),$config))->run($options['file'],$options['namespace'],$ints['batch'],$ints['actor-user'],$ints['expected-rows'],isset($options['apply']));
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
}catch(CognitoEmergencyBackfillFailure $error){
    echo json_encode($error->counts,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
    fwrite(STDERR,'Emergency backfill stopped: '.$error->getMessage().'. No changes committed.'.PHP_EOL);exit(1);
}catch(Throwable){fwrite(STDERR,"Emergency backfill could not start. Check options, private configuration and database access. No contact values logged.\n");exit(1);}

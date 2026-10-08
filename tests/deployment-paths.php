<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$source = dirname(__DIR__);
$fixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bma-split-' . bin2hex(random_bytes(8));
$project = $fixture . '/repositories/project';
$public = $fixture . '/public_html/customers/public';
$private = $fixture . '/private';
$checks = 0;
function pathCheck(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    $checks++; echo 'PASS ' . $label . PHP_EOL;
}
function copyPathTree(string $from, string $to): void {
    if (!is_dir($to)) mkdir($to,0700,true);
    foreach (new DirectoryIterator($from) as $item) {
        if ($item->isDot()) continue;
        if ($item->isDir()) copyPathTree($item->getPathname(),$to.'/'.$item->getFilename());
        else copy($item->getPathname(),$to.'/'.$item->getFilename());
    }
}
function runPathCode(string $code, array $values=[]): array {
    $environment=getenv();
    foreach (['BMA_APP_ROOT','BMA_CONFIG_FILE','PUBLIC_WEB_ROOT'] as $key) unset($environment[$key]);
    $environment=array_replace($environment,$values);
    $pipes=[];
    $process=proc_open([PHP_BINARY,'-d','display_errors=1','-r',$code],[1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$environment);
    if(!is_resource($process)) throw new RuntimeException('Test subprocess unavailable.');
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
    return [$out,$err,$exit];
}
function phpString(string $value): string { return var_export($value,true); }
try {
    foreach ([$project,$public,$private.'/sessions',$private.'/import-tmp',$project.'/sql',$project.'/vendor'] as $dir) mkdir($dir,0700,true);
    foreach (['app','src','config','bin'] as $dir) copyPathTree($source.'/'.$dir,$project.'/'.$dir);
    copyPathTree($source.'/public',$public);
    // Bootstrap tests inspect vendor location, not Stripe behavior or API calls.
    file_put_contents($project.'/vendor/autoload.php',"<?php\n");
    $data=['PUBLIC_WEB_ROOT'=>$public,'APP_ERROR_LOG'=>$private.'/errors.log','SESSION_SAVE_PATH'=>$private.'/sessions','APP_ENV'=>'production','APP_KEY'=>bin2hex(random_bytes(32)),'DB_PASSWORD'=>'example-placeholder'];
    $config=$private.'/config.php';
    file_put_contents($config,'<?php return '.var_export($data,true).';');
    // Substitute only the copied fixture's controlled fallback literals; never create production paths locally.
    $bridgeFile=$public.'/_bootstrap.php';$bridgeText=file_get_contents($bridgeFile);
    if(!preg_match('~\$_SERVER\[\'BMA_APP_ROOT\'\] \?\? \'([^\']+)\'~',$bridgeText,$rootFallback)
        || !preg_match("~Paths::deploymentValue\\('BMA_CONFIG_FILE'\\) \\?\\? '([^']+)'~",$bridgeText,$configFallback))throw new RuntimeException('Central fallback declarations missing.');
    $bridgeText=str_replace([phpString($rootFallback[1]),phpString($configFallback[1])],[phpString($project),phpString($config)],$bridgeText);
    file_put_contents($bridgeFile,$bridgeText);
    $bridge=phpString($public.'/_bootstrap.php');
    $probe='register_shutdown_function(function(){echo "|STATUS=".http_response_code();}); require '.$bridge.'; echo "|OK=".APP_ROOT;';
    foreach ([['BMA_APP_ROOT'=>''],['BMA_APP_ROOT'=>$fixture.'/missing'],['BMA_APP_ROOT'=>'relative/path'],['BMA_APP_ROOT'=>$private]] as $env) {
        [$out,$err]=runPathCode((($env['BMA_APP_ROOT']??null)===''?'putenv("BMA_APP_ROOT=");':'').$probe,$env);
        pathCheck($out==='Application access is unavailable.|STATUS=503'&&$err==='','supplied empty/nonexistent/relative/incomplete root fails generically');
    }
    [$out,$err]=runPathCode($probe,['BMA_APP_ROOT'=>$project]);
    pathCheck(str_contains($out,'|OK='.realpath($project))&&$err==='','valid split application root resolves');
    pathCheck(!is_dir($project.'/public'),'private project fixture has no public directory');
    [$out,$err]=runPathCode('$_SERVER["BMA_APP_ROOT"]='.phpString($project).';'.$probe);
    pathCheck(str_contains($out,'|OK='.realpath($project))&&$err==='','trusted server BMA_APP_ROOT supported');
    [$out,$err]=runPathCode('$_SERVER["HTTP_BMA_APP_ROOT"]='.phpString($source).';'.$probe);
    pathCheck(str_contains($out,'|OK='.realpath($project))&&$err==='','client path header ignored; approved fallback retained');
    [$out,$err]=runPathCode('putenv("BMA_APP_ROOT=");$_SERVER["BMA_APP_ROOT"]='.phpString($project).';'.$probe);
    pathCheck($out==='Application access is unavailable.|STATUS=503'&&$err==='','explicit empty environment root wins over server value');

    [$out,$err]=runPathCode($probe);
    pathCheck(str_contains($out,'|OK='.realpath($project))&&$err==='','absent runtime app root uses controlled fallback');
    [$out,$err]=runPathCode('$_SERVER["BMA_APP_ROOT"]='.phpString($project).';'.$probe,['BMA_APP_ROOT'=>$source]);
    pathCheck(str_contains($out,'|OK='.realpath($source))&&$err==='','environment app root outranks trusted server and fallback');
    [$out,$err]=runPathCode('$_SERVER["BMA_APP_ROOT"]='.phpString($source).';'.$probe);
    pathCheck(str_contains($out,'|OK='.realpath($source))&&$err==='','trusted server app root outranks fallback');
    [$out,$err]=runPathCode('require '.$bridge.';echo BMA_CONFIG_PATH==='.phpString(realpath($config)).'?"CONFIG_PATH_OK":"BAD";');
    pathCheck($out==='CONFIG_PATH_OK'&&$err==='','absent runtime config path resolves controlled fallback');
    $overrideConfig=$private.'/config-override.php';$overrideData=$data;$overrideData['fallback_test']='runtime';
    file_put_contents($overrideConfig,'<?php return '.var_export($overrideData,true).';');
    $selected='require '.$bridge.';require APP_ROOT."/src/database.php";$c=bmaConfig();echo ($c["fallback_test"]??"");';
    [$out,$err]=runPathCode($selected,['BMA_CONFIG_FILE'=>$overrideConfig]);
    pathCheck($out==='runtime'&&$err==='','environment config path and existing loader outrank fallback');
    [$out,$err]=runPathCode('$_SERVER["BMA_CONFIG_FILE"]='.phpString($overrideConfig).';'.$selected);
    pathCheck($out==='runtime'&&$err==='','trusted server config path and loader outrank fallback');
    [$out,$err]=runPathCode('$_SERVER["BMA_CONFIG_FILE"]='.phpString($private.'/missing').';'.$selected,['BMA_CONFIG_FILE'=>$overrideConfig]);
    pathCheck($out==='runtime'&&$err==='','environment config path outranks conflicting server path');
    [$out,$err]=runPathCode('require '.$bridge.';require APP_ROOT."/src/database.php";$c=bmaConfig();echo $c["PUBLIC_WEB_ROOT"]==='.phpString($public).'?"LOADER_OK":"BAD";');
    pathCheck($out==='LOADER_OK'&&$err==='','existing loader consumes bootstrap config fallback without runtime variables');
    foreach([$fixture.'/missing-config',$private] as $badConfig){
        [$out,$err]=runPathCode($probe,['BMA_CONFIG_FILE'=>$badConfig]);
        pathCheck($out==='Application access is unavailable.|STATUS=503'&&$err==='','supplied invalid config fails generically instead of falling back');
    }
    [$out,$err]=runPathCode('putenv("BMA_CONFIG_FILE=");'.$probe);
    pathCheck($out==='Application access is unavailable.|STATUS=503'&&$err==='','explicit empty config path does not silently fall back');
    $unsafeConfig=$public.'/unsafe-config.php';file_put_contents($unsafeConfig,'<?php echo "EXECUTED"; return [];');
    [$out,$err]=runPathCode($probe,['BMA_CONFIG_FILE'=>$unsafeConfig]);
    pathCheck($out==='Application access is unavailable.|STATUS=503'&&$err==='','bootstrap rejects config inside actual public root without execution');
    $loader='require '.phpString($project.'/src/database.php').';';
    $security='require '.phpString($project.'/app/Security/Security.php').';';
    $load='$c=bmaConfig('.phpString($config).');';
    [$out,$err]=runPathCode($loader.$load.'echo "CONFIG_OK";');
    pathCheck($out==='CONFIG_OK'&&$err==='','CLI config supplies actual root without DOCUMENT_ROOT');
    [$out,$err]=runPathCode($loader.'$c=bmaConfig();echo "CONFIG_OK";',['BMA_CONFIG_FILE'=>$config]);
    pathCheck($out==='CONFIG_OK'&&$err==='','environment BMA_CONFIG_FILE selects private config');
    [$out,$err]=runPathCode('$_SERVER["BMA_CONFIG_FILE"]='.phpString($config).';'.$loader.'$c=bmaConfig();echo "CONFIG_OK";');
    pathCheck($out==='CONFIG_OK'&&$err==='','trusted server BMA_CONFIG_FILE supported');
    [$out,$err]=runPathCode('putenv("DB_PASSWORD=");'.$loader.$load.'echo $c["DB_PASSWORD"]===""?"EMPTY_OK":"BAD";');
    pathCheck($out==='EMPTY_OK'&&$err==='','intentional empty environment value still overrides config');
    $publicConfig=$public.'/config-test.php';
    file_put_contents($publicConfig,'<?php return '.var_export($data,true).';');
    $reject='try{bmaConfig('.phpString($publicConfig).');echo "BAD";}catch(RuntimeException){echo "REJECT";}';
    [$out,$err]=runPathCode($loader.$reject,['PUBLIC_WEB_ROOT'=>$public]);
    pathCheck($out==='REJECT'&&$err==='','config under environment PUBLIC_WEB_ROOT rejected');
    [$out,$err]=runPathCode($loader.$reject);
    pathCheck($out==='REJECT'&&$err==='','config under config-declared PUBLIC_WEB_ROOT rejected after load');
    file_put_contents($publicConfig,'<?php echo "EXECUTED"; return '.var_export($data,true).';');
    [$out,$err]=runPathCode('$_SERVER["DOCUMENT_ROOT"]='.phpString($public).';'.$loader.$reject);
    pathCheck($out==='REJECT'&&$err==='','known DOCUMENT_ROOT config rejected before execution');
    [$out,$err]=runPathCode('require '.$bridge.';'.$loader.$reject,['BMA_APP_ROOT'=>$project]);
    pathCheck($out==='REJECT'&&$err==='','actual public bridge directory protects config without server root');
    [$out,$err]=runPathCode($loader.'try{bmaConfig();echo "BAD";}catch(RuntimeException){echo "REJECT";}');
    pathCheck($out==='REJECT'&&$err==='','CLI privacy validation fails closed without any actual root');
    [$out,$err]=runPathCode('$_SERVER["PUBLIC_WEB_ROOT"]='.phpString($public).';'.$loader.'echo count(Paths::servedRoots())===1?"OK":"BAD";');
    pathCheck($out==='OK'&&$err==='','trusted server PUBLIC_WEB_ROOT supported');
    [$out,$err]=runPathCode($loader.$load.'echo "CONFIG_OK";',['PUBLIC_WEB_ROOT'=>$public]);
    pathCheck($out==='CONFIG_OK'&&$err==='','environment PUBLIC_WEB_ROOT precedence retained');

    foreach (['log','session'] as $kind) {
        foreach ([false,true] as $underPublic) {
            $c=$data;
            if($kind==='log')$c['APP_ERROR_LOG']=($underPublic?$public:$private).'/errors.log';
            else $c['SESSION_SAVE_PATH']=$underPublic?$public:$private.'/sessions';
            $method=$kind==='log'?'privateLogPath':'privateSessionPath';
            $code=$security.'try{Security::'.$method.'('.var_export($c,true).');echo "OK";}catch(RuntimeException){echo "REJECT";}';
            [$out,$err]=runPathCode($code);
            pathCheck($out===($underPublic?'REJECT':'OK')&&$err==='',($underPublic?'public ':'private ').$kind.' location '.($underPublic?'rejected':'accepted'));
        }
    }
    $secondRoot=$fixture.'/second-web-root';mkdir($secondRoot);
    foreach (['APP_ERROR_LOG'=>'/errors.log','SESSION_SAVE_PATH'=>''] as $key=>$suffix) {
        $c=$data;$c[$key]=$secondRoot.$suffix;$method=$key==='APP_ERROR_LOG'?'privateLogPath':'privateSessionPath';
        [$out,$err]=runPathCode('$_SERVER["DOCUMENT_ROOT"]='.phpString($secondRoot).';'.$security.'try{Security::'.$method.'('.var_export($c,true).');echo "BAD";}catch(RuntimeException){echo "REJECT";}');
        pathCheck($out==='REJECT'&&$err==='','additional DOCUMENT_ROOT protects '.$key);
    }
    $adjacent=$public.'-private';mkdir($adjacent);
    $c=$data;$c['APP_ERROR_LOG']=$adjacent.'/errors.log';
    [$out,$err]=runPathCode($security.'Security::privateLogPath('.var_export($c,true).');echo "OK";');
    pathCheck($out==='OK'&&$err==='','directory boundary avoids false positive for public-name prefix');
    [$out,$err]=runPathCode($security.$load.'$_SERVER["HTTPS"]="on";$_SERVER["SERVER_PORT"]="443";Security::boot($c);echo session_status()===PHP_SESSION_ACTIVE?"SESSION_OK":"BAD";session_destroy();');
    pathCheck($out==='SESSION_OK'&&$err==='','session boot retains full configured root context');

    $count=0;$includes=[];
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source.'/public',FilesystemIterator::SKIP_DOTS)) as $file) {
        if($file->getExtension()!=='php')continue;
        $text=file_get_contents($file->getPathname());
        pathCheck(!preg_match('~dirname\\(__DIR__(?:,\\s*\\d+)?\\)\\s*\\.\\s*[\'\"]/app/~',$text),'no adjacent-app assumption: '.$file->getFilename());
        preg_match_all('~require_once\\s+APP_ROOT\\s*\\.\\s*[\'\"](/app/[^\'\"]+)[\'\"]~',$text,$m);
        foreach($m[1] as $suffix){if(!is_file($project.$suffix))throw new RuntimeException('Private include missing.');$includes[$suffix]=true;$count++;}
    }
    pathCheck($count===25,'all 25 private includes resolve through APP_ROOT');
    $code='require '.$bridge.';';foreach(array_keys($includes) as $suffix)$code.='require_once APP_ROOT.'.phpString($suffix).';';$code.='echo "INCLUDES_OK";';
    [$out,$err]=runPathCode($code,['BMA_APP_ROOT'=>$project]);
    pathCheck($out==='INCLUDES_OK'&&$err==='','private include graph loads from split tree');
    $pipes=[];$p=proc_open([PHP_BINARY,$project.'/bin/create-user.php','--help'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($p);
    pathCheck($status===0&&str_contains($out,'Usage:')&&$err==='','private CLI user-tool help works without public bootstrap or database');
    $htaccess=file_get_contents($source.'/public/.htaccess');
    pathCheck(str_contains($htaccess,'_bootstrap\\.php')&&str_contains($htaccess,'Require all denied'),'Apache source denies direct bootstrap HTTP access');
    echo 'Focused checks passed: '.$checks.PHP_EOL;
} finally {
    // Delete only the unique disposable fixture this test created beneath system temp.
    if(is_dir($fixture)) {
        if(realpath(dirname($fixture))!==realpath(sys_get_temp_dir())||!str_starts_with(basename($fixture),'bma-split-'))throw new RuntimeException('Unsafe fixture cleanup path.');
        foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            if($item->isDir()&&!$item->isLink())rmdir($item->getPathname());else unlink($item->getPathname());
        }
        rmdir($fixture);
    }
}

<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
// Fault injection for the real copied _common.php; no application/database state is changed.
$root=dirname(__DIR__);$fixture=sys_get_temp_dir().DIRECTORY_SEPARATOR.'bma-admin-errors-'.bin2hex(random_bytes(8));$checks=0;
function adminErrorCheck(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException('FAIL '.$label);++$checks;echo 'PASS '.$label.PHP_EOL;}
try{
 foreach(['/private/app/Import','/private/app/Views','/private/app/Security','/public/admin'] as $dir)mkdir($fixture.$dir,0700,true);
 copy($root.'/public/admin/_common.php',$fixture.'/public/admin/_common.php');
 file_put_contents($fixture.'/public/_bootstrap.php','<?php define("APP_ROOT",'.var_export($fixture.'/private',true).');');
 file_put_contents($fixture.'/private/app/Import/bootstrap.php',<<<'PHP'
<?php
function bmaConfig():array{if(BMA_HANDLER_CASE==='configuration')throw new RuntimeException('sensitive-exception-marker');return ['fixture'=>true];}
class HandlerFixturePDO extends PDO{public function __construct(){}}
function bmaDatabase(array $config):PDO{if(BMA_HANDLER_CASE==='database')throw new RuntimeException('sensitive-exception-marker');return new HandlerFixturePDO;}
final class Security{
 public static int $calls=0;
 public static function boot(array $config):void{if(in_array(BMA_HANDLER_CASE,['boot','logging'],true))throw new RuntimeException('sensitive-exception-marker');$_SESSION=['csrf'=>'fixture-csrf'];}
 public static function log(array $config,string $code):void{++self::$calls;if(in_array(BMA_HANDLER_CASE,['logging','provider'],true))throw new RuntimeException('sensitive-exception-marker');}
}
PHP);
 file_put_contents($fixture.'/private/app/Views/layout.php',"<?php\n");
 file_put_contents($fixture.'/private/app/Security/CurrentUserProvider.php',<<<'PHP'
<?php
final class SessionCurrentUserProvider{
 public function __construct(PDO $db,array $config){}
 public function current():?array{if(BMA_HANDLER_CASE==='provider')throw new RuntimeException('sensitive-exception-marker');return match(BMA_HANDLER_CASE){'viewer'=>['id'=>2,'role'=>'viewer'],'admin'=>['id'=>1,'role'=>'admin'],default=>null};}
}
PHP);
 file_put_contents($fixture.'/private/app/Security/DashboardPermissionService.php',"<?php final class DashboardPermissionService{public function __construct(PDO \$db){}}\n");
 $run=function(string $mode,string $script='/admin/test.php',string $role='viewer',string $method='GET')use($fixture):array{
  $code='define("BMA_HANDLER_CASE",'.var_export($mode,true).');http_response_code(200);$_SERVER["SCRIPT_NAME"]='.var_export($script,true).';$_SERVER["REQUEST_METHOD"]='.var_export($method,true).';register_shutdown_function(function(){echo "|STATUS=".http_response_code()."|LOGS=".(class_exists("Security",false)?Security::$calls:0);});require '.var_export($fixture.'/public/admin/_common.php',true).';adminConfig('.var_export($role,true).');echo "ACCESS_OK";';
  $p=proc_open([PHP_BINARY,'-d','display_errors=1','-d','log_errors=0','-r',$code],[1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($p))throw new RuntimeException('Fixture process unavailable');$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);proc_close($p);return [$out,$err];
 };
 foreach(['configuration'=>0,'boot'=>1,'logging'=>1,'database'=>1,'provider'=>1] as $mode=>$calls){[$out,$err]=$run($mode);adminErrorCheck($out==='Application access is unavailable.|STATUS=503|LOGS='.$calls&&$err==='','generic 503 without exception disclosure: '.$mode);}
 [$out,$err]=$run('anonymous','/api/test.php');adminErrorCheck($out==='{"error":"Sign in required."}|STATUS=401|LOGS=0'&&$err==='','unauthenticated API retains JSON 401');
 [$out,$err]=$run('anonymous');adminErrorCheck($out==='|STATUS=303|LOGS=0'&&$err==='','unauthenticated page retains login redirect');
 [$out,$err]=$run('viewer','/admin/test.php','admin');adminErrorCheck($out==='Administrator access is required.|STATUS=403|LOGS=0'&&$err==='','viewer retains administrator 403 denial');
 [$out,$err]=$run('admin','/admin/test.php','admin');adminErrorCheck($out==='ACCESS_OK|STATUS=200|LOGS=0'&&$err==='','administrator guard success path unchanged');
 [$out,$err]=$run('admin','/admin/test.php','admin','DELETE');adminErrorCheck($out==='Unsupported request method.|STATUS=405|LOGS=0'&&$err==='','method restriction unchanged');
 $apache=file_get_contents($root.'/public/admin/.htaccess');adminErrorCheck(str_contains($apache,'Options -Indexes')&&!preg_match('/^\s*(?:AuthType|Require\s+valid-user)\b/im',$apache),'admin Apache default disables indexing without mandatory Basic Auth');
 echo 'Admin/bootstrap checks: '.$checks.PHP_EOL;
}finally{
 if(is_dir($fixture)){
  if(realpath(dirname($fixture))!==realpath(sys_get_temp_dir())||!str_starts_with(basename($fixture),'bma-admin-errors-'))throw new RuntimeException('Unsafe fixture cleanup');
  foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry){if($entry->isDir())rmdir($entry->getPathname());else unlink($entry->getPathname());}rmdir($fixture);
 }
}

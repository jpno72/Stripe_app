<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/src/database.php';
final class Security
{
    public static function https(): bool {return in_array(strtolower((string)($_SERVER['HTTPS']??'')),['on','1'],true)||(string)($_SERVER['SERVER_PORT']??'')==='443';}
    public static function boot(array $config): void
    {
        $originalBuffer=ob_get_level();ob_start();
        ini_set('display_errors','0');ini_set('log_errors','0');ini_set('zend.exception_ignore_args','1');
        set_exception_handler(static function(Throwable $error) use($config,$originalBuffer):void{self::log($config,'UNHANDLED_ERROR');while(ob_get_level()>$originalBuffer)ob_end_clean();http_response_code(500);header('Content-Type: text/plain; charset=utf-8');echo 'The application could not complete this request.';});
        set_error_handler(static function(int $severity) use($config):bool{if(error_reporting()&$severity)self::log($config,'PHP_ERROR');return true;});
        register_shutdown_function(static function() use($config,$originalBuffer):void{$error=error_get_last();if($error&&in_array($error['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true)){self::log($config,'FATAL_ERROR');while(ob_get_level()>$originalBuffer)ob_end_clean();http_response_code(500);header('Content-Type: text/plain; charset=utf-8');echo 'The application could not complete this request.';}});
        header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');header('Referrer-Policy: no-referrer');header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.jsdelivr.net https://cdn.datatables.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdn.datatables.net; img-src 'self' data:; font-src 'self' data: https://cdn.jsdelivr.net https://cdn.datatables.net; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'; object-src 'none'");
        if(self::https()){header('Strict-Transport-Security: max-age=31536000');}
        $environment=(string)($config['APP_ENV']??'production');
        if(!self::https()&& !in_array($environment,['test','development'],true)){http_response_code(403);exit('HTTPS is required.');}
        if(!self::https()&& !in_array((string)($_SERVER['REMOTE_ADDR']??''),['127.0.0.1','::1'],true)){http_response_code(403);exit('HTTPS is required.');}
        $key=(string)($config['APP_KEY']??'');if(strlen($key)<32||str_contains($key,'REPLACE')||str_contains($key,'CHANGE_ME'))throw new RuntimeException('Application key must be configured.');
        if($environment==='production')self::privateLogPath($config);
        if(session_status()!==PHP_SESSION_ACTIVE){$save=(string)($config['SESSION_SAVE_PATH']??'');if($save!==''){session_save_path(dirname(self::privateLogPath(['APP_ERROR_LOG'=>rtrim($save,'/\\').DIRECTORY_SEPARATOR.'session-check'])));}elseif($environment==='production'){throw new RuntimeException('Private session directory required.');}$name=(string)($config['SESSION_COOKIE_NAME']??'bma_session');if(!preg_match('/\A[a-zA-Z][a-zA-Z0-9_]{0,63}\z/D',$name))throw new RuntimeException('Invalid session configuration.');session_name($name);ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');ini_set('session.use_trans_sid','0');session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>self::https(),'httponly'=>true,'samesite'=>'Strict']);if(!session_start())throw new RuntimeException('Session unavailable.');}
        $_SESSION['csrf']??=bin2hex(random_bytes(32));
        // Existing forms keep their field names, with one shared token lifecycle.
        $_SESSION['import_csrf']=$_SESSION['csrf'];$_SESSION['match_review_csrf']=$_SESSION['csrf'];
    }
    public static function csrf(mixed $token): void {if(!is_string($token)||!isset($_SESSION['csrf'])||!hash_equals($_SESSION['csrf'],$token)){http_response_code(403);exit('Invalid request.');}}
    public static function privateLogPath(array $config): string
    {
        $path=(string)($config['APP_ERROR_LOG']??'');$directory=realpath(dirname($path));$public=realpath(dirname(__DIR__,2).'/public');
        if($path===''||$directory===false||!is_writable($directory)||$public===false||is_link($path))throw new RuntimeException('Private error log required.');
        $normalized=strtolower(str_replace('\\','/',$directory));$roots=[$public];if(!empty($_SERVER['DOCUMENT_ROOT']))$roots[]=$_SERVER['DOCUMENT_ROOT'];
        foreach($roots as $root){$resolved=realpath($root);if($resolved&&str_starts_with($normalized.'/',strtolower(str_replace('\\','/',$resolved)).'/'))throw new RuntimeException('Error log must be private.');}
        return $directory.DIRECTORY_SEPARATOR.basename($path);
    }
    public static function log(array $config,string $code): void
    {
        if(!preg_match('/\A[A-Z_]{1,50}\z/D',$code))return;
        try{$path=self::privateLogPath($config);$line=json_encode(['at'=>gmdate('c'),'code'=>$code],JSON_THROW_ON_ERROR).PHP_EOL;$stream=@fopen($path,'ab');if($stream){@chmod($path,0600);if(flock($stream,LOCK_EX)){fwrite($stream,$line);flock($stream,LOCK_UN);}fclose($stream);}}catch(Throwable){} // Never fall back to exception text or request dumps.
    }
    public static function audit(PDO $db,?int $actor,string $action,?string $entity=null,array $metadata=[]): void
    {
        $db->prepare('INSERT INTO audit_log(actor_user_id,action,entity_type,entity_id,metadata) VALUES (?,?,?,?,?)')->execute([$actor,$action,'application_security',$entity,json_encode($metadata,JSON_THROW_ON_ERROR)]);
    }
}

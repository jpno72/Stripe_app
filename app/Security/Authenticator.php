<?php
declare(strict_types=1);
require_once __DIR__.'/Security.php';require_once __DIR__.'/Passwords.php';
final class Authenticator
{
    public function __construct(private PDO $db,private array $config){}
    public function current(): ?array
    {
        $auth=$_SESSION['auth']??null;if(!is_array($auth))return null;$now=time();$idle=max(60,(int)($this->config['SESSION_IDLE_SECONDS']??1800));$absolute=max($idle,(int)($this->config['SESSION_ABSOLUTE_SECONDS']??28800));
        if($now-(int)($auth['last_seen']??0)>$idle||$now-(int)($auth['started']??0)>$absolute){$this->clear();return null;}
        $q=$this->db->prepare('SELECT id,email,display_name,role,is_active,auth_version FROM app_users WHERE id=?');$q->execute([(int)($auth['id']??0)]);$user=$q->fetch();
        if(!$user||!$user['is_active']||(string)$user['auth_version']!==(string)($auth['version']??'')){ $this->clear();return null;}
        $_SESSION['auth']['last_seen']=$now;return $user;
    }
    private function clear(): void {$_SESSION=[];if(!session_regenerate_id(true))throw new RuntimeException('Session rotation failed.');$_SESSION['csrf']=bin2hex(random_bytes(32));}
    private function reserve(string $email,string $ip): bool
    {
        $window=max(60,(int)($this->config['LOGIN_WINDOW_SECONDS']??900));$buckets=[hash_hmac('sha256','email:'.$email,$this->config['APP_KEY'])=>max(1,(int)($this->config['LOGIN_MAX_ATTEMPTS']??5)),hash_hmac('sha256','ip:'.$ip,$this->config['APP_KEY'])=>max(1,(int)($this->config['LOGIN_IP_MAX_ATTEMPTS']??30))];ksort($buckets);$this->db->beginTransaction();
        try{$allowed=true;foreach($buckets as $hash=>$limit){$this->db->prepare('INSERT IGNORE INTO auth_throttle(bucket_hash,window_started_at,attempt_count) VALUES (?,UTC_TIMESTAMP(),0)')->execute([$hash]);$q=$this->db->prepare('SELECT attempt_count,TIMESTAMPDIFF(SECOND,window_started_at,UTC_TIMESTAMP()) age FROM auth_throttle WHERE bucket_hash=? FOR UPDATE');$q->execute([$hash]);$row=$q->fetch();if((int)$row['age']>=$window){$this->db->prepare('UPDATE auth_throttle SET window_started_at=UTC_TIMESTAMP(),attempt_count=0 WHERE bucket_hash=?')->execute([$hash]);$row['attempt_count']=0;}if((int)$row['attempt_count']>=$limit)$allowed=false;}
        if($allowed)foreach($buckets as $hash=>$limit)$this->db->prepare('UPDATE auth_throttle SET attempt_count=attempt_count+1 WHERE bucket_hash=?')->execute([$hash]);$this->db->commit();return $allowed;}catch(Throwable $error){$this->db->rollBack();throw $error;}
    }
    public function login(string $email,#[SensitiveParameter] string $password,string $ip): bool
    {
        $email=mb_strtolower(trim(mb_substr($email,0,254)),'UTF-8');$ip=filter_var($ip,FILTER_VALIDATE_IP)?$ip:'unknown';
        if(!$this->reserve($email,$ip)){Security::audit($this->db,null,'login_throttled');return false;}
        $q=$this->db->prepare('SELECT * FROM app_users WHERE email_normalized=?');$q->execute([$email]);$user=$q->fetch();
        // A dummy hash is generated only for unknown accounts; never a usable application credential.
        $dummyHash=password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT);$hash=$user['password_hash']??$dummyHash;
        $valid=strlen($password)<=72&&!str_contains($password,"\0")&&password_verify($password,$hash);
        if(!$valid||!$user||!$user['is_active']){Security::audit($this->db,null,'login_failed');return false;}
        $this->db->beginTransaction();try{$lock=$this->db->prepare('SELECT auth_version,is_active,password_hash FROM app_users WHERE id=? FOR UPDATE');$lock->execute([$user['id']]);$current=$lock->fetch();if(!$current||!$current['is_active']||$current['password_hash']!==$user['password_hash']){$this->db->rollBack();return false;}
        if(password_needs_rehash($hash,PASSWORD_DEFAULT))$this->db->prepare('UPDATE app_users SET password_hash=? WHERE id=?')->execute([Passwords::hash($password),$user['id']]);
        $this->db->prepare('UPDATE app_users SET last_login_at=UTC_TIMESTAMP(6) WHERE id=?')->execute([$user['id']]);Security::audit($this->db,(int)$user['id'],'login_succeeded',(string)$user['id']);$this->db->commit();}catch(Throwable $error){$this->db->rollBack();throw $error;}
        // Release only the reservations made by this successful request, preserving other failures.
        foreach(['email:'.$email,'ip:'.$ip] as $bucket)$this->db->prepare('UPDATE auth_throttle SET attempt_count=GREATEST(attempt_count,1)-1 WHERE bucket_hash=?')->execute([hash_hmac('sha256',$bucket,$this->config['APP_KEY'])]);
        $_SESSION=[];if(!session_regenerate_id(true))throw new RuntimeException('Session rotation failed.');$_SESSION['csrf']=bin2hex(random_bytes(32));$_SESSION['auth']=['id'=>(int)$user['id'],'version'=>$current['auth_version'],'started'=>time(),'last_seen'=>time()];return true;
    }
    public function logout(): void
    {
        $user=$this->current();if($user)Security::audit($this->db,(int)$user['id'],'logout',(string)$user['id']);$_SESSION=[];$params=session_get_cookie_params();setcookie(session_name(),'', ['expires'=>time()-42000,'path'=>$params['path'],'secure'=>$params['secure'],'httponly'=>true,'samesite'=>'Strict']);session_destroy();
    }
}

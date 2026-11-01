<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
ob_start();ini_set('zend.exception_ignore_args','1');session_start();
require_once dirname(__DIR__).'/app/Members/MemberProfileService.php';
require_once dirname(__DIR__).'/app/Views/partials/member-emergency-contact.php';
require_once dirname(__DIR__).'/src/People.php';
require_once dirname(__DIR__).'/src/IdentityAssociations.php';
require_once dirname(__DIR__).'/src/Migrations.php';
$checks=0;
function emergencyCheck(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException('FAIL '.$label);++$checks;echo 'PASS '.$label.PHP_EOL;}
function emergencyHtml(array $waiver):string{ob_start();uiMemberEmergencyContact($waiver);return ob_get_clean();}
foreach(['(570) 555-0123 ext. 42'=>'tel:5705550123;ext=42','+1 (570) 555-0123 x42'=>'tel:+15705550123;ext=42','570-555-0123;ext=42'=>'tel:5705550123;ext=42','570.555.0123'=>'tel:5705550123'] as $raw=>$uri)emergencyCheck(uiEmergencyPhoneHref($raw)===$uri,'validated telephone formatting/extension yields dialing URI');
foreach(['javascript:alert(1)','5705550123" onclick="alert(1)','5705550123%0d%0a','5705550123,123','5705550123;phone-context=evil','(5705550123','123',"5705550123\nX"] as $bad)emergencyCheck(uiEmergencyPhoneHref($bad)===null,'unsafe/ambiguous phone remains unlinked');
$name="O’Connor & Élise <img src=x onerror=alert(1)>";$phone=' +1 (570) 555-0123 ext. 42 ';
$html=emergencyHtml(['emergency_contact_name'=>$name,'emergency_contact_phone'=>$phone]);
emergencyCheck(str_contains($html,uiEscape($name))&&!str_contains($html,'<img')&&str_contains($html,'>'.uiEscape($phone).'</a>'),'source punctuation/accents/HTML-like text escaped; visible formatting unchanged');
emergencyCheck(str_contains(emergencyHtml(['emergency_contact_name'=>null,'emergency_contact_phone'=>null]),'No emergency contact information provided.'),'both fields missing has explicit empty state');
emergencyCheck(str_contains(emergencyHtml(['emergency_contact_name'=>null,'emergency_contact_phone'=>'570-555-0123']),'<dd>Not provided.</dd>'),'missing name has explicit empty state');
emergencyCheck(str_contains(emergencyHtml(['emergency_contact_name'=>'Fixture','emergency_contact_phone'=>null]),'<dd>Not provided.</dd>'),'missing phone has explicit empty state');
emergencyCheck(emergencyHtml([])==='','default unauthorized projection renders no contact section');
if(getenv('BMA_PROFILE_EMERGENCY_DB_TEST')!=='1'){echo 'SKIP integration: enable BMA_PROFILE_EMERGENCY_DB_TEST with localhost DB_* credentials.'.PHP_EOL;echo 'Emergency profile checks: '.$checks.PHP_EOL;ob_end_flush();exit;}
if(!in_array(getenv('DB_HOST'),['127.0.0.1','localhost'],true))throw new RuntimeException('Local disposable server required.');
final class EmergencyProfileTestPDO extends PDO
{
    public array $prepared=[];
    public function prepare(string $query,array $options=[]):PDOStatement|false{$this->prepared[]=$query;return parent::prepare($query,$options);}
}
$db=new EmergencyProfileTestPDO('mysql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT').';charset=utf8mb4',getenv('DB_USERNAME'),getenv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$database='bma_schema_test_profile_emergency_'.bin2hex(random_bytes(6));$directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.$database;mkdir($directory,0700);mkdir($directory.'/sessions',0700);
$db->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$db->exec('USE `'.$database.'`');$server=null;
$query=function(string $sql,array $values=[])use($db){$q=$db->prepare($sql);$q->execute($values);return $q;};
try{
 // Fresh throwaway fixture only. No migration runner, import or backfill is executed.
 foreach(Migrations::statements(file_get_contents(dirname(__DIR__).'/sql/schema.sql')) as $sql)$db->exec($sql);
 $db->exec("SET time_zone='+00:00'");
 $config=['APP_ENV'=>'test','PUBLIC_WEB_ROOT'=>dirname(__DIR__).'/public','APP_KEY'=>bin2hex(random_bytes(32)),
  'DB_HOST'=>(string)getenv('DB_HOST'),'DB_PORT'=>(string)getenv('DB_PORT'),'DB_DATABASE'=>$database,'DB_USERNAME'=>(string)getenv('DB_USERNAME'),'DB_PASSWORD'=>(string)getenv('DB_PASSWORD'),
  'APP_ERROR_LOG'=>$directory.'/application.log','SESSION_SAVE_PATH'=>$directory.'/sessions','SESSION_COOKIE_NAME'=>'bma_profile_fixture','APP_DISPLAY_TIMEZONE'=>'America/New_York'];
 $password=bin2hex(random_bytes(20));$users=[];
 foreach(['admin','staff','analyst','viewer'] as $role){$email=$role.'@example.invalid';$query('INSERT INTO app_users(email,email_normalized,display_name,password_hash,role) VALUES (?,?,?,?,?)',[$email,$email,'Profile '.ucfirst($role),password_hash($password,PASSWORD_DEFAULT),$role]);$users[$role]=(int)$db->lastInsertId();}
 $people=new People($db);$student=$people->create('Profile','Student');$sibling=$people->create('Other','Student');$emptyPerson=$people->create('No waiver','Student');$payer=$people->create('Profile','Payer');
 foreach([$student,$sibling,$emptyPerson] as $person)$query("INSERT INTO members(person_id,member_status) VALUES (?,'active')",[$person['id']]);
 $member=(int)$query('SELECT id FROM members WHERE person_id=?',[$student['id']])->fetchColumn();$emptyMember=(int)$query('SELECT id FROM members WHERE person_id=?',[$emptyPerson['id']])->fetchColumn();
 $identity=new IdentityAssociations($db);$waiverIds=[];
 $addWaiver=function(string $label,?string $contact,?string $number,?int $person,?string $status,string $role='student')use($query,$db,$identity,$users,&$waiverIds){
  $locator='cog-v1-'.hash('sha256','profile-'.$label);
  $query("INSERT INTO cognito_waivers(source_record_id,source_id_kind,form_namespace,source_locator,content_hash,emergency_contact_name,emergency_contact_phone) VALUES (?,'external','profile-fixture',?,?,?,?)",[$label,$locator,hash('sha256',$label),$contact,$number]);$waiverIds[$label]=(int)$db->lastInsertId();
  if($status==='possible')$query("INSERT INTO identity_links(person_id,source_system,source_type,external_id,source_role,match_method,match_status) VALUES (?,'cognito','waiver',?,?,'fixture','possible')",[$person,$locator,$role]);
  elseif($status!==null)$identity->decide('cognito','waiver',$locator,$role,$person,$status,$status==='automatic'?null:$users['admin']);
 };
 $addWaiver('accepted-one',$name,$phone,(int)$student['id'],'confirmed');
 $addWaiver('accepted-two','EMERGENCY_PROFILE_SECOND','570-555-0199',(int)$student['id'],'automatic');
 $addWaiver('missing-name',null,'570-555-0101',(int)$student['id'],'confirmed');
 $addWaiver('missing-phone','Name only',null,(int)$student['id'],'confirmed');
 $addWaiver('both-missing',null,null,(int)$student['id'],'confirmed');
 foreach(['possible','rejected',null] as $status)$addWaiver($status??'unresolved','NOT_FOR_THIS_MEMBER','570-555-0999',(int)$student['id'],$status);
 $addWaiver('other-member','OTHER_MEMBER_ONLY','570-555-0909',(int)$sibling['id'],'confirmed');
 $addWaiver('signer-only','SIGNER_ONLY_CONTACT','570-555-0998',(int)$student['id'],'confirmed','signer');
 foreach([$student,$sibling] as $child)$query("INSERT INTO person_relationships(person_id,related_person_id,relationship_type) VALUES (?,?,'payer')",[$payer['id'],$child['id']]);
 $query("INSERT INTO stripe_customers(stripe_customer_id,name) VALUES ('cus_ProfileFixture','Profile Payer')");$customer=(int)$db->lastInsertId();$identity->decide('stripe','customer','cus_ProfileFixture','customer',(int)$payer['id'],'confirmed',$users['admin']);
 foreach([['ch_ProfilePaid','paid',13000],['py_ProfileFailed','failed',99900]] as [$external,$status,$amount])$query("INSERT INTO stripe_transactions(stripe_charge_id,customer_id,amount_minor,amount_refunded_minor,currency,status,captured,stripe_created_at) VALUES (?,?,?,0,'usd',?,1,'2026-10-15 12:00:00')",[$external,$customer,$amount,$status]);
 $repo=new MemberProfileRepository($db);$service=new MemberProfileService($repo);$identifier=new MemberProfileIdentifier($member,null);
 $session=function(string $role)use($users){$_SESSION=['auth'=>['id'=>$users[$role],'version'=>1,'started'=>time(),'last_seen'=>time()]];};
 $protected=['people','members','person_relationships','identity_links','identity_match_candidates','cognito_waivers','stripe_customers','stripe_transactions','audit_log'];
 $hashTables=function()use($db,$protected){$out=[];foreach($protected as $table)$out[$table]=hash('sha256',json_encode($db->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll()));return $out;};$baseline=$hashTables();
 foreach(['admin','staff','analyst','viewer'] as $role){
  $session($role);$db->prepared=[];$data=$service->profile($identifier,'usd',$role==='admin',$config);$allowed=in_array($role,['admin','staff'],true);
  emergencyCheck(count($data['waivers'])===5,'only accepted student links appear for '.$role);
  emergencyCheck(array_key_exists('emergency_contact_name',$data['waivers'][0])===$allowed,'sensitive data projection matches canonical '.$role.' policy');
  emergencyCheck((bool)array_filter($db->prepared,fn($sql)=>str_contains($sql,'w.emergency_contact_name'))===$allowed,'emergency columns are not queried for unauthorized '.$role);
  emergencyCheck(MemberProfileAccess::canReview($db,$config)===($role==='admin'),'identity review remains admin-only for '.$role);
  if(!$allowed)emergencyCheck(!str_contains(json_encode($data),$name)&&!str_contains(json_encode($data),'EMERGENCY_PROFILE_SECOND'),'unauthorized service/JSON payload excludes emergency values');
 }
 $session('admin');$waivers=$repo->waivers((int)$student['id'],$config);$byId=array_column($waivers,null,'source_record_id');
 emergencyCheck($byId['accepted-one']['emergency_contact_name']===$name&&$byId['accepted-two']['emergency_contact_name']==='EMERGENCY_PROFILE_SECOND','multiple waivers keep distinct contacts under their exact source records');
 emergencyCheck(!str_contains(json_encode($waivers),'NOT_FOR_THIS_MEMBER')&&!str_contains(json_encode($waivers),'OTHER_MEMBER_ONLY')&&!str_contains(json_encode($waivers),'SIGNER_ONLY_CONTACT'),'possible/rejected/unlinked/other-member/signer-only contacts excluded');
 emergencyCheck($repo->waivers((int)$emptyPerson['id'],$config)===[],'member without accepted waiver retains empty state');
 emergencyCheck(!array_key_exists('emergency_contact_name',$repo->waivers((int)$student['id'])[0]),'repository default omits sensitive fields even for an administrator');
 $dashboard=(int)$query("SELECT id FROM dashboards WHERE dashboard_key='members'")->fetchColumn();
 foreach(['admin','staff'] as $role){$session($role);$query("INSERT INTO user_dashboard_overrides(user_id,dashboard_id,permission) VALUES (?,?,'DENY')",[$users[$role],$dashboard]);emergencyCheck(!MemberProfileAccess::canViewEmergencyContacts($db,$config)&&!array_key_exists('emergency_contact_name',$repo->waivers((int)$student['id'],$config)[0]),'Members user DENY blocks sensitive query for '.$role);$query('DELETE FROM user_dashboard_overrides WHERE user_id=?',[$users[$role]]);}
 $session('staff');$query("UPDATE role_dashboard_permissions SET can_view=0 WHERE role='staff' AND dashboard_id=?",[$dashboard]);emergencyCheck(!MemberProfileAccess::canViewEmergencyContacts($db,$config),'Members role denial blocks staff');
 $query("INSERT INTO user_dashboard_overrides(user_id,dashboard_id,permission) VALUES (?,?,'ALLOW')",[$users['staff'],$dashboard]);emergencyCheck(MemberProfileAccess::canViewEmergencyContacts($db,$config),'explicit staff Members ALLOW honors effective access');$query('DELETE FROM user_dashboard_overrides WHERE user_id=?',[$users['staff']]);$query("UPDATE role_dashboard_permissions SET can_view=1 WHERE role='staff' AND dashboard_id=?",[$dashboard]);
 $session('admin');$query('UPDATE dashboards SET is_enabled=0 WHERE id=?',[$dashboard]);emergencyCheck(!MemberProfileAccess::canViewEmergencyContacts($db,$config),'disabled Members blocks emergency data');$query('UPDATE dashboards SET is_enabled=1 WHERE id=?',[$dashboard]);
 $query("UPDATE app_users SET role='viewer' WHERE id=?",[$users['admin']]);emergencyCheck(!MemberProfileAccess::canViewEmergencyContacts($db,$config),'canonical database role overrides stale admin session');$query("UPDATE app_users SET role='admin' WHERE id=?",[$users['admin']]);
 $session('admin');$query('UPDATE app_users SET is_active=0 WHERE id=?',[$users['admin']]);emergencyCheck(!MemberProfileAccess::canViewEmergencyContacts($db,$config),'inactive current application user denied');$query('UPDATE app_users SET is_active=1 WHERE id=?',[$users['admin']]);
 $_SESSION=[];$_SERVER['REMOTE_USER']='admin';$_SERVER['HTTP_X_AUTHENTICATED_USER']='admin';emergencyCheck(!MemberProfileAccess::canViewEmergencyContacts($db,$config),'identity headers cannot replace an authenticated session');
 emergencyCheck($hashTables()===$baseline,'profile reads preserve all source/identity/member/payment/audit records');
 // Existing profile regression uses default redacted projection and the same disposable fixture database.
 $configPath=$directory.'/config.php';file_put_contents($configPath,'<?php return '.var_export($config,true).';');chmod($configPath,0600);
 foreach(['BMA_CONFIG_FILE'=>$configPath,'BMA_APP_ROOT'=>dirname(__DIR__),'DB_DATABASE'=>$database,'APP_ENV'=>'test','PUBLIC_WEB_ROOT'=>dirname(__DIR__).'/public'] as $key=>$value)putenv($key.'='.$value);
 foreach(['member-profile.php','dashboard-access.php'] as $test){$output=[];exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/'.$test),$output,$status);if($status!==0)echo implode(PHP_EOL,$output).PHP_EOL;emergencyCheck($status===0,'existing '.$test.' regression passed');echo end($output).PHP_EOL;}
 // Exercise the actual protected route with real application login; no authentication bypass fixture.
 $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$message);if(!$socket)throw new RuntimeException('Fixture port unavailable');$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);$url='http://127.0.0.1:'.$port;
 $config['APP_URL']=$url;file_put_contents($configPath,'<?php return '.var_export($config,true).';');
 $router=$directory.'/router.php';file_put_contents($router,'<?php $public='.var_export(realpath(dirname(__DIR__).'/public'),true).';'.<<<'ROUTER'
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)?:'/';if($path==='/')$path='/index.php';
$file=realpath($public.$path);if(!$file||!str_starts_with($file,$public.DIRECTORY_SEPARATOR)){http_response_code(404);return true;}
if(!str_ends_with($file,'.php'))return false;$_SERVER['SCRIPT_NAME']=$path;$_SERVER['SCRIPT_FILENAME']=$file;require $file;
ROUTER);
 $server=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',dirname(__DIR__).'/public',$router],[0=>['pipe','r'],1=>['file',$directory.'/server.log','a'],2=>['file',$directory.'/server.log','a']],$pipes,dirname(__DIR__));fclose($pipes[0]);
 $http=function(string $path,?string $cookie=null,?array $post=null,array $headers=[])use($url){$curl=curl_init($url.$path);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>$headers]);if($cookie!==null){curl_setopt($curl,CURLOPT_COOKIEFILE,$cookie);curl_setopt($curl,CURLOPT_COOKIEJAR,$cookie);}if($post!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,http_build_query($post));$response=curl_exec($curl);$code=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$size=curl_getinfo($curl,CURLINFO_HEADER_SIZE);curl_close($curl);return [$code,substr((string)$response,0,$size),substr((string)$response,$size)];};
 for($attempt=0;$attempt<50;++$attempt){[$status]=$http('/login.php');if($status===200)break;usleep(100000);}emergencyCheck($status===200,'isolated local application server starts');
 [$status,$headersOut,$body]=$http('/member.php?id='.$member,null,null,['REMOTE_USER: admin','X-Authenticated-User: admin']);emergencyCheck($status===303&&!str_contains($body,'EMERGENCY_PROFILE'),'unauthenticated direct URL and spoofed headers cannot obtain data');
 $login=function(string $role)use($http,$directory,$password){$cookie=$directory.'/'.$role.'.cookies';if(is_file($cookie))unlink($cookie);[$code,$headersOut,$body]=$http('/login.php',$cookie);preg_match('/name="csrf" value="([a-f0-9]+)"/',$body,$match);if(!isset($match[1]))throw new RuntimeException('Fixture login token unavailable');[$code]=$http('/login.php',$cookie,['csrf'=>$match[1],'email'=>$role.'@example.invalid','password'=>$password]);if($code!==303)throw new RuntimeException('Fixture login failed');return $cookie;};
 foreach(['admin','staff','analyst','viewer'] as $role){
  $cookie=$login($role);[$status,$headersOut,$body]=$http('/member.php?id='.$member,$cookie);$allowed=in_array($role,['admin','staff'],true);
  emergencyCheck($status===200&&str_contains($body,'EMERGENCY_PROFILE_SECOND')===$allowed,'actual HTML obeys '.$role.' emergency policy');
  emergencyCheck(str_contains(strtolower($headersOut),'cache-control: private, no-store'),'profile response forbids shared caching for '.$role);
  emergencyCheck(str_contains($body,'Identity &amp; review')===($role==='admin'),'identity-review HTML remains admin-only for '.$role);
  if($allowed){emergencyCheck(str_contains($body,uiEscape($name))&&!str_contains($body,'<img src=x onerror')&&str_contains($body,'tel:+15705550123;ext=42'),'actual HTML safely displays escaped emergency text and dialing extension');emergencyCheck(str_contains($body,'No emergency contact information provided.')&&str_contains($body,'Not provided.'),'actual page includes waiver-specific missing-data states');}
  [$code,$headerApi,$api]=$http('/api/member-transactions.php?id='.$member,$cookie);emergencyCheck($code===200&&!str_contains($api,'EMERGENCY_PROFILE')&&!str_contains($api,'emergency_contact'),'existing API never receives emergency data for '.$role);
  if($allowed){$query("INSERT INTO user_dashboard_overrides(user_id,dashboard_id,permission) VALUES (?,?,'DENY')",[$users[$role],$dashboard]);[$code,,$denied]=$http('/member.php?id='.$member,$cookie);emergencyCheck($code===403&&!str_contains($denied,'EMERGENCY_PROFILE'),'direct Members DENY returns 403 for '.$role);$query('DELETE FROM user_dashboard_overrides WHERE user_id=?',[$users[$role]]);}
  if($role==='staff'){[$code]=$http('/admin/matches.php',$cookie);emergencyCheck($code===403,'staff emergency access does not grant identity-review route access');}
 }
 $cookie=$login('admin');$query('UPDATE app_users SET is_active=0 WHERE id=?',[$users['admin']]);[$code,,$body]=$http('/member.php?id='.$member,$cookie);emergencyCheck($code===303&&!str_contains($body,'EMERGENCY_PROFILE'),'inactive session denied actual profile route');$query('UPDATE app_users SET is_active=1 WHERE id=?',[$users['admin']]);
 $cookie=$login('admin');[$code,,$body]=$http('/member.php?id='.$emptyMember,$cookie);emergencyCheck($code===200&&str_contains($body,'No accepted waiver link')&&!str_contains($body,'profile-emergency'),'no accepted association preserves profile empty state');
 // Optional local Playwright script, stored in the system temp directory, never in application files.
 if($script=getenv('BMA_PROFILE_EMERGENCY_BROWSER_SCRIPT')){
  $runner=getenv('BMA_PROFILE_EMERGENCY_BROWSER_RUNNER');if(!$runner||!is_file($runner)||!is_file($script))throw new RuntimeException('Browser runner unavailable');
  $metadata=$directory.'/browser.json';file_put_contents($metadata,json_encode(['url'=>$url,'member'=>$member,'password'=>$password,'screenshots'=>$directory],JSON_THROW_ON_ERROR));chmod($metadata,0600);putenv('BMA_PROFILE_BROWSER_METADATA='.$metadata);
  $output=[];exec('node '.escapeshellarg($runner).' '.escapeshellarg($script),$output,$status);emergencyCheck($status===0,'desktop/mobile Playwright checks passed');foreach($output as $line)if(str_starts_with($line,'BROWSER PASS'))echo $line.PHP_EOL;
 }
 $audit=json_encode($db->query('SELECT metadata FROM audit_log')->fetchAll());$log=is_file($config['APP_ERROR_LOG'])?file_get_contents($config['APP_ERROR_LOG']):'';
 emergencyCheck(!str_contains($audit,$name)&&!str_contains($audit,'EMERGENCY_PROFILE_SECOND')&&!str_contains($log,'EMERGENCY_PROFILE'),'emergency reads never place contact data in audit/error logs');
 echo 'Emergency profile checks: '.$checks.PHP_EOL;
}finally{
 if(is_resource($server)){proc_terminate($server);proc_close($server);}
 if($db->inTransaction())$db->rollBack();$db->exec('DROP DATABASE `'.$database.'`');
 foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry){if($entry->isDir())rmdir($entry->getPathname());else unlink($entry->getPathname());}rmdir($directory);session_destroy();ob_end_flush();
}

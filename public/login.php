<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/Security/Authenticator.php';require_once dirname(__DIR__).'/app/Views/components.php';
try{$config=bmaConfig();Security::boot($config);$db=bmaDatabase($config);$auth=new Authenticator($db,$config);if($auth->current()){header('Location: /index.php',true,303);exit;}}
catch(Throwable){if(isset($config))Security::log($config,'LOGIN_UNAVAILABLE');http_response_code(503);exit('Sign-in is unavailable.');}
$message=null;
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    Security::csrf($_POST['csrf']??null);$email=$_POST['email']??'';$password=$_POST['password']??'';
    try{if(is_string($email)&&is_string($password)&&$auth->login($email,$password,(string)($_SERVER['REMOTE_ADDR']??''))){header('Location: /index.php',true,303);exit;}$message='Unable to sign in. Check your credentials or try again later.';}
    catch(Throwable){Security::log($config,'LOGIN_UNAVAILABLE');http_response_code(503);$message='Unable to sign in. Please try again later.';}
}elseif(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){http_response_code(405);header('Allow: GET, POST');exit;}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Sign in · BMA Analytics</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="/assets/css/app.css" rel="stylesheet"><link href="/assets/css/auth.css" rel="stylesheet"></head><body class="auth-page"><main class="auth-panel card"><div class="card-body p-4 p-sm-5"><div class="brand-mark mb-4">B</div><div class="eyebrow">BMA ANALYTICS</div><h1 class="h3 mt-2">Sign in to your workspace</h1><p class="text-secondary">Internal academy reporting. Authorized team members only.</p><?php if($message)uiAlert($message); ?><form method="post" action="/login.php"><input type="hidden" name="csrf" value="<?=uiEscape($_SESSION['csrf'])?>"><div class="mb-3"><label for="email" class="form-label">Email</label><input id="email" name="email" type="email" maxlength="254" autocomplete="username" class="form-control" required></div><div class="mb-4"><label for="password" class="form-label">Password</label><input id="password" name="password" type="password" maxlength="72" autocomplete="current-password" class="form-control" required></div><button class="btn btn-primary w-100" type="submit">Sign in</button></form><p class="small text-secondary mt-4 mb-0">Contact your BMA administrator if you need access or a password reset.</p></div></main></body></html>

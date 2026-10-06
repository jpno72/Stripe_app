<?php
declare(strict_types=1);
require_once __DIR__.'/admin/_common.php';$config=adminConfig();
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);header('Allow: POST');exit('Use the sign-out form.');}
Security::csrf($_POST['csrf']??null);(new Authenticator(bmaDatabase($config),$config))->logout();header('Location: /login.php',true,303);exit;

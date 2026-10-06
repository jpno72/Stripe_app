<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/src/database.php';require dirname(__DIR__).'/app/Security/UserManagement.php';
$options=getopt('',['email:','name:','role:','password-stdin','reset','config:','help']);
if(isset($options['help'])||!isset($options['email'],$options['password-stdin'])){echo "Usage: php bin/create-user.php --email=... --name=... --role=admin|viewer --password-stdin [--config=/private/config.php]\nReset: php bin/create-user.php --email=... --reset --password-stdin [--config=...]\nRead a password from standard input, never from command-line arguments. No password is printed.\n";exit(isset($options['help'])?0:1);}
try{$input=fgets(STDIN,1024);if($input===false)throw new RuntimeException();$password=rtrim($input,"\r\n");$config=bmaConfig(isset($options['config'])?(string)$options['config']:null);$service=new UserManagement(bmaDatabase($config));if(isset($options['reset']))$service->reset((string)$options['email'],$password);else $service->create((string)$options['email'],(string)($options['name']??''),(string)($options['role']??'viewer'),$password);unset($password,$input);echo "Account saved. No credentials printed.\n";}catch(Throwable){fwrite(STDERR,"Account operation failed. Check account details, password policy and private configuration.\n");exit(1);}

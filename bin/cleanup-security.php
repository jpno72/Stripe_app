<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/src/database.php';
try{$config=bmaConfig();$seconds=max(172800,2*(int)($config['LOGIN_WINDOW_SECONDS']??900));$db=bmaDatabase($config);$q=$db->prepare('DELETE FROM auth_throttle WHERE window_started_at < DATE_SUB(UTC_TIMESTAMP(),INTERVAL ? SECOND)');$q->execute([$seconds]);echo 'Expired throttle buckets removed: '.$q->rowCount().PHP_EOL;}catch(Throwable){fwrite(STDERR,"Security cleanup unavailable. Check private configuration.\n");exit(1);}

<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/Security/Authenticator.php';
final class MemberProfileAccess
{
    public static function canReview(PDO $db,array $config,string $unusedLegacyUser=''): bool
    {
        $user=(new Authenticator($db,$config))->current();return $user!==null&&$user['role']==='admin';
    }
}

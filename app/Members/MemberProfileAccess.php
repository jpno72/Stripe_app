<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/Security/Authenticator.php';
require_once dirname(__DIR__).'/Security/CurrentUserProvider.php';
require_once dirname(__DIR__).'/Security/DashboardPermissionService.php';
final class MemberProfileAccess
{
    /** Emergency viewing is independent of administrator-only identity review. */
    public static function canViewEmergencyContacts(PDO $db,array $config): bool
    {
        $user=(new SessionCurrentUserProvider($db,$config))->current();
        return $user!==null && in_array($user['role'],['admin','staff'],true)
            && (new DashboardPermissionService($db))->canViewDashboard($user,'members');
    }

    public static function canReview(PDO $db,array $config,string $unusedLegacyUser=''): bool
    {
        $user=(new Authenticator($db,$config))->current();return $user!==null&&$user['role']==='admin';
    }
}

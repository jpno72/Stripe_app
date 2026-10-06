<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/Import/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/Views/layout.php';
require_once dirname(__DIR__, 2) . '/app/Security/CurrentUserProvider.php';
require_once dirname(__DIR__, 2) . '/app/Security/DashboardPermissionService.php';

/** All protected pages use this guard. Administrative routes explicitly require admin. */
function adminConfig(string $requiredRole='viewer'): array
{
    try {$config=bmaConfig();Security::boot($config);$db=bmaDatabase($config);$user=(new SessionCurrentUserProvider($db,$config))->current();}
    catch(Throwable){if(isset($config))Security::log($config,'ACCESS_UNAVAILABLE');http_response_code(503);exit('Application access is unavailable.');}
    if(!$user){if(str_starts_with((string)($_SERVER['SCRIPT_NAME']??''),'/api/')){http_response_code(401);header('Content-Type: application/json');exit('{"error":"Sign in required."}');}header('Location: /login.php',true,303);exit;}
    if($requiredRole==='admin'&&$user['role']!=='admin'){http_response_code(403);exit('Administrator access is required.');}
    if(!in_array((string)($_SERVER['REQUEST_METHOD']??'GET'),['GET','POST'],true)){http_response_code(405);header('Allow: GET, POST');exit('Unsupported request method.');}
    $GLOBALS['bma_current_user']=$user;
    $GLOBALS['bma_dashboard_permissions']=new DashboardPermissionService($db);
    $_SESSION['import_csrf']=$_SESSION['csrf'];$_SESSION['match_review_csrf']=$_SESSION['csrf'];
    return $config;
}
function e(mixed $value): string {return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function adminReviewActor(PDO $db,array $config): int
{
    $user=(new SessionCurrentUserProvider($db,$config))->current();if(!$user||$user['role']!=='admin'){http_response_code(403);exit('Administrator access is required.');}return (int)$user['id'];
}

function adminHead(string $title): void
{
    $active = basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === 'matches.php' ? 'matches' : 'imports';
    uiLayoutStart($title, $active);
    echo '<div class="admin-page">';
}

function adminFoot(): void { echo '</div>'; uiLayoutEnd(); }

function requireDashboardAccess(string $key): void
{
    $service=$GLOBALS['bma_dashboard_permissions']??null;
    if(!$service instanceof DashboardPermissionService){http_response_code(403);exit('Dashboard access denied.');}
    $service->requireDashboardAccess($GLOBALS['bma_current_user']??[],$key);
}
function canViewDashboard(string $key): bool
{
    $service=$GLOBALS['bma_dashboard_permissions']??null;
    return $service instanceof DashboardPermissionService && $service->canViewDashboard($GLOBALS['bma_current_user']??[],$key);
}

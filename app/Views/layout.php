<?php
declare(strict_types=1);
require_once __DIR__ . '/components.php';

function uiNavigation(): array
{
    return [
        'dashboard' => ['Dashboard', '/index.php', 'grid'],
        'revenue' => ['Revenue', '/revenue', 'trend'],
        'membership' => ['Membership', '/membership', 'users'],
        'demographics' => ['Demographics', '/demographics', 'pie'],
        'members' => ['Members', '/index.php?page=members', 'user'],
        'transactions' => ['Transactions', '/index.php?page=transactions', 'card'],
        'waivers' => ['Waivers', '/index.php?page=waivers', 'file'],
        'reports' => ['Reports', '/index.php?page=reports', 'chart'],
        'imports' => ['Imports', '/admin/imports.php', 'upload'],
        'matches' => ['Matches', '/admin/matches.php', 'link'],
        'settings' => ['Settings', '/admin/settings.php', 'settings'],
        'users' => ['User management', '/admin/users.php', 'users'],
    ];
}

function uiIcon(string $name): string
{
    $paths = [
        'grid'=>'<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'trend'=>'<path d="M3 17l6-6 4 4 8-8"/><path d="M15 7h6v6"/>',
        'users'=>'<circle cx="9" cy="8" r="3"/><path d="M3 20v-2a6 6 0 0 1 12 0v2"/><path d="M17 5a3 3 0 0 1 0 6M17 14a5 5 0 0 1 4 5v1"/>',
        'pie'=>'<path d="M12 3v9h9"/><path d="M20.5 15a9 9 0 1 1-11-11"/>',
        'user'=>'<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>',
        'card'=>'<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h4"/>',
        'file'=>'<path d="M6 2h8l4 4v16H6z"/><path d="M14 2v5h5M9 12h6M9 16h6"/>',
        'chart'=>'<path d="M4 20V4M4 20h17M8 16v-5M13 16V7M18 16v-9"/>',
        'upload'=>'<path d="M12 16V3m-4 4 4-4 4 4"/><path d="M4 16v4h16v-4"/>',
        'link'=>'<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-2 2"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l2-2"/>',
        'settings'=>'<circle cx="12" cy="12" r="3"/><path d="M12 2v3m0 14v3M2 12h3m14 0h3M4.9 4.9l2.2 2.2m9.8 9.8 2.2 2.2m0-14.2-2.2 2.2M7.1 16.9l-2.2 2.2"/>',
    ];
    return '<svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? $paths['grid']) . '</svg>';
}

function uiLayoutStart(string $title, string $active, string $description = '', array $styles = []): void
{
    $allNavigation = uiNavigation();
    $navigation = [];
    $service = $GLOBALS['bma_dashboard_permissions'] ?? null;
    if ($service instanceof DashboardPermissionService) {
        foreach ($service->getVisibleDashboards($GLOBALS['bma_current_user'] ?? []) as $dashboard) {
            $key = $dashboard['dashboard_key'] === 'overview' ? 'dashboard' : $dashboard['dashboard_key'];
            if (isset($allNavigation[$key])) {
                $navigation[$key] = [$dashboard['display_name'], $dashboard['route'], $allNavigation[$key][2]];
            }
        }
    }
    $active = isset($allNavigation[$active]) ? $active : 'dashboard';
    $label = $allNavigation[$active][0];
    $user = (string) ($GLOBALS['bma_current_user']['display_name'] ?? 'BMA team');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="color-scheme" content="light"><title>' . uiEscape($title) . ' · BMA Analytics</title>';
    echo '<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">';
    echo '<link href="/assets/css/app.css" rel="stylesheet">';
    foreach ($styles as $style) { echo '<link href="' . uiEscape($style) . '" rel="stylesheet">'; }
    echo '</head><body><div class="app-shell">';
    require __DIR__ . '/partials/sidebar.php';
    echo '<div class="app-main">';
    require __DIR__ . '/partials/header.php';
    echo '<main class="main-content" id="main-content"><div class="content-wrap">';
    if ($description !== '') { uiPageHeader($label, $title, $description, 'As of ' . gmdate('M j, Y') . ' UTC'); }
}

function uiLayoutEnd(): void
{
    echo '</div></main><footer class="app-footer">Bloomsburg Martial Arts Academy <span aria-hidden="true">·</span> Internal analytics</footer></div></div>';
    echo '<div class="sidebar-backdrop" data-sidebar-close></div>';
    echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer></script><script src="/assets/js/app.js" defer></script></body></html>';
}

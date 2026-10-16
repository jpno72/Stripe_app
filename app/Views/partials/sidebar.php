<?php
declare(strict_types=1);
echo '<aside class="app-sidebar" id="app-sidebar" aria-label="Main navigation"><div class="sidebar-inner">';
echo '<a class="brand" href="/index.php" aria-label="BMA Analytics home"><span class="brand-mark" aria-hidden="true">B</span><span class="brand-type"><strong>BMA</strong><small>ANALYTICS</small></span></a>';
echo '<div class="sidebar-label">WORKSPACE</div><nav class="sidebar-nav" aria-label="Primary">';
foreach ($navigation as $key => [$navLabel, $href, $icon]) {
    if ($key === 'revenue') { continue; }
    if ($key === 'imports') { echo '<div class="nav-divider" role="separator"></div><div class="sidebar-label sidebar-label-tools">ADMINISTRATION</div>'; }
    echo '<a class="nav-item' . ($active === $key ? ' active' : '') . '" href="' . uiEscape($href) . '"' . ($active === $key ? ' aria-current="page"' : '') . '>' . uiIcon($icon) . '<span>' . uiEscape($navLabel) . '</span></a>';
}
echo '</nav><div class="sidebar-foot"><span class="sidebar-foot-dot"></span><span>Internal workspace</span></div></div></aside>';

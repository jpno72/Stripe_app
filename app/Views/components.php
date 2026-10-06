<?php
declare(strict_types=1);

function uiEscape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function uiFormatMinorUnits(mixed $value): string
{
    $raw = (string) $value;
    if (!preg_match('/\A-?\d+(?:\.0+)?\z/D', $raw)) { throw new InvalidArgumentException('Expected an integer minor-unit amount.'); }
    $integer = explode('.', $raw, 2)[0];
    return preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $integer);
}

function uiPageHeader(string $section, string $title, string $description, ?string $aside = null): void
{
    echo '<div class="page-intro"><div><div class="eyebrow">BMA ANALYTICS / ' . uiEscape(strtoupper($section)) . '</div><h1>' . uiEscape($title) . '</h1><p>' . uiEscape($description) . '</p></div>';
    if ($aside !== null) { echo '<span class="as-of">' . uiEscape($aside) . '</span>'; }
    echo '</div>';
}

function uiKpiCard(string $label, string $value, string $detail, string $icon = '◉'): void
{
    echo '<article class="metric-card card h-100"><div class="card-body">';
    echo '<div class="metric-top"><span class="metric-label">' . uiEscape($label) . '</span><span class="metric-icon" aria-hidden="true">' . uiEscape($icon) . '</span></div>';
    echo '<div class="metric-value">' . uiEscape($value) . '</div><p class="metric-detail mb-0">' . uiEscape($detail) . '</p>';
    echo '</div></article>';
}

function uiCardStart(string $title, ?string $subtitle = null, string $class = ''): void
{
    echo '<section class="surface-card card ' . uiEscape($class) . '"><div class="card-body"><div class="section-heading"><div><h2 class="h5 mb-1">' . uiEscape($title) . '</h2>';
    if ($subtitle !== null) { echo '<p class="text-secondary small mb-0">' . uiEscape($subtitle) . '</p>'; }
    echo '</div></div>';
}

function uiCardEnd(): void { echo '</div></section>'; }

function uiTableCardStart(string $title, string $subtitle, array $headers, string $class = ''): void
{
    uiCardStart($title, $subtitle, 'table-card ' . $class);
    echo '<div class="table-responsive"><table class="table align-middle"><thead><tr>';
    foreach ($headers as $header) { echo '<th scope="col">' . uiEscape($header) . '</th>'; }
    echo '</tr></thead><tbody>';
}

function uiTableCardEnd(): void { echo '</tbody></table></div>'; uiCardEnd(); }

function uiChartCard(string $title, string $message): void
{
    uiChartCardStart($title, 'A chart area for this report');
    echo '<div class="chart-placeholder"><span class="chart-placeholder-icon" aria-hidden="true">▥</span><p class="fw-semibold mb-1">' . uiEscape($message) . '</p><p class="small text-secondary mb-0">Available when the report is built.</p></div>';
    uiChartCardEnd();
}

function uiChartCardStart(string $title, ?string $subtitle = null): void
{
    uiCardStart($title, $subtitle, 'h-100 chart-card');
    echo '<div class="chart-content">';
}

function uiChartCardEnd(): void
{
    echo '</div>';
    uiCardEnd();
}

function uiEmptyState(string $title, string $description, ?string $href = null, ?string $linkLabel = null): void
{
    echo '<div class="empty-state"><div class="empty-state-mark" aria-hidden="true">◇</div><h2 class="h5">' . uiEscape($title) . '</h2><p class="text-secondary mb-0">' . uiEscape($description) . '</p>';
    if ($href !== null && $linkLabel !== null) { echo '<a class="btn btn-primary mt-3" href="' . uiEscape($href) . '">' . uiEscape($linkLabel) . '</a>'; }
    echo '</div>';
}

function uiStatusBadge(string $label, string $tone = 'neutral'): void
{
    $allowed = ['neutral', 'success', 'warning', 'danger', 'info'];
    if (!in_array($tone, $allowed, true)) { $tone = 'neutral'; }
    echo '<span class="status-badge status-' . $tone . '"><span class="status-dot" aria-hidden="true"></span>' . uiEscape($label) . '</span>';
}

function uiAlert(string $message, string $tone = 'danger'): void
{
    $tone = in_array($tone, ['danger', 'warning', 'info', 'success'], true) ? $tone : 'danger';
    echo '<div class="alert alert-' . $tone . '" role="alert">' . uiEscape($message) . '</div>';
}

function uiLoadingIndicator(string $label = 'Loading'): void
{
    echo '<div class="loading-indicator" role="status"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span><span>' . uiEscape($label) . '</span></div>';
}

function uiPagination(int $page, int $totalPages, string $baseUrl): void
{
    if ($totalPages <= 1) { return; }
    $separator = str_contains($baseUrl, '?') ? '&' : '?';
    echo '<nav aria-label="Pages" class="d-flex align-items-center gap-2">';
    if ($page > 1) { echo '<a class="btn btn-outline-secondary btn-sm" href="' . uiEscape($baseUrl . $separator . 'page=' . ($page - 1)) . '">Previous</a>'; }
    echo '<span class="small text-secondary">Page ' . uiEscape($page) . ' of ' . uiEscape($totalPages) . '</span>';
    if ($page < $totalPages) { echo '<a class="btn btn-outline-secondary btn-sm" href="' . uiEscape($baseUrl . $separator . 'page=' . ($page + 1)) . '">Next</a>'; }
    echo '</nav>';
}

function uiModal(string $id, string $title, string $body, string $confirmLabel = 'Continue'): void
{
    if (!preg_match('/\A[a-z][a-z0-9-]*\z/D', $id)) { throw new InvalidArgumentException('Invalid modal ID.'); }
    echo '<div class="modal fade" id="' . $id . '" tabindex="-1" aria-labelledby="' . $id . '-title" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">';
    echo '<div class="modal-header"><h2 class="modal-title h5" id="' . $id . '-title">' . uiEscape($title) . '</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>';
    echo '<div class="modal-body">' . uiEscape($body) . '</div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" data-modal-confirm="' . $id . '">' . uiEscape($confirmLabel) . '</button></div>';
    echo '</div></div></div>';
}

function uiFilterBar(bool $disabled = true, array $fields = ['start', 'end', 'program', 'status', 'city', 'age']): void
{
    $attribute = $disabled ? ' disabled' : '';
    echo '<form class="filter-bar" aria-label="Report filters"><div class="row g-3 align-items-end">';
    $labels = ['start' => 'From', 'end' => 'To', 'program' => 'Program', 'status' => 'Membership status', 'city' => 'City', 'age' => 'Age group'];
    foreach ($fields as $id) {
        if (!is_string($id) || !isset($labels[$id])) { throw new InvalidArgumentException('Unknown filter field.'); }
        echo '<div class="col-6 col-md-3 col-xl-2"><label for="filter-' . $id . '" class="form-label">' . $labels[$id] . '</label>';
        if (in_array($id, ['start', 'end'], true)) {
            echo '<input id="filter-' . $id . '" name="' . $id . '" type="date" class="form-control"' . $attribute . '>';
        } else {
            echo '<select id="filter-' . $id . '" name="' . $id . '" class="form-select"' . $attribute . '><option value="">All</option></select>';
        }
        echo '</div>';
    }
    echo '</div></form>';
}

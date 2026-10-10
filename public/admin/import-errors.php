<?php
declare(strict_types=1);
require_once __DIR__ . '/_common.php';
$config = adminConfig('admin');requireDashboardAccess('imports');if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){http_response_code(405);header('Allow: GET');exit('Read-only page.');} $db = bmaDatabase($config);
$batch = filter_input(INPUT_GET, 'batch', FILTER_VALIDATE_INT);
if (!$batch || $batch < 1) { http_response_code(400); exit('Invalid batch.'); }
$q = $db->prepare('SELECT id, source_type, filename, source_headers, import_options FROM import_batches WHERE id = ?'); $q->execute([$batch]);
$batchRow = $q->fetch();
if (!$batchRow) { http_response_code(404); exit('Batch not found.'); }
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
if ($page < 1 || $page > 100000) { http_response_code(400); exit('Invalid page.'); }
$q = $db->prepare('SELECT source_row_number, error_code, error_message, severity, created_at FROM import_errors WHERE import_batch_id = ? ORDER BY id LIMIT 501 OFFSET ' . (($page - 1) * 500)); $q->execute([$batch]);
$errors = $q->fetchAll(); $meta = json_decode((string)$batchRow['import_options'], true) ?: [];
$more = count($errors) > 500;
if ($more) { array_pop($errors); }
adminHead('Import diagnostics');
echo '<p><a href="imports.php">← Imports</a></p><h1>Batch ' . e($batch) . ' diagnostics</h1><p>' . e($batchRow['source_type']) . ' · ' . e($batchRow['filename']) . '</p>';
echo '<p>Detected columns: ' . e(implode(', ', json_decode((string)$batchRow['source_headers'], true) ?: [])) . '</p>';
if (!empty($meta['unknown_headers'])) { echo '<div class="alert alert-warning">Unknown columns ignored: ' . e(implode(', ', $meta['unknown_headers'])) . '</div>'; }
echo '<table class="table table-sm"><thead><tr><th>Row</th><th>Severity</th><th>Code</th><th>Message</th><th>Time</th></tr></thead><tbody>';
foreach ($errors as $r) { echo '<tr><td>' . e($r['source_row_number']) . '</td><td>' . e($r['severity']) . '</td><td>' . e($r['error_code']) . '</td><td>' . e($r['error_message']) . '</td><td>' . e(AppTime::display($r['created_at'])) . '</td></tr>'; }
echo '</tbody></table>';
if ($page > 1) { echo '<a class="btn btn-outline-secondary me-2" href="import-errors.php?batch=' . e($batch) . '&amp;page=' . e($page - 1) . '">Previous</a>'; }
if ($more) { echo '<a class="btn btn-outline-secondary" href="import-errors.php?batch=' . e($batch) . '&amp;page=' . e($page + 1) . '">Next</a>'; }
adminFoot();

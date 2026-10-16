<?php
declare(strict_types=1);
require_once __DIR__ . '/_common.php';
$config = adminConfig('admin');requireDashboardAccess('imports');
$db = bmaDatabase($config);
$notice = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::csrf($_POST['csrf']??null);
    $upload = $_FILES['csv'] ?? null;
    try {
        if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$upload['tmp_name'])) { throw new RuntimeException('UPLOAD_FAILED'); }
        if (!preg_match('/\.csv\z/i',(string)($upload['name']??''))) {throw new RuntimeException('CSV_EXTENSION_REQUIRED');}
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file((string)$upload['tmp_name']);
        if(!in_array($mime,['text/plain','text/csv','application/csv','application/vnd.ms-excel'],true)){throw new RuntimeException('CSV_TYPE_REQUIRED');}
        $max = (int)($config['IMPORT_MAX_BYTES'] ?? 52428800);
        if (($upload['size'] ?? 0) < 1 || $upload['size'] > $max) { throw new RuntimeException('UPLOAD_SIZE_INVALID'); }
        $source = (string)($_POST['source'] ?? '');
        $name = (string)($upload['name'] ?? '');
        $uploads = new TemporaryUploads((string)$config['IMPORT_TEMP_DIR'], (string)$config['PUBLIC_WEB_ROOT']);
        $uploads->cleanup((int)($config['IMPORT_ABANDONED_SECONDS'] ?? 86400));
        $result = $uploads->processUploadedFile((string)$upload['tmp_name'], fn($stream) => (new ImportService($db, $config))->run($stream, $source, $name, [
            'dry_run'=>isset($_POST['dry_run']),
            'cognito_encoding'=>$_POST['cognito_encoding'] ?? 'UTF-8',
            'actor_user_id'=>adminReviewActor($db,$config),
            'stripe_account_id'=>(string)($_POST['stripe_account_id'] ?? ''),
            'stripe_livemode'=>in_array($_POST['stripe_livemode'] ?? '', ['0','1'], true) ? (int)$_POST['stripe_livemode'] : null,
        ]));
        header('Location: imports.php?batch=' . $result['batch_id'], true, 303); exit;
    } catch (Throwable) {
        // No exception text: PDO and parsers can include source content in messages.
        Security::log($config,'IMPORT_REQUEST_FAILED');
        $notice = 'Import could not complete. Check the private configuration and batch diagnostics.';
    } finally {
        if (is_array($upload) && isset($upload['tmp_name']) && is_string($upload['tmp_name']) && is_uploaded_file($upload['tmp_name'])) { unlink($upload['tmp_name']); }
    }
}
$rows = $db->query('SELECT id, source_type, filename, file_hash, started_at, row_count, inserted_count, updated_count, skipped_count, error_count, status, import_options FROM import_batches ORDER BY id DESC LIMIT 50')->fetchAll();
$selected = filter_input(INPUT_GET, 'batch', FILTER_VALIDATE_INT);
adminHead('CSV imports');
echo '<h1>CSV imports</h1><p>Uploads are processed from a private temporary directory and removed when processing ends. Only approved fields enter staging.</p>';
if ($notice !== null) { echo '<div class="alert alert-danger">' . e($notice) . '</div>'; }
if ($selected) { echo '<div class="alert alert-info">Batch ' . e($selected) . ' finished processing. Review its counts and diagnostics below.</div>'; }
echo '<form method="post" enctype="multipart/form-data" class="card card-body mb-4"><input type="hidden" name="csrf" value="' . e($_SESSION['import_csrf']) . '">';
echo '<div class="row g-3"><div class="col-md-4"><label class="form-label" for="source">Source</label><select class="form-select" name="source" id="source" required>';
foreach (['stripe_customers'=>'Stripe Customers','stripe_subscriptions'=>'Stripe Subscriptions','stripe_transactions'=>'Stripe Transactions','cognito_waivers'=>'Cognito Waivers'] as $key=>$label) { echo '<option value="' . e($key) . '">' . e($label) . '</option>'; }
echo '</select></div><div class="col-md-5"><label class="form-label" for="csv">CSV file</label><input class="form-control" type="file" name="csv" id="csv" accept=".csv,text/csv" required></div><div class="col-md-3"><label class="form-label" for="account">Stripe account ID</label><input class="form-control" name="stripe_account_id" id="account" placeholder="acct_..."></div>';
echo '<div class="col-md-3"><label class="form-label" for="mode">Stripe environment</label><select class="form-select" name="stripe_livemode" id="mode"><option value="">Unverified</option><option value="1">Live</option><option value="0">Test</option></select></div>';
echo '<div class="col-md-4"><label class="form-label" for="cognito-encoding">Cognito encoding (Cognito only)</label><select class="form-select" id="cognito-encoding" name="cognito_encoding"><option value="UTF-8">UTF-8 (default)</option><option value="Windows-1252">Windows-1252 (confirmed source)</option></select></div>';
echo '<div class="col-md-4 align-self-end"><label class="form-check"><input class="form-check-input" type="checkbox" name="dry_run" value="1"><span class="form-check-label">Validate only (dry run)</span></label></div></div>';
echo '<p class="small text-muted mt-3">For a production Stripe import, confirm the account ID and environment from Stripe before uploading. Dry runs do not change normalized records. Select Windows-1252 only for a verified Cognito export encoding; source timezone is configured separately.</p><button class="btn btn-primary" type="submit">Run import</button></form>';
echo '<h2>Recent batches</h2><div class="table-responsive"><table class="table table-striped table-sm"><thead><tr><th>ID</th><th>Source</th><th>Filename</th><th>Uploaded</th><th>Rows</th><th>Inserted</th><th>Updated</th><th>Skipped</th><th>Errors</th><th>Status</th><th>Duplicate</th><th>Diagnostics</th></tr></thead><tbody>';
foreach ($rows as $r) {
    $meta = json_decode((string)$r['import_options'], true) ?: [];
    echo '<tr><td>' . e($r['id']) . '</td><td>' . e($r['source_type']) . '</td><td>' . e($r['filename']) . '</td><td>' . e(AppTime::display($r['started_at'])) . '</td><td>' . e($r['row_count']) . '</td><td>' . e($r['inserted_count']) . '</td><td>' . e($r['updated_count']) . '</td><td>' . e($r['skipped_count']) . '</td><td>' . e($r['error_count']) . '</td><td>' . e($r['status']) . '</td><td>' . (($meta['duplicate_of']??null) ? 'Same file as batch ' . e($meta['duplicate_of']) : '') . '</td><td><a href="import-errors.php?batch=' . e($r['id']) . '">View</a></td></tr>';
}
echo '</tbody></table></div>';
adminFoot();

<?php
declare(strict_types=1);
ini_set('zend.exception_ignore_args', '1');

final class ImportService
{
    private const SOURCES = ['stripe_customers','stripe_subscriptions','stripe_transactions','cognito_waivers'];

    public function __construct(private readonly PDO $db, private readonly array $config) {}

    public function run($stream, string $source, string $filename, array $options = []): array
    {
        if (!is_resource($stream) || !in_array($source, self::SOURCES, true)) { throw new RuntimeException('INVALID_IMPORT_SOURCE'); }
        $filename = basename(str_replace('\\', '/', $filename));
        if (!preg_match('/\A[^\x00-\x1f\x7f]{1,240}\.csv\z/iD', $filename)) { throw new RuntimeException('INVALID_CSV_FILENAME'); }
        $dryRun = (bool)($options['dry_run'] ?? false);
        $encoding = $source === 'cognito_waivers' ? ($options['cognito_encoding'] ?? 'UTF-8') : 'UTF-8';
        if (!in_array($encoding, ['UTF-8','Windows-1252'], true)) { throw new RuntimeException('INVALID_CSV_ENCODING'); }
        $namespace = (string)($this->config['COGNITO_FORM_NAMESPACE'] ?? 'bma-waiver');
        $timezone = (string)($this->config['COGNITO_SOURCE_TIMEZONE'] ?? '');
        $account = trim((string)($options['stripe_account_id'] ?? ''));
        $live = $options['stripe_livemode'] ?? null;
        if ($source !== 'cognito_waivers' && !$dryRun && ($this->config['APP_ENV'] ?? 'production') === 'production' && ($account === '' || !in_array($live, [0,1], true))) {
            throw new RuntimeException('STRIPE_SOURCE_ATTESTATION_REQUIRED');
        }
        if ($account !== '' && (!preg_match('/\Aacct_[A-Za-z0-9]+\z/D', $account) || !in_array($live, [0,1], true))) { throw new RuntimeException('INVALID_STRIPE_CONTEXT'); }
        rewind($stream);
        $hash = hash_init('sha256'); hash_update_stream($hash, $stream); $fileHash = hash_final($hash);
        rewind($stream);
        $prefix = fread($stream, 4096); rewind($stream);
        if ($prefix === false || str_contains($prefix, "\0") || str_starts_with($prefix, "\xFF\xFE") || str_starts_with($prefix, "\xFE\xFF")) { throw new RuntimeException('INVALID_CSV_ENCODING'); }
        $headers = fgetcsv($stream, 1048576, ',', '"', '');
        if ($headers === false || $headers === [null] || count($headers) > 256) { throw new RuntimeException('INVALID_CSV_HEADER'); }
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]);
        foreach ($headers as &$header) {
            if (!is_string($header) || !mb_check_encoding($header, 'UTF-8') || strlen($header) > 255 || preg_match('/[\x00-\x1f\x7f]/', $header)) { throw new RuntimeException('INVALID_CSV_HEADER'); }
        }
        unset($header);
        if (count($headers) !== count(array_unique($headers))) { throw new RuntimeException('DUPLICATE_CSV_HEADER'); }
        $mappingKey = $source === 'cognito_waivers' ? CognitoSource::mappingKey($headers) : $source;
        $mapping = (require dirname(__DIR__, 2) . '/config/import-mappings.php')[$mappingKey];
        $mappingHash = hash('sha256', json_encode($mapping, JSON_THROW_ON_ERROR));
        $known = array_intersect($headers, array_keys($mapping));
        if ($known === [] || ($source !== 'cognito_waivers' && !in_array('id', $headers, true))) { throw new RuntimeException('UNRECOGNIZED_CSV_HEADER'); }
        $safeHeaders = array_map(fn($h) => mb_substr($h, 0, 100), $headers);
        $unknown = array_values(array_diff($safeHeaders, array_keys($mapping)));
        $logPath = rtrim((string)($this->config['IMPORT_TEMP_DIR'] ?? ''), '/\\') . DIRECTORY_SEPARATOR . 'import-events.log';
        if (!is_dir(dirname($logPath))) { throw new RuntimeException('IMPORT_PRIVATE_DIRECTORY_REQUIRED'); }
        $lock = (int)$this->db->query("SELECT GET_LOCK('bma_csv_import', 0)")->fetchColumn();
        if ($lock !== 1) { throw new RuntimeException('IMPORT_ALREADY_RUNNING'); }
        $batchId = null;
        try {
            $q = $this->db->prepare("SELECT id FROM import_batches WHERE source_type = ? AND file_hash = ? AND status = 'completed' AND JSON_EXTRACT(import_options, '$.dry_run') = false AND JSON_UNQUOTE(JSON_EXTRACT(import_options, '$.mapping_hash')) = ? AND JSON_UNQUOTE(JSON_EXTRACT(import_options, '$.cognito_namespace')) = ? AND source_timezone <=> ? ORDER BY id DESC LIMIT 1");
            $q->execute([$source, $fileHash, $mappingHash, $source === 'cognito_waivers' ? $namespace : '', $source === 'cognito_waivers' ? ($timezone ?: null) : null]); $duplicateOf = $q->fetchColumn() ?: null;
            if ($duplicateOf !== null && $source === 'cognito_waivers') {
                $q = $this->db->prepare('SELECT import_options FROM import_batches WHERE id=?'); $q->execute([$duplicateOf]);
                $priorOptions = json_decode((string)$q->fetchColumn(), true) ?: [];
                if (($priorOptions['input_encoding'] ?? 'UTF-8') !== $encoding) $duplicateOf = null;
            }
            $metadata = ['dry_run'=>$dryRun, 'duplicate_of'=>$duplicateOf, 'unknown_headers'=>$unknown, 'mapping_hash'=>$mappingHash, 'cognito_namespace'=>$source === 'cognito_waivers' ? $namespace : ''];
            if ($source === 'cognito_waivers') { $metadata['cognito_format'] = $mappingKey; $metadata['input_encoding'] = $encoding; }
            $q = $this->db->prepare('INSERT INTO import_batches (source_type, filename, file_hash, source_headers, source_timezone, import_options, status, source_stripe_account_id, source_stripe_livemode, source_identity_evidence, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $q->execute([$source, $filename, $fileHash, json_encode($safeHeaders, JSON_THROW_ON_ERROR), $source === 'cognito_waivers' ? ($timezone ?: null) : null,
                json_encode($metadata, JSON_THROW_ON_ERROR), 'running', $account ?: null, $account === '' ? null : $live, $account === '' ? null : 'operator', isset($options['actor_user_id'])?(int)$options['actor_user_id']:null]);
            $batchId = (int)$this->db->lastInsertId();
            if ($account !== '' && !$dryRun) { StripeContext::establish($this->db, $account, (bool)$live); }
            $importer = match ($source) {
                'stripe_customers'=>new StripeCustomerImporter(), 'stripe_subscriptions'=>new StripeSubscriptionImporter(),
                'stripe_transactions'=>new StripeTransactionImporter(), 'cognito_waivers'=>new CognitoWaiverImporter(),
            };
            $counts = ['row_count'=>0,'inserted_count'=>0,'updated_count'=>0,'skipped_count'=>0,'error_count'=>0];
            while (($cells = fgetcsv($stream, 1048576, ',', '"', '')) !== false) {
                $row = ++$counts['row_count'];
                if (count($cells) === 1 && $cells[0] === null) { $cells = array_fill(0, count($headers), ''); }
                $blank = true;
                foreach ($cells as $cell) { if ($cell !== null && trim((string)$cell) !== '') { $blank = false; break; } }
                if ($blank) { $counts['skipped_count']++; continue; }
                $rawId = null;
                try {
                    if (count($cells) !== count($headers)) { throw new RuntimeException('INVALID_ROW'); }
                    foreach ($cells as $cell) { if (!is_string($cell) || ($source !== 'cognito_waivers' && !mb_check_encoding($cell, 'UTF-8'))) { throw new RuntimeException('INVALID_ROW'); } }
                    $record = ApprovedRecord::fromRow($source, $headers, $cells, $encoding);
                    // Original cell array is never passed to persistence or diagnostics.
                    unset($cells);
                    $rawId = $record->stage($this->db, $batchId, $row);
                    $v = $record->values();
                    $key = $source === 'cognito_waivers'
                        ? CognitoSource::identity($record, $namespace)['source_locator']
                        : (Normalizers::text($v['id'] ?? null) ?? '');
                    if ($source === 'cognito_waivers' && $key !== '') {
                        $this->db->prepare('UPDATE raw_cognito_waivers SET source_locator = ?, content_hash = ? WHERE id = ?')->execute([$key, $record->contentHash(), $rawId]);
                    }
                    $duplicateRow = false;
                    if ($key !== '') {
                        $column = $source === 'cognito_waivers' ? 'source_locator' : 'source_record_id';
                        $q = $this->db->prepare('SELECT id FROM raw_' . $source . ' WHERE import_batch_id = ? AND ' . $column . ' = ? AND id <> ? LIMIT 1');
                        $q->execute([$batchId, $key, $rawId]);
                        $duplicateRow = $q->fetchColumn() !== false;
                    }
                    if ($duplicateRow) {
                        ImportDiagnostics::write($this->db, $batchId, $row, 'DUPLICATE_SOURCE_RECORD', $logPath);
                        $this->rawStatus($source, $rawId, 'skipped'); $counts['skipped_count']++; continue;
                    }
                    if ($duplicateOf !== null && !$dryRun) {
                        $this->rawStatus($source, $rawId, 'skipped'); $counts['skipped_count']++; continue;
                    }
                    $this->db->beginTransaction();
                    [$status, $warnings] = $importer->import($this->db, $record, $rawId, $dryRun,
                        ['cognito_namespace'=>$namespace, 'cognito_timezone'=>$timezone]);
                    $this->rawStatus($source, $rawId, $status);
                    $this->db->commit();
                    $counts[$status . '_count']++;
                    foreach (array_unique($warnings) as $warning) { ImportDiagnostics::write($this->db, $batchId, $row, $warning, $logPath); }
                } catch (Throwable $e) {
                    if ($this->db->inTransaction()) { $this->db->rollBack(); }
                    $code = in_array($e->getMessage(), ['INVALID_ROW','INVALID_ENCODING','INVALID_IDENTIFIER','INVALID_AMOUNT','INVALID_DATE','INVALID_CURRENCY','INVALID_STATUS','INVALID_BOOLEAN','INVALID_COUNT','IDENTITY_AMBIGUOUS','STRIPE_REFERENCE_CONFLICT'], true) ? $e->getMessage() : 'PROCESSING_FAILED';
                    ImportDiagnostics::write($this->db, $batchId, $row, $code, $logPath, 'error');
                    if ($rawId !== null) { $this->rawStatus($source, $rawId, 'error'); }
                    $counts['error_count']++;
                }
            }
            $status = $counts['error_count'] > 0 ? 'completed_with_errors' : 'completed';
            $q = $this->db->prepare('UPDATE import_batches SET row_count = ?, inserted_count = ?, updated_count = ?, skipped_count = ?, error_count = ?, status = ?, completed_at = UTC_TIMESTAMP(6) WHERE id = ?');
            $q->execute([$counts['row_count'],$counts['inserted_count'],$counts['updated_count'],$counts['skipped_count'],$counts['error_count'],$status,$batchId]);
            return ['batch_id'=>$batchId, 'duplicate_of'=>$duplicateOf, 'unknown_headers'=>$unknown] + $counts + ['status'=>$status];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            if ($batchId !== null) {
                $this->db->prepare("UPDATE import_batches SET status = 'failed', completed_at = UTC_TIMESTAMP(6) WHERE id = ?")->execute([$batchId]);
                ImportDiagnostics::write($this->db, $batchId, null, 'PROCESSING_FAILED', $logPath);
            }
            throw new RuntimeException('PROCESSING_FAILED');
        } finally {
            $this->db->query("SELECT RELEASE_LOCK('bma_csv_import')");
        }
    }

    private function rawStatus(string $source, int $id, string $status): void
    {
        $this->db->prepare('UPDATE raw_' . $source . ' SET processing_status = ? WHERE id = ?')->execute([$status, $id]);
    }
}

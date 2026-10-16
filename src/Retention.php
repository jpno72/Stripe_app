<?php
declare(strict_types=1);
ini_set('zend.exception_ignore_args', '1');
require_once __DIR__ . '/CognitoSource.php';

/** Only this value object crosses from source parsing into persistence. No original row is retained. */
final class ApprovedRecord
{
    private function __construct(public readonly string $source, private readonly array $values, public readonly array $unknownHeaders) {}

    public static function fromRow(string $source, array $headers, array $cells, string $encoding = 'UTF-8'): self
    {
        $maps = require dirname(__DIR__) . '/config/import-mappings.php';
        $mapKey = $source === 'cognito_waivers' ? CognitoSource::mappingKey($headers) : $source;
        if (!isset($maps[$mapKey])) { throw new RuntimeException('UNKNOWN_SOURCE'); }
        if (count($headers) !== count($cells) || count(array_unique($headers)) !== count($headers)) {
            throw new RuntimeException('INVALID_HEADERS_OR_WIDTH');
        }
        $approved = []; $unknown = [];
        foreach ($maps[$mapKey] as $header => $mapping) {
            if (in_array($mapping['action'], ['IMPORT','OPTIONAL'], true) && $mapping['approved']) { $approved[$header] = null; }
        }
        foreach ($headers as $index => $header) {
            if (!is_string($header)) { throw new RuntimeException('INVALID_HEADER'); }
            $mapping = $maps[$mapKey][$header] ?? null;
            if ($mapping === null) {
                // Header names are metadata; strip control bytes, bound size, never include values.
                $unknown[] = mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', '', $header), 0, 100);
                continue;
            }
            if (!in_array($mapping['action'], ['IMPORT','OPTIONAL'], true) || !$mapping['approved']) { continue; }
            if (!is_string($cells[$index]) && $cells[$index] !== null) { throw new RuntimeException('INVALID_SOURCE_VALUE'); }
            $approved[$header] = $source === 'cognito_waivers' ? CognitoSource::text($cells[$index], $encoding) : $cells[$index];
        }
        ksort($approved, SORT_STRING);
        return new self($source, $approved, $unknown);
    }

    public function values(): array { return $this->values; }
    public function json(): string { return json_encode((object)$this->values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }

    public function contentHash(): string
    {
        $canonical = [];
        foreach ($this->values as $key => $value) {
            $canonical[$key] = $value === null ? '' : trim(str_replace(["\r\n", "\r"], "\n", $value));
        }
        return hash('sha256', json_encode((object)$canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function stage(PDO $db, int $batch, int $row): int
    {
        // Source validated by the fixed mapping, never from a SQL identifier supplied by callers.
        $types = ['stripe_customers','stripe_subscriptions','stripe_transactions','cognito_waivers'];
        if (!in_array($this->source, $types, true) || $row < 1) { throw new RuntimeException('INVALID_STAGE_TARGET'); }
        $q = $db->prepare('SELECT source_type FROM import_batches WHERE id = ?'); $q->execute([$batch]);
        if ($q->fetchColumn() !== $this->source) { throw new RuntimeException('BATCH_SOURCE_MISMATCH'); }
        $sourceCustomer = in_array($this->source, ['stripe_subscriptions','stripe_transactions'], true) ? ($this->values['Customer ID'] ?? null) : null;
        $sourceEmail = $this->source === 'stripe_customers' ? ($this->values['Email'] ?? null) : ($this->source === 'cognito_waivers' ? ($this->values['Email'] ?? null) : null);
        $extraColumn = $sourceCustomer !== null || in_array($this->source, ['stripe_subscriptions','stripe_transactions'], true) ? 'source_customer_id' : 'source_email';
        $extraValue = $extraColumn === 'source_customer_id' ? $sourceCustomer : $sourceEmail;
        $recordId = $this->values['id'] ?? null;
        if ($this->source === 'cognito_waivers' && CognitoSource::native($this)) {
            $native = $this->values[CognitoSource::ID];
            $recordId = is_string($native) && preg_match('/\A[1-9][0-9]{0,254}\z/D', $native) ? $native : null;
        }
        $q = $db->prepare('INSERT INTO raw_' . $this->source . ' (import_batch_id, source_row_number, source_record_id, ' . $extraColumn . ', raw_values, row_hash) VALUES (?, ?, ?, ?, ?, ?)');
        $q->execute([$batch, $row, $recordId, $extraValue, $this->json(), $this->contentHash()]);
        return (int)$db->lastInsertId();
    }

    public function __debugInfo(): array { return ['source'=>$this->source, 'retained_field_count'=>count($this->values)]; }
}

/** Fixed diagnostics deliberately have no row, exception, arbitrary message, or metadata argument. */
final class ImportDiagnostics
{
    private const MESSAGES = [
        'CURRENCY_UNRESOLVED' => 'Source currency is unresolved; monetary normalization deferred.',
        'TIMEZONE_UNRESOLVED' => 'Source timezone is unresolved; timestamp normalization deferred.',
        'IDENTITY_AMBIGUOUS' => 'Submission identity needs administrative review.',
        'STRIPE_REFERENCE_CONFLICT' => 'Stripe references conflict; association deferred.',
        'PROCESSING_FAILED' => 'Processing failed; source values and exception details omitted.',
        'INVALID_ENCODING' => 'Approved source text is invalid for the selected encoding.',
        'LEGACY_RECONCILIATION_REQUIRED' => 'Native entry needs reviewed reconciliation with existing fingerprint records.',
        'WAIVER_UPDATE_REVIEW_REQUIRED' => 'Changed source evidence is protected by an existing review or identity association.',
        'SOURCE_TIME_AMBIGUOUS' => 'Source local time is ambiguous or nonexistent in the verified source timezone.',
        'SUBMISSION_TIME_MISSING' => 'Submission has a valid native ID but no source submission timestamp.',
        'INVALID_ROW' => 'Row has an invalid shape or source value.',
        'INVALID_IDENTIFIER' => 'A required external identifier is missing or invalid.',
        'INVALID_AMOUNT' => 'A monetary value is invalid for its source currency.',
        'INVALID_DATE' => 'A date or timestamp is invalid or ambiguous.',
        'INVALID_CURRENCY' => 'A required source currency is missing or invalid.',
        'INVALID_STATUS' => 'A required status is missing.',
        'INVALID_BOOLEAN' => 'A Boolean source value is invalid.',
        'INVALID_COUNT' => 'A source count is invalid.',
        'DUPLICATE_SOURCE_RECORD' => 'Source identifier occurs more than once in this file; later row skipped.',
    ];

    public static function write(PDO $db, int $batch, ?int $row, string $code, string $logPath, ?string $severity = null): void
    {
        if (!isset(self::MESSAGES[$code])) { throw new RuntimeException('INVALID_DIAGNOSTIC_CODE'); }
        $q = $db->prepare('INSERT INTO import_errors (import_batch_id, source_row_number, error_code, error_message, severity) VALUES (?, ?, ?, ?, ?)');
        if ($severity !== null && !in_array($severity, ['warning','error'], true)) throw new RuntimeException('INVALID_DIAGNOSTIC_SEVERITY');
        $warning = in_array($code, ['CURRENCY_UNRESOLVED','TIMEZONE_UNRESOLVED','IDENTITY_AMBIGUOUS','DUPLICATE_SOURCE_RECORD','LEGACY_RECONCILIATION_REQUIRED','WAIVER_UPDATE_REVIEW_REQUIRED','SUBMISSION_TIME_MISSING','SOURCE_TIME_AMBIGUOUS'], true);
        $q->execute([$batch, $row, $code, self::MESSAGES[$code], $severity ?? ($warning ? 'warning' : 'error')]);
        $line = json_encode(['batch_id'=>$batch, 'row_number'=>$row, 'code'=>$code], JSON_THROW_ON_ERROR) . "\n";
        // The structured database diagnostic is authoritative; a private log failure
        // must not turn one bad source row into a whole-file abort.
        @file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
    }
}

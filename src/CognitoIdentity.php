<?php
declare(strict_types=1);

final class CognitoIdentity
{
    public static function identify(ApprovedRecord $record, string $namespace, ?string $trustedEntryId = null): array
    {
        if ($record->source !== 'cognito_waivers' || !preg_match('/\A[a-zA-Z0-9_.-]{1,100}\z/', $namespace)) { throw new RuntimeException('INVALID_COGNITO_NAMESPACE'); }
        $values = $record->values();
        if ($trustedEntryId !== null && $trustedEntryId !== '') {
            $kind = 'external'; $tuple = [$namespace, $trustedEntryId];
        } else {
            // These are source name labels, not assumptions about which person owns them.
            $name = self::name($values['Name'] ?? ''); $signer = self::name($values['Print Name'] ?? '');
            $submitted = self::parse($values['Date Submitted'] ?? '');
            if ($name === '' || $signer === '' || $submitted === null) { throw new RuntimeException('IDENTITY_AMBIGUOUS'); }
            $kind = 'fingerprint';
            $tuple = [$namespace, $submitted['key'], $name, $signer];
        }
        return ['source_record_id'=>$trustedEntryId, 'source_id_kind'=>$kind,
            'form_namespace'=>$namespace, 'source_locator'=>'cog-v1-' . hash('sha256', json_encode($tuple, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
            'content_hash'=>$record->contentHash()];
    }

    private static function name(string $value): string { return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value)), 'UTF-8'); }

    /** Returns an explicit-offset instant or an unzoned wall-clock value, never a guessed zone. */
    private static function parse(string $input): ?array
    {
        $value = trim($input);
        foreach (['!Y-m-d\TH:i:sP','!Y-m-d\TH:iP','!Y-m-d H:i:sP','!Y-m-d H:iP'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                $utc = $date->setTimezone(new DateTimeZone('UTC'));
                return ['key'=>'instant:' . $utc->format('Y-m-d H:i:s'), 'explicit'=>true, 'date'=>$utc];
            }
        }
        foreach (['!n/j/Y G:i','!n/j/Y G:i:s','!Y-m-d H:i:s','!Y-m-d H:i'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
            $errors = DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return ['key'=>'civil:' . $date->format('Y-m-d H:i:s'), 'explicit'=>false, 'date'=>$date];
            }
        }
        if (preg_match('/\A(\d{1,2})\/(\d{1,2})\/(\d{4}) (0?[1-9]|1[0-2]):([0-5]\d)(?::([0-5]\d))? (AM|PM)\z/iD', $value, $parts)
            && checkdate((int)$parts[1], (int)$parts[2], (int)$parts[3])) {
            $format = ($parts[6] ?? '') !== '' ? '!n/j/Y g:i:s A' : '!n/j/Y g:i A';
            $date = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
            $errors = DateTimeImmutable::getLastErrors();
            if ($date && ($errors === false || (!$errors['warning_count'] && !$errors['error_count']))) {
                return ['key'=>'civil:' . $date->format('Y-m-d H:i:s'), 'explicit'=>false, 'date'=>$date];
            }
        }
        return null;
    }

    public static function submissionTime(string $input, ?string $configuredZone): array
    {
        $parsed = self::parse($input);
        if ($parsed === null) { return ['utc'=>null, 'timezone'=>null, 'warning'=>'INVALID_TIMESTAMP']; }
        if ($parsed['explicit']) { return ['utc'=>$parsed['date']->format('Y-m-d H:i:s'), 'timezone'=>'explicit_offset', 'warning'=>null]; }
        if ($configuredZone === null || trim($configuredZone) === '') { return ['utc'=>null, 'timezone'=>null, 'warning'=>'TIMEZONE_UNRESOLVED']; }
        if (!in_array($configuredZone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) { throw new RuntimeException('INVALID_SOURCE_TIMEZONE'); }
        $zone = new DateTimeZone($configuredZone); $wall = $parsed['date']; $stamp = $wall->getTimestamp();
        $offsets = array_unique(array_column($zone->getTransitions($stamp - 172800, $stamp + 172800), 'offset'));
        $candidates = [];
        foreach ($offsets as $offset) {
            $candidate = (new DateTimeImmutable('@' . ($stamp - $offset)))->setTimezone($zone);
            if ($candidate->format('Y-m-d H:i:s') === $wall->format('Y-m-d H:i:s')) { $candidates[] = $candidate->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); }
        }
        if (count($candidates) !== 1) { return ['utc'=>null, 'timezone'=>$configuredZone, 'warning'=>'AMBIGUOUS_OR_INVALID_LOCAL_TIME']; }
        return ['utc'=>$candidates[0], 'timezone'=>$configuredZone, 'warning'=>null];
    }
}

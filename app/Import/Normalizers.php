<?php
declare(strict_types=1);

final class Normalizers
{
    public static function text(?string $value): ?string
    {
        if ($value === null) { return null; }
        $value = trim(preg_replace('/\s+/u', ' ', $value));
        return $value === '' ? null : $value;
    }

    public static function email(?string $value): ?string
    {
        $value = self::text($value);
        return $value === null ? null : mb_strtolower($value, 'UTF-8');
    }

    public static function phone(?string $value): ?string
    {
        $value = self::text($value);
        if ($value === null) { return null; }
        $digits = preg_replace('/\D+/', '', $value);
        return $digits === '' ? null : $digits;
    }

    public static function postal(?string $value): ?string
    {
        $value = self::text($value);
        return $value === null ? null : mb_strtoupper($value, 'UTF-8');
    }

    public static function currency(?string $value): ?string
    {
        return StripeContext::currency($value);
    }

    /** CSVs contain major-unit decimal text. Never pass a value through float. */
    public static function amount(?string $value, string $currency): ?int
    {
        $value = self::text($value);
        if ($value === null) { return null; }
        $exponent = in_array($currency, ['bif','clp','djf','gnf','jpy','kmf','krw','mga','pyg','rwf','ugx','vnd','vuv','xaf','xof','xpf'], true) ? 0 : (in_array($currency, ['bhd','jod','kwd','omr','tnd'], true) ? 3 : 2);
        $value = str_replace(',', '', $value);
        if (!preg_match('/\A(-?)(?:([0-9]+))(?:\.([0-9]+))?\z/D', $value, $match)) { throw new RuntimeException('INVALID_AMOUNT'); }
        $fraction = $match[3] ?? '';
        if (strlen($fraction) > $exponent && trim(substr($fraction, $exponent), '0') !== '') { throw new RuntimeException('INVALID_AMOUNT'); }
        $digits = ltrim($match[2] . str_pad(substr($fraction, 0, $exponent), $exponent, '0'), '0');
        if ($digits === '') { return 0; }
        $limit = (string)PHP_INT_MAX;
        if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) { throw new RuntimeException('INVALID_AMOUNT'); }
        return ($match[1] === '-' ? -1 : 1) * (int)$digits;
    }

    public static function validateDecimal(?string $value): void
    {
        $value = self::text($value);
        if ($value !== null && !preg_match('/\A-?[0-9][0-9,]*(?:\.[0-9]+)?\z/D', $value)) { throw new RuntimeException('INVALID_AMOUNT'); }
    }

    public static function utc(?string $value): ?string
    {
        $value = self::text($value);
        if ($value === null) { return null; }
        foreach (['!Y-m-d H:i:s','!Y-m-d H:i','!n/j/Y G:i','!n/j/Y G:i:s'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
            $errors = DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) { return $date->format('Y-m-d H:i:s'); }
        }
        throw new RuntimeException('INVALID_DATE');
    }

    public static function date(?string $value): ?string
    {
        $value = self::text($value);
        if ($value === null) { return null; }
        foreach (['!Y-m-d','!n/j/Y','!n/j/y'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
            $errors = DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) { return $date->format('Y-m-d'); }
        }
        throw new RuntimeException('INVALID_DATE');
    }

    public static function boolean(?string $value): ?int
    {
        $value = self::text($value);
        if ($value === null) { return null; }
        return match (mb_strtolower($value, 'UTF-8')) {
            'true','yes','y','1' => 1,
            'false','no','n','0' => 0,
            default => throw new RuntimeException('INVALID_BOOLEAN'),
        };
    }

    /** Unified Payments exports use both charge/payment ID prefixes. Other IDs remain strict. */
    public static function stripeTransactionId(?string $value): string
    {
        if ($value !== null && str_contains($value, "\0")) { throw new RuntimeException('INVALID_IDENTIFIER'); }
        $value = self::text($value);
        if ($value === null || strlen($value) > 255
            || !preg_match('/\A(?:ch_|py_)[A-Za-z0-9_]+\z/D', $value)) {
            throw new RuntimeException('INVALID_IDENTIFIER');
        }
        return $value;
    }

    public static function stripeId(?string $value, string $prefix, bool $required = false): ?string
    {
        $value = self::text($value);
        if ($value === null) {
            if ($required) { throw new RuntimeException('INVALID_IDENTIFIER'); }
            return null;
        }
        if (!preg_match('/\A' . preg_quote($prefix, '/') . '[A-Za-z0-9_]+\z/D', $value) || strlen($value) > 255) { throw new RuntimeException('INVALID_IDENTIFIER'); }
        return $value;
    }
}

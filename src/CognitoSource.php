<?php
declare(strict_types=1);

/** Explicit BMA form adapter. No fuzzy headers, contact identity guesses or lossy transcoding. */
final class CognitoSource
{
    public const ID = 'BloomsburgMartialArtsAcademy2_Id';
    public static function mappingKey(array $headers): string
    {
        $native = array_intersect($headers, [self::ID,'StudentName_First','StudentName_Last','PrintName','Entry_DateSubmitted']);
        if (!$native) return 'cognito_waivers';
        if (array_diff([self::ID,'StudentName_First','StudentName_Last','PrintName','Entry_DateSubmitted'], $headers)
            || array_intersect(['Name','Date Submitted','Print Name'], $headers)) throw new RuntimeException('UNRECOGNIZED_CSV_HEADER');
        return 'cognito_waivers_bma2_v1';
    }
    public static function native(ApprovedRecord $record): bool { return array_key_exists(self::ID, $record->values()); }
    public static function entryId(?string $id): string
    {
        // Cognito internal entry numbers are positive integers; retain the complete source string.
        if ($id === null || !preg_match('/\A[1-9][0-9]{0,254}\z/D', $id)) throw new RuntimeException('INVALID_IDENTIFIER');
        return $id;
    }
    public static function identity(ApprovedRecord $record, string $namespace): array
    {
        if (!self::native($record)) return CognitoIdentity::identify($record, $namespace);
        return CognitoIdentity::identify($record, $namespace . '.BloomsburgMartialArtsAcademy2', self::entryId($record->values()[self::ID]));
    }
    /** Derived canonical values are for parsing only; staging retains original approved headings/cells. */
    public static function canonical(ApprovedRecord $record): array
    {
        $v = $record->values();
        if (!self::native($record)) return $v;
        $address = [];
        foreach (['Address_Line1','Address_Line2','Address_City','Address_State','Address_PostalCode'] as $key) {
            $part = Normalizers::text($v[$key]); if ($part !== null) $address[] = $part;
        }
        return ['Name'=>Normalizers::text(implode(' ', [$v['StudentName_First'] ?? '',$v['StudentName_Last'] ?? ''])),
            'Print Name'=>$v['PrintName'], 'Date Submitted'=>$v['Entry_DateSubmitted'],
            'Email'=>$v['Email'], 'Phone'=>$v['Phone'], 'Text Friendly'=>$v['TextFriendly'],
            'Student Birthdate'=>$v['StudentBirthdate'], 'Address'=>$address ? implode(', ', $address) : null,
            'Date'=>$v['Date'], 'Relationship to student'=>$v['RelationshipToStudent']];
    }
    public static function text(?string $value, string $encoding): ?string
    {
        if ($value === null) return null;
        if (str_contains($value, "\0")) throw new RuntimeException('INVALID_ENCODING');
        if ($encoding === 'UTF-8') {
            if (!mb_check_encoding($value, 'UTF-8')) throw new RuntimeException('INVALID_ENCODING');
            return $value;
        }
        if ($encoding !== 'Windows-1252' || preg_match('/[\x81\x8d\x8f\x90\x9d]/', $value)) throw new RuntimeException('INVALID_ENCODING');
        $converted = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        if (!mb_check_encoding($converted, 'UTF-8') || mb_convert_encoding($converted, 'Windows-1252', 'UTF-8') !== $value) throw new RuntimeException('INVALID_ENCODING');
        return $converted;
    }
}

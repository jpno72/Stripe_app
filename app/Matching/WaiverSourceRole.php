<?php
declare(strict_types=1);

/** A verified form label is not a verified BMA person association. */
final class WaiverSourceRole
{
    public static function isStudent(array $waiver): bool
    {
        return ($waiver['source_format']??null)==='bma2_v1' && ($waiver['source_name_role']??null)==='student';
    }
    public static function comparison(array $waiver,string $candidateRole): string
    {
        return $candidateRole==='signer'?'signer':(self::isStudent($waiver)?'student':'unresolved_name');
    }
    public static function identityNotice(array $waiver): string
    {
        if(!self::isStudent($waiver))return 'The form Name role and household contact ownership require review.';
        return !empty($waiver['student_identity_reviewed'])
            ? 'The form explicitly labels the student. Their BMA identity has administrator confirmation; household contact ownership remains unverified.'
            : 'The form explicitly labels the student. Their BMA identity remains unverified until administrator review; household contact ownership remains unverified.';
    }
}

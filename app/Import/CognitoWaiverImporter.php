<?php
declare(strict_types=1);

final class CognitoWaiverImporter extends AbstractImporter
{
    public function import(PDO $db, ApprovedRecord $record, int $rawId, bool $dryRun, array $options): array
    {
        $v = $record->values();
        $identity = CognitoIdentity::identify($record, $options['cognito_namespace']);
        $q = $db->prepare('UPDATE raw_cognito_waivers SET source_locator = ?, content_hash = ? WHERE id = ?');
        $q->execute([$identity['source_locator'], $identity['content_hash'], $rawId]);
        $existing = $this->existing($db, 'cognito_waivers', 'source_locator', $identity['source_locator']);
        if ($existing && $existing['form_namespace'] !== $identity['form_namespace']) { throw new RuntimeException('IDENTITY_AMBIGUOUS'); }
        if ($existing && hash_equals($existing['content_hash'], $identity['content_hash'])) { return ['skipped', []]; }
        // A fallback locator is not a true submission ID. Changed content needs review.
        if ($existing && $identity['source_id_kind'] === 'fingerprint') { return ['skipped', ['IDENTITY_AMBIGUOUS']]; }
        $submission = CognitoIdentity::submissionTime($v['Date Submitted'] ?? '', $options['cognito_timezone']);
        $birth = Normalizers::date($v['Student Birthdate']);
        $signed = Normalizers::date($v['Date']);
        $textFriendly = Normalizers::boolean($v['Text Friendly']);
        $values = ['source_record_id'=>$identity['source_record_id'], 'source_id_kind'=>$identity['source_id_kind'],
            'form_namespace'=>$identity['form_namespace'], 'source_locator'=>$identity['source_locator'],
            'content_hash'=>$identity['content_hash'], 'submitted_at'=>$submission['utc'], 'source_timezone'=>$submission['timezone'],
            'source_name'=>Normalizers::text($v['Name']), 'contact_email'=>Normalizers::email($v['Email']),
            'contact_phone'=>Normalizers::phone($v['Phone']), 'text_friendly'=>$textFriendly, 'student_birth_date'=>$birth,
            'address_text'=>Normalizers::text($v['Address']), 'signer_print_name'=>Normalizers::text($v['Print Name']),
            'signed_date'=>$signed, 'relationship_to_student'=>Normalizers::text($v['Relationship to student']),
            'latest_raw_id'=>$rawId, 'interpretation_status'=>'unresolved'];
        if (!$dryRun) { $this->write($db, 'cognito_waivers', $values, $existing === null ? null : (int)$existing['id']); }
        $warnings = $submission['warning'] === null ? [] : [$submission['warning'] === 'TIMEZONE_UNRESOLVED' ? 'TIMEZONE_UNRESOLVED' : 'INVALID_DATE'];
        return [$this->status($existing, false, $dryRun), $warnings];
    }
}

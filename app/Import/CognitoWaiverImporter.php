<?php
declare(strict_types=1);

final class CognitoWaiverImporter extends AbstractImporter
{
    public function import(PDO $db, ApprovedRecord $record, int $rawId, bool $dryRun, array $options): array
    {
        $v = CognitoSource::canonical($record);
        $native = CognitoSource::native($record);
        $identity = CognitoSource::identity($record, $options['cognito_namespace']);
        $q = $db->prepare('UPDATE raw_cognito_waivers SET source_locator = ?, content_hash = ? WHERE id = ?');
        $q->execute([$identity['source_locator'], $identity['content_hash'], $rawId]);
        $existing = $this->existing($db, 'cognito_waivers', 'source_locator', $identity['source_locator']);
        if ($existing && $existing['form_namespace'] !== $identity['form_namespace']) { throw new RuntimeException('IDENTITY_AMBIGUOUS'); }
        if ($existing && hash_equals($existing['content_hash'], $identity['content_hash'])) { return ['skipped', []]; }
        $submission = CognitoIdentity::submissionTime($v['Date Submitted'] ?? '', $options['cognito_timezone']);
        if ($submission['warning'] === 'INVALID_TIMESTAMP' && Normalizers::text($v['Date Submitted'] ?? null) !== null) { throw new RuntimeException('INVALID_DATE'); }
        $birth = Normalizers::date($v['Student Birthdate']);
        $signed = Normalizers::date($v['Date']);
        $textFriendly = Normalizers::boolean($v['Text Friendly']);
        // A fallback locator is not a true submission ID. Changed content needs review.
        if ($existing && $identity['source_id_kind'] === 'fingerprint') { return ['skipped', ['IDENTITY_AMBIGUOUS']]; }
        if ($native && !$existing) {
            // Without a reviewed crosswalk, no fingerprint can prove which native entry it represents.
            $q = $db->prepare("SELECT 1 FROM cognito_waivers WHERE source_id_kind='fingerprint' AND form_namespace IN (?,?) LIMIT 1");
            $q->execute([$options['cognito_namespace'], $identity['form_namespace']]);
            if ($q->fetchColumn() !== false) return ['skipped', ['LEGACY_RECONCILIATION_REQUIRED']];
        }
        if ($native && $existing) {
            $q = $db->prepare("SELECT 1 FROM identity_links WHERE source_system='cognito' AND source_type='waiver' AND external_id=? LIMIT 1");
            $q->execute([$identity['source_locator']]);
            $linked = $q->fetchColumn() !== false;
            $q = $db->prepare('SELECT 1 FROM identity_match_candidates WHERE cognito_waiver_id=? LIMIT 1');
            $q->execute([$existing['id']]);
            if ($linked || $q->fetchColumn() !== false || $existing['student_person_id'] !== null || $existing['signer_person_id'] !== null || $existing['interpretation_status'] === 'verified') {
                return ['skipped', ['WAIVER_UPDATE_REVIEW_REQUIRED']];
            }
        }
        $values = ['source_record_id'=>$identity['source_record_id'], 'source_id_kind'=>$identity['source_id_kind'],
            'form_namespace'=>$identity['form_namespace'], 'source_locator'=>$identity['source_locator'],
            'content_hash'=>$identity['content_hash'], 'submitted_at'=>$submission['utc'], 'source_timezone'=>$submission['timezone'],
            'source_name'=>Normalizers::text($v['Name']), 'contact_email'=>Normalizers::email($v['Email']),
            'contact_phone'=>Normalizers::phone($v['Phone']), 'text_friendly'=>$textFriendly, 'student_birth_date'=>$birth,
            'address_text'=>Normalizers::text($v['Address']), 'signer_print_name'=>Normalizers::text($v['Print Name']),
            'signed_date'=>$signed, 'relationship_to_student'=>Normalizers::text($v['Relationship to student']),
            'latest_raw_id'=>$rawId, 'interpretation_status'=>'unresolved'];
        if ($native) {
            $raw = $record->values();
            $values['source_format'] = 'bma2_v1';
            $values['source_name_role'] = 'student';
            $values['source_student_first_name'] = Normalizers::text($raw['StudentName_First']);
            $values['source_student_last_name'] = Normalizers::text($raw['StudentName_Last']);
        }
        if (!$dryRun) { $this->write($db, 'cognito_waivers', $values, $existing === null ? null : (int)$existing['id']); }
        $warnings = $submission['warning'] === null ? [] : [$submission['warning'] === 'TIMEZONE_UNRESOLVED' ? 'TIMEZONE_UNRESOLVED' : ($submission['warning'] === 'INVALID_TIMESTAMP' ? 'SUBMISSION_TIME_MISSING' : 'SOURCE_TIME_AMBIGUOUS')];
        return [$this->status($existing, false, $dryRun), $warnings];
    }
}

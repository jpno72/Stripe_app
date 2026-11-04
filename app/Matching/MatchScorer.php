<?php
declare(strict_types=1);
require_once __DIR__.'/WaiverSourceRole.php';

/** Pure scorer. Evidence contains comparison codes, never source values. */
final class MatchScorer
{
    public function score(array $payer, array $waiver, string $role): array
    {
        if (!in_array($role, ['signer','source_name_unresolved'], true)) { throw new RuntimeException('INVALID_MATCH_ROLE'); }
        $signals = []; $conflicts = []; $points = 0;
        $payerName = $this->name($payer['name'] ?? null);
        $waiverName = $this->name($waiver['name'] ?? null);
        $variant=self::comparisonName($payer['name']??null,false)!==self::comparisonName($waiver['name']??null,false)&&$payerName===$waiverName;
        $ambiguous=self::ambiguousName($payer['name']??null)||self::ambiguousName($waiver['name']??null);
        if($variant)$conflicts[]='name_variant_requires_review';
        if($ambiguous)$conflicts[]='ambiguous_name_variant';
        $payerLast = $this->last($payerName); $waiverLast = $this->last($waiverName);
        if ($payerName !== '' && $waiverName !== '') {
            if ($payerName === $waiverName) { $points += 42; $signals[] = 'exact_full_name'; }
            elseif ($payerLast !== '' && $waiverLast !== '' && $payerLast !== $waiverLast) { $points -= 25; $conflicts[] = 'different_last_name'; }
            else { $points -= 10; $conflicts[] = 'different_full_name'; }
            if ($payerLast !== '' && $payerLast === $waiverLast) { $points += 10; $signals[] = 'exact_last_name'; }
            elseif ($payerName !== $waiverName) {
                similar_text($payerName, $waiverName, $percent);
                if ($percent >= 80) { $points += 5; $signals[] = 'similar_name_only'; }
            }
        }
        $this->compare($payer['email'] ?? null, $waiver['email'] ?? null, 'exact_email', 24, $points, $signals);
        $this->compare($payer['phone'] ?? null, $waiver['phone'] ?? null, 'exact_phone', 16, $points, $signals);
        $this->compare($payer['postal'] ?? null, $waiver['postal'] ?? null, 'exact_postal', 8, $points, $signals);
        $this->compare($payer['address'] ?? null, $waiver['address'] ?? null, 'exact_address', 12, $points, $signals);
        $payerDob = $payer['birth_date'] ?? null; $waiverDob = $waiver['birth_date'] ?? null;
        if ($payerDob !== null && $waiverDob !== null) {
            if ($payerDob === $waiverDob) { $points += 32; $signals[] = 'exact_birth_date'; }
            else { $points -= 80; $conflicts[] = 'conflicting_birth_date'; }
        }
        $relationship = $this->name($waiver['relationship'] ?? null);
        $sourceName = $this->name($waiver['source_name'] ?? null);
        $signerName = $this->name($waiver['signer_name'] ?? null);
        $studentSource=WaiverSourceRole::isStudent($waiver);
        if ($role === 'source_name_unresolved') {
            if ($studentSource) {
                $signals[]='explicit_student_name';
                if(empty($waiver['student_identity_reviewed']))$conflicts[]='student_identity_unverified';
            } else $conflicts[]='source_name_role_unverified';
        }
        if ($relationship === 'self' && $sourceName !== '' && $signerName !== '' && $sourceName !== $signerName) { $conflicts[] = 'self_label_names_disagree'; }
        if (in_array($relationship, ['mother','father','parent','guardian','grandparent'], true) && $sourceName !== '' && $signerName !== '' && $sourceName !== $signerName) {
            $signals[] = 'possible_parent_student_structure';
        }
        $score = max(0, min(100, $points)) / 100;
        $hardConflict = $variant || $ambiguous || in_array('conflicting_birth_date', $conflicts, true)
            || in_array('source_name_role_unverified', $conflicts, true)
            || in_array('student_identity_unverified', $conflicts, true)
            || in_array('self_label_names_disagree', $conflicts, true);
        $method = in_array('exact_full_name', $signals, true) && in_array('exact_email', $signals, true) ? 'name_email'
            : (in_array('exact_full_name', $signals, true) && in_array('exact_phone', $signals, true) ? 'name_phone'
            : (in_array('exact_email', $signals, true) ? 'shared_email'
            : (in_array('exact_full_name', $signals, true) ? 'exact_name' : 'weak_or_none')));
        if (in_array('conflicting_birth_date', $conflicts, true)) { $reason = 'conflicting_birth_date'; }
        elseif (in_array('self_label_names_disagree', $conflicts, true)) { $reason = 'self_label_names_disagree'; }
        elseif (in_array('student_identity_unverified', $conflicts, true)) { $reason = 'student_identity_unverified'; }
        elseif (in_array('source_name_role_unverified', $conflicts, true)) { $reason = 'source_name_role_unverified'; }
        elseif ($conflicts !== []) { $reason = 'contact_or_name_conflict'; }
        elseif ($score >= 0.9) { $reason = 'multiple_strong_signals'; }
        elseif ($score >= 0.45) { $reason = 'review_signals'; }
        else { $reason = 'insufficient_evidence'; }
        return ['score'=>$score, 'method'=>$method, 'reason'=>$reason, 'hard_conflict'=>$hardConflict,
            'evidence'=>['signals'=>array_values(array_unique($signals)), 'conflicts'=>array_values(array_unique($conflicts)),
                'contact_owner_verified'=>false, 'role'=>$role,
                'source_name_role'=>$studentSource?'student':'unresolved',
                'student_identity_reviewed'=>$studentSource&&!empty($waiver['student_identity_reviewed'])]];
    }

    private function compare(?string $left, ?string $right, string $signal, int $weight, int &$points, array &$signals): void
    {
        if ($left === null || $right === null) { return; }
        $left = mb_strtolower(trim($left), 'UTF-8'); $right = mb_strtolower(trim($right), 'UTF-8');
        if ($left !== '' && $left === $right) { $points += $weight; $signals[] = $signal; }
    }

    /** Comparison-only normalization. Full middle names and original stored values are preserved. */
    public static function comparisonName(?string $value,bool $variants=true): string
    {
        $parts=explode(' ',mb_strtolower(trim(preg_replace('/[\s\p{Z}]+/u',' ',(string)$value)??''),'UTF-8'));
        if(count($parts)<2)return implode(' ',$parts);
        $last=array_pop($parts);$first=array_shift($parts);
        $parts=array_values(array_filter($parts,static fn($part)=>!preg_match('/\A\p{L}\.?\z/u',$part)));
        if($variants){$rules=self::nameRules();if(!isset($rules['ambiguous'][$first]))foreach($rules['families'] as $formal=>$aliases)if($first===$formal||in_array($first,$aliases,true)){$first=$formal;break;}}
        return implode(' ',[$first,...$parts,$last]);
    }
    private static function nameRules(): array { static $rules;return $rules??=require dirname(__DIR__,2).'/config/name-variants.php'; }
    public static function ambiguousName(?string $value): bool
    {
        $first=explode(' ',self::comparisonName($value,false))[0];return isset(self::nameRules()['ambiguous'][$first]);
    }
    public static function namesCompatible(?string $a,?string $b,bool $allowAmbiguous=false): bool
    {
        if(self::comparisonName($a)!==''&&self::comparisonName($a)===self::comparisonName($b))return true;
        if(!$allowAmbiguous)return false;
        $left=explode(' ',self::comparisonName($a,false));$right=explode(' ',self::comparisonName($b,false));
        if(count($left)<2||array_slice($left,1)!==array_slice($right,1))return false;
        $rules=self::nameRules()['ambiguous'];return in_array($right[0],$rules[$left[0]]??[],true)||in_array($left[0],$rules[$right[0]]??[],true);
    }
    private function name(?string $value): string { return self::comparisonName($value); }

    private function last(string $name): string
    {
        $parts = explode(' ', $name);
        return count($parts) >= 2 ? end($parts) : '';
    }
}

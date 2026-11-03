<?php
declare(strict_types=1);
/** Counts and code labels only; never expose household source values in bulk review receipts. */
function renderBulkMatchPreview(?array $preview,?array $pending,?string $error=null,?array $success=null): void
{
    ?>
    <div class="match-review bulk-preview">
        <header class="review-page-header"><div><p class="eyebrow">BMA ANALYTICS / ADMINISTRATOR REVIEW</p><h1>Bulk Confirm Exact Matches</h1><p>Read-only preview of the owner-approved exact name and email rules. No decisions are saved until you confirm below.</p></div><a class="btn btn-outline-secondary" href="matches.php">Back to review queue</a></header>
        <?php if($error!==null): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
        <?php if($success!==null): ?><div class="alert alert-success" role="status"><?= e($success['eligible_candidates']) ?> candidate decisions confirmed and audited. <?= e($success['people_to_create']) ?> people and <?= e($success['members_to_create']) ?> members created.</div><?php endif; ?>
        <section class="bulk-panel"><h2>Rules used for this preview</h2><ul>
            <li><strong>Payer and signer:</strong> nonempty full names match after case and whitespace normalization; nonempty emails match after case-insensitive trimming. This rule alone creates no student or member association.</li>
            <li><strong>Self-payer/student:</strong> the payer/signer rule also holds, the native form explicitly labels the same student name, and the relationship is Self. Known minor ages, conflicting dates of birth and contradictory identities are excluded.</li>
            <li><strong>One identity per group:</strong> repeated waivers and identical payer accounts reuse one unambiguous BMA person. Different existing BMA people, rejected decisions and manual holds are excluded. Household contacts are never copied automatically.</li>
        </ul><p class="mb-0">The preview covers the entire candidate queue, independent of search filters. Scores and similarity do not authorize bulk confirmation.</p></section>
        <?php if($preview!==null&&$pending!==null): $s=$preview['summary']; ?>
            <dl class="bulk-metrics">
                <?php foreach(['eligible_candidates'=>'Eligible candidate decisions','payer_signer_candidates'=>'Exact payer/signer candidates (Rule A)','self_student_candidates'=>'Exact self-payer/student candidates (Rule B)','people_to_create'=>'Unique BMA people to create','people_to_reuse'=>'Existing BMA people to reuse','members_to_create'=>'Member records to create','additional_waivers'=>'Additional waivers sharing a group identity','associations_to_confirm'=>'Identity associations to confirm','excluded_candidates'=>'Excluded candidate records','remaining_manual'=>'Candidates remaining for manual review','already_decided'=>'Already accepted candidates (unchanged)','total_candidates'=>'Total candidate records'] as $key=>$label): ?>
                    <div class="bulk-metric"><dt><?= e($label) ?></dt><dd><?= e($s[$key]) ?></dd></div>
                <?php endforeach; ?>
            </dl>
            <section class="bulk-panel"><h2>Excluded records</h2>
                <?php if($preview['exclusions']===[]): ?><p class="mb-0">No excluded candidates in this preview.</p><?php else: ?>
                    <ul class="mb-0"><?php $labels=['person_name_requires_manual'=>'Source name exceeds supported BMA name lengths','missing_or_invalid_name_email'=>'Missing names or missing/invalid email','payer_signer_name_email_differ'=>'Payer and signer full name/email differ','student_rule_not_met'=>'Student comparison does not meet the native Self rule','self_student_age_conflict'=>'Self-student age contradicts adult identity','manual_identity_rejection'=>'Existing rejected identity decision','manual_identity_review_pending'=>'Existing identity review hold','identity_cache_conflict'=>'Authoritative links and cached associations disagree','multiple_existing_people'=>'Multiple existing BMA people in one group','invalid_existing_person'=>'Invalid or unfinished existing BMA person','existing_person_evidence_conflict'=>'Existing person has contradictory name/email evidence','conflicting_birth_dates'=>'Conflicting dates of birth','manual_candidate_decision'=>'Existing manual rejection, deferral or reviewed decision','confirmed_candidate_link_conflict'=>'Accepted candidate links are inconsistent','candidate_conflict'=>'Candidate has a conflict status','hard_or_contradictory_evidence'=>'Hard or contradictory evidence']; ?>
                        <?php foreach($preview['exclusions'] as $reason=>$count): ?><li><strong><?= e($count) ?></strong> &mdash; <?= e($labels[$reason]??str_replace('_',' ',$reason)) ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
                <p class="form-text mt-3 mb-0">Excluded counts include protected rejections; rejected candidates are not included in the remaining open manual-review count. Additional waivers count distinct selected submissions beyond the first within each group.</p>
            </section>
            <section class="bulk-panel bulk-confirm" aria-labelledby="bulk-confirm-title"><h2 id="bulk-confirm-title">Confirm <?= e($s['eligible_candidates']) ?> candidate decisions</h2>
                <p>This will create <?= e($s['people_to_create']) ?> people and <?= e($s['members_to_create']) ?> members, reuse <?= e($s['people_to_reuse']) ?> existing people, and confirm <?= e($s['associations_to_confirm']) ?> identity associations. Candidate decisions, link changes and audit entries are committed together. Existing financial data and original source values are not edited.</p>
                <?php if($s['eligible_candidates']>0): ?>
                    <form method="post" action="bulk-matches.php"><input type="hidden" name="csrf" value="<?= e($_SESSION['match_review_csrf']) ?>"><input type="hidden" name="bulk_action" value="confirm"><input type="hidden" name="operation_id" value="<?= e($pending['operation']) ?>"><input type="hidden" name="confirm_count" value="<?= e($s['eligible_candidates']) ?>">
                        <div class="review-checkbox"><input type="checkbox" class="form-check-input" id="confirm-exact" name="confirm_exact" value="1" required><label for="confirm-exact">I reviewed these counts and authorize all <?= e($s['eligible_candidates']) ?> exact-match candidate decisions under the stated rules.</label></div>
                        <button type="submit" class="btn btn-primary">Confirm <?= e($s['eligible_candidates']) ?> exact matches</button>
                    </form><p class="form-text mt-3 mb-0">Preview expires after 15 minutes. If records change, nothing is confirmed and a fresh preview is required.</p>
                <?php else: ?><p class="mb-0">No bulk action is available. Continue with individual review.</p><?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
    <?php
}

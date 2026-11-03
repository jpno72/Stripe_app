<?php
declare(strict_types=1);

/** Presentation only. Candidate interpretation and decisions remain in the matching services. */
function reviewBadge(string $status): string
{
    $labels = ['possible'=>['warning','Review'], 'unmatched'=>['secondary','Unmatched'], 'conflict'=>['danger','Conflict'],
        'confirmed'=>['success','Confirmed'], 'linked'=>['success','Confirmed'], 'rejected'=>['dark','Rejected']];
    [$color, $label] = $labels[$status] ?? ['secondary', $status];
    return '<span class="badge rounded-pill text-bg-' . e($color) . '">' . e($label) . '</span>';
}

function reviewIcon(string $kind): string
{
    $path = match ($kind) {
        'support' => '<path d="m5 12 4 4 10-10"/>',
        'conflict' => '<path d="m12 3 10 18H2L12 3Z"/><path d="M12 9v5m0 3v.1"/>',
        default => '<circle cx="12" cy="12" r="9"/><path d="M12 11v6m0-10v.1"/>',
    };
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
}

function reviewEvidence(string $json, array $waiver = []): string
{
    $data = json_decode($json, true);
    $data = is_array($data) ? $data : [];
    $labels = [
        'exact_full_name'=>'Full name matches', 'exact_last_name'=>'Last name matches', 'similar_name_only'=>'Names are similar',
        'exact_email'=>'Email matches', 'exact_phone'=>'Phone matches', 'exact_postal'=>'Postal code matches',
        'exact_address'=>'Address matches', 'exact_birth_date'=>'Birth date matches',
        'possible_parent_student_structure'=>'Form suggests a parent/student structure',
        'explicit_student_name'=>'Form explicitly labels this name as the student',
        'student_identity_unverified'=>'Student BMA identity requires administrator review',
        'different_full_name'=>'Full names differ', 'different_last_name'=>'Last names differ',
        'conflicting_birth_date'=>'Birth dates conflict', 'source_name_role_unverified'=>'Form Name role is unverified',
        'self_label_names_disagree'=>'Self label conflicts with differing names',
    ];
    if (WaiverSourceRole::isStudent($waiver)) {
        $labels['source_name_role_unverified'] = !empty($waiver['student_identity_reviewed'])
            ? 'Historical review flag; student BMA identity now has administrator confirmation'
            : 'Form labels the student; BMA identity still requires administrator review';
    }
    $groups = ['support'=>[], 'conflict'=>[], 'unverified'=>[]];
    foreach (['signals'=>'support', 'conflicts'=>'conflict'] as $key=>$kind) {
        foreach (is_array($data[$key] ?? null) ? $data[$key] : [] as $code) {
            if (!is_string($code)) { continue; }
            $target = in_array($code, ['student_identity_unverified','source_name_role_unverified'], true) ? 'unverified' : $kind;
            $groups[$target][] = $labels[$code] ?? $code;
        }
    }
    $html = '<div class="review-evidence-grid">';
    foreach (['support'=>'Supporting evidence', 'conflict'=>'Conflicting evidence', 'unverified'=>'Missing or unverified'] as $kind=>$title) {
        if ($groups[$kind] === [] && $kind === 'unverified') { continue; }
        $html .= '<section class="review-evidence review-evidence--' . $kind . '"><h4>' . reviewIcon($kind) . e($title) . '</h4><ul>';
        if ($groups[$kind] === []) { $html .= '<li class="review-muted">None recorded.</li>'; }
        foreach ($groups[$kind] as $text) { $html .= '<li>' . e($text) . '</li>'; }
        $html .= '</ul></section>';
    }
    return $html . '</div>';
}

function reviewReason(string $reason, array $waiver = []): string
{
    if (WaiverSourceRole::isStudent($waiver) && in_array($reason, ['source_name_role_unverified','student_identity_unverified'], true)) {
        return !empty($waiver['student_identity_reviewed']) ? 'Student BMA identity has administrator confirmation' : 'Student BMA identity needs administrator review';
    }
    $labels = ['student_identity_unverified'=>'Student BMA identity needs administrator review', 'manual_review_pending'=>'Held for administrator review',
        'source_name_role_unverified'=>'Form Name role needs review', 'self_label_names_disagree'=>'Self label conflicts with names',
        'manual_rejection'=>'Previously rejected identity', 'existing_links_same_person'=>'Existing links identify one person',
        'existing_links_different_people'=>'Existing links identify different people', 'insufficient_evidence'=>'Insufficient evidence',
        'review_signals'=>'Multiple review signals', 'contact_or_name_conflict'=>'Contact or name conflict', 'multiple_strong_signals'=>'Multiple strong signals'];
    return $labels[$reason] ?? str_replace('_', ' ', $reason);
}

function reviewPersonField(string $prefix, string $name, string $label, string $help = ''): void
{
    $id = $prefix . '-' . $name;
    ?>
    <div class="review-field">
        <label class="form-label" for="<?= e($id) ?>"><?= e($label) ?></label>
        <input id="<?= e($id) ?>" name="<?= e($name) ?>" class="form-control" placeholder="BMA-000001"<?= $help !== '' ? ' aria-describedby="' . e($id . '-help') . '"' : '' ?>>
        <?php if ($help !== ''): ?><div class="form-text" id="<?= e($id . '-help') ?>"><?= e($help) ?></div><?php endif; ?>
    </div>
    <?php
}

function renderMatchReview(array $queue, string $search, string $status, int $page, callable $historyFor): void
{
    ?>
    <div class="match-review">
        <header class="review-page-header">
            <div><p class="eyebrow">BMA ANALYTICS / IDENTITY REVIEW</p><h1>Identity match review</h1>
                <p>Compare payer and waiver records. Confirm identities and relationships with an auditable decision.</p></div>
            <div class="review-header-actions"><a class="btn btn-primary" href="/admin/bulk-matches.php">Bulk Confirm Exact Matches</a><div class="review-total"><strong><?= e(number_format((int)$queue['total'])) ?></strong><span>candidates found</span></div></div>
        </header>
        <?php foreach (['match_review_notice'=>'success', 'match_review_error'=>'danger'] as $key=>$type): ?>
            <?php if (isset($_SESSION[$key])): ?><div class="alert alert-<?= e($type) ?>" role="<?= $type === 'danger' ? 'alert' : 'status' ?>"><?= e($_SESSION[$key]) ?></div><?php unset($_SESSION[$key]); endif; ?>
        <?php endforeach; ?>
        <form method="get" class="review-filter" aria-label="Filter match candidates">
            <div class="review-field"><label for="q" class="form-label">Search people and records</label>
                <input id="q" class="form-control" name="q" maxlength="100" value="<?= e($search) ?>" placeholder="Name, email, phone, Stripe ID or BMA ID"></div>
            <div class="review-field"><label for="status" class="form-label">Candidate status</label><select id="status" name="status" class="form-select">
                <?php foreach (['open'=>'Open queue', 'possible'=>'Review', 'unmatched'=>'Unmatched', 'conflict'=>'Conflict', 'confirmed'=>'Confirmed', 'rejected'=>'Rejected', 'all'=>'All'] as $key=>$label): ?>
                    <option value="<?= e($key) ?>"<?= $status === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?></select></div>
            <button class="btn btn-primary" type="submit">Apply filters</button>
        </form>
        <?php if ($queue['rows'] === []): ?>
            <section class="review-empty"><h2>No candidates found</h2><p>Try another search or candidate status.</p><a class="btn btn-outline-primary" href="matches.php">Clear filters</a></section>
        <?php endif; ?>
        <?php foreach ($queue['rows'] as $row):
            $score = (int)round((float)$row['match_score'] * 100);
            $comparison = WaiverSourceRole::comparison($row, $row['waiver_role']);
            $comparisonLabel = $comparison === 'signer' ? 'Signer comparison' : ($comparison === 'student'
                ? 'Student comparison / ' . (!empty($row['student_identity_reviewed']) ? 'identity reviewed' : 'BMA identity unverified') : 'Form Name role unresolved');
            $waiverName = $row['waiver_role'] === 'signer' ? $row['signer_print_name'] : $row['source_name'];
            $prefix = 'candidate-' . (int)$row['id'];
            ?>
            <article class="review-candidate" aria-labelledby="<?= e($prefix . '-title') ?>">
                <header class="review-candidate-header">
                    <div class="review-candidate-title"><h2 id="<?= e($prefix . '-title') ?>">Candidate #<?= e($row['id']) ?></h2><p><?= e($comparisonLabel) ?></p></div>
                    <div class="review-badges"><?= reviewBadge((string)$row['match_status']) ?>
                        <?php if ($score >= 90 && !$row['hard_conflict'] && in_array($row['match_status'], ['possible','linked','confirmed'], true)): ?><span class="badge rounded-pill text-bg-primary">High confidence</span><?php endif; ?>
                    </div>
                </header>
                <div class="review-comparison">
                    <section class="review-source"><p class="review-kicker">STRIPE / PAYER ACCOUNT</p><h3><?= e($row['stripe_name'] ?: 'Name unavailable') ?></h3>
                        <dl class="review-facts">
                            <div><dt>Email</dt><dd><?= e($row['stripe_email'] ?: 'Email unavailable') ?></dd></div>
                            <div><dt>Phone</dt><dd class="review-muted">Not supplied in Stripe CSV</dd></div>
                            <div><dt>Billing address</dt><dd><?= e(trim((string)$row['address_line1'] . ' ' . (string)$row['address_city'] . ' ' . (string)$row['address_postal_code']) ?: 'Address unavailable') ?></dd></div>
                            <div><dt>Stripe customer</dt><dd><code><?= e($row['stripe_customer_id']) ?></code></dd></div>
                            <div><dt>BMA person</dt><dd><?= e($row['payer_bma'] ?: 'No BMA person linked') ?></dd></div>
                        </dl>
                    </section>
                    <section class="review-source"><p class="review-kicker">COGNITO / <?= e($comparison === 'signer' ? 'SIGNER' : ($comparison === 'student' ? 'STUDENT (SOURCE LABEL)' : 'UNRESOLVED NAME')) ?></p><h3><?= e($waiverName ?: 'Name unavailable') ?></h3>
                        <dl class="review-facts">
                            <div><dt>Contact email</dt><dd><?= e($row['contact_email'] ?: 'Email unavailable') ?><small>Contact owner unverified</small></dd></div>
                            <div><dt>Contact phone</dt><dd><?= e($row['contact_phone'] ?: 'Phone unavailable') ?><small>Contact owner unverified</small></dd></div>
                            <div><dt>Address</dt><dd><?= e($row['address_text'] ?: 'Address unavailable') ?></dd></div>
                            <div><dt>Form relationship</dt><dd><?= e($row['relationship_to_student'] ?: 'Not supplied') ?></dd></div>
                            <div><dt>BMA associations</dt><dd>Signer: <?= e($row['signer_bma'] ?: 'unlinked') ?><br>Student: <?= e($row['student_bma'] ?: 'unlinked') ?></dd></div>
                        </dl>
                    </section>
                    <section class="review-assessment"><h3>Match assessment</h3><p class="review-score-label">Confidence score</p>
                        <div class="review-score"><?= e($score) ?><span> / 100</span></div>
                        <p class="review-assessment-reason"><?= e(reviewReason((string)$row['match_reason'], $row)) ?></p>
                        <p class="review-muted">Comparison evidence, not proof of identity.</p>
                    </section>
                </div>
                <div class="review-evidence-wrap"><h3>Evidence and identity verification</h3>
                    <p class="review-identity-notice"><?= reviewIcon('unverified') ?><span><?= e(WaiverSourceRole::identityNotice($row)) ?></span></p>
                    <?= reviewEvidence((string)$row['evidence'], $row) ?>
                </div>
                <section class="review-decision" aria-labelledby="<?= e($prefix . '-decision-title') ?>">
                    <div class="review-decision-heading"><h3 id="<?= e($prefix . '-decision-title') ?>">Review decision</h3><p>Choose an action after reviewing both records. Changes and notes are audited.</p></div>
                    <form method="post" class="review-form">
                        <input type="hidden" name="csrf" value="<?= e($_SESSION['match_review_csrf']) ?>"><input type="hidden" name="candidate_id" value="<?= e($row['id']) ?>">
                        <div class="review-decision-grid">
                            <div class="review-field"><label class="form-label" for="<?= e($prefix . '-action') ?>">Decision</label><select id="<?= e($prefix . '-action') ?>" name="action" class="form-select action-select" required><option value="">Choose an action</option>
                                <?php foreach (['confirm_same'=>'Confirm same person', 'confirm_relationship'=>'Confirm payer/member relationship', 'link_existing'=>'Link to existing person', 'create_separate'=>'Create new separate person', 'reject'=>'Reject proposed match', 'later'=>'Mark for later review', 'reopen'=>'Undo / reopen prior decision'] as $key=>$label): ?>
                                    <option value="<?= e($key) ?>"><?= e($label) ?></option>
                                <?php endforeach; ?></select></div>
                            <?php reviewPersonField($prefix, 'person_bma', 'Existing BMA person ID', 'For same-person or link-existing actions. Leave blank to create for same-person.'); ?>
                            <div class="review-field"><label class="form-label" for="<?= e($prefix . '-side') ?>">Link side</label><select id="<?= e($prefix . '-side') ?>" name="side" class="form-select"><option value="">Choose a side</option><option value="stripe">Stripe payer</option><option value="signer">Cognito signer</option><option value="student">Cognito student</option></select><div class="form-text">For link-existing or create-separate actions.</div></div>
                        </div>
                        <fieldset class="review-household-fields"><legend>People for a payer/member relationship</legend><p>Use the BMA IDs of the people you have verified.</p>
                            <div class="review-decision-grid"><?php reviewPersonField($prefix, 'payer_bma', 'Payer BMA ID'); reviewPersonField($prefix, 'signer_bma', 'Signer BMA ID'); reviewPersonField($prefix, 'student_bma', 'Student BMA ID'); ?></div>
                            <div class="review-checkbox"><input id="<?= e($prefix . '-verified') ?>" type="checkbox" name="confirm_source_relationship" value="1" class="form-check-input"><label for="<?= e($prefix . '-verified') ?>">I verified the form relationship and these signer/student roles</label></div>
                        </fieldset>
                        <div class="review-field"><label class="form-label" for="<?= e($prefix . '-notes') ?>">Review notes</label><textarea id="<?= e($prefix . '-notes') ?>" name="notes" class="form-control" maxlength="2000" rows="3" placeholder="Explain why this decision is correct"></textarea></div>
                        <div class="review-submit"><p>New people do not inherit shared contact details. You will be asked to confirm this decision.</p><button class="btn btn-primary" type="submit">Review decision</button></div>
                    </form>
                    <?php $history = $historyFor((int)$row['id']); if ($history !== []): ?>
                        <details class="review-history"><summary>Decision history (<?= e(count($history)) ?> recent)</summary><ol>
                            <?php foreach ($history as $event): $meta = json_decode((string)$event['metadata'], true) ?: []; ?>
                                <li><time><?= e(AppTime::display($event['created_at'])) ?></time><span><?= e($event['display_name'] ?: 'Administrator') ?></span><span><?= e($meta['action'] ?? $event['action']) ?></span>
                                    <span><?= e($meta['before']['status'] ?? '?') ?> <span aria-label="changed to">&rarr;</span> <?= e($meta['after']['status'] ?? '?') ?></span>
                                    <?php if (($meta['notes'] ?? '') !== ''): ?><p><?= e($meta['notes']) ?></p><?php endif; ?>
                                </li>
                            <?php endforeach; ?></ol></details>
                    <?php endif; ?>
                </section>
            </article>
        <?php endforeach; ?>
        <?php if ($queue['total'] > 25): ?><nav aria-label="Queue pages" class="review-pagination">
            <?php if ($page > 1): ?><a class="btn btn-outline-secondary" href="?<?= e(http_build_query(['q'=>$search,'status'=>$status,'page'=>$page-1])) ?>">Previous</a><?php endif; ?>
            <span>Page <?= e($page) ?></span>
            <?php if ($page*25 < $queue['total']): ?><a class="btn btn-outline-secondary" href="?<?= e(http_build_query(['q'=>$search,'status'=>$status,'page'=>$page+1])) ?>">Next</a><?php endif; ?>
        </nav><?php endif; ?>
    </div>
    <script src="/assets/js/match-review.js" defer></script>
    <?php
}

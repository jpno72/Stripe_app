<?php
declare(strict_types=1);
require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__, 2) . '/app/Matching/MatchReviewService.php';
$config = adminConfig('admin');requireDashboardAccess('matches');
$db = bmaDatabase($config);
$actor = adminReviewActor($db,$config);
$service = new MatchReviewService($db);
$_SESSION['match_review_csrf'] ??= bin2hex(random_bytes(32));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Security::csrf($_POST['csrf']??null);
    $candidate = filter_input(INPUT_POST,'candidate_id',FILTER_VALIDATE_INT);
    $action = (string)($_POST['action'] ?? '');
    try {
        $service->decide((int)$candidate,$action,$actor,$_POST);
        $_SESSION['match_review_notice'] = 'Decision saved and audited.';
    } catch (RuntimeException $e) {
        $messages = [
            'BMA_PERSON_ID_REQUIRED'=>'Enter a valid BMA person ID.', 'PERSON_NOT_FOUND'=>'The BMA person ID was not found.',
            'NAME_REQUIRED_FOR_NEW_PERSON'=>'A source name is required to create a person.',
            'EXISTING_PERSON_ASSOCIATION_CONFLICT'=>'This record is already linked to another person. Reopen that decision first.',
            'SEPARATE_PAYER_STUDENT_REQUIRED'=>'Choose separate payer and student people.',
            'SOURCE_RELATION_UNSUPPORTED'=>'The source relationship cannot be confirmed for these people.',
            'REOPEN_PRIOR_DECISION_FIRST'=>'Reopen the prior decision before changing it.',
            'NO_REVERSIBLE_REVIEW_DECISION'=>'There is no Step 4 decision to reopen.',
            'REVIEW_STATE_CHANGED'=>'The linked records changed since that decision. Review them before undoing it.',
            'REVIEW_DEPENDENCY_EXISTS'=>'A later review relies on this decision. Reopen that later decision first.',
            'ACCEPTED_LINKS_REQUIRE_REVIEW'=>'Accepted links already identify the same person. Reopen those links before rejecting.',
            'NOTES_TOO_LONG'=>'Notes must be 2,000 characters or fewer.',
            'ADMIN_REVIEW_REQUIRED'=>'An active administrator is required.',
        ];
        $_SESSION['match_review_error'] = $messages[$e->getMessage()] ?? 'The decision could not be saved. Review the candidate and linked people.';
    } catch (Throwable) {
        $_SESSION['match_review_error'] = 'The decision could not be saved. Review the candidate and linked people.';
    }
    header('Location: matches.php',true,303); exit;
}

$search = trim((string)($_GET['q'] ?? ''));
$status = (string)($_GET['status'] ?? 'open');
$page = max(1,(int)($_GET['page'] ?? 1));
try { $queue = $service->queue($search,$status,$page); }
catch (RuntimeException) { $search = ''; $status = 'open'; $page = 1; $queue = $service->queue(); }

function reviewBadge(string $status): string
{
    $labels = ['possible'=>['warning','Review'],'unmatched'=>['secondary','Unmatched'],'conflict'=>['danger','Conflict'],
        'confirmed'=>['success','Confirmed'],'linked'=>['success','Confirmed'],'rejected'=>['dark','Rejected']];
    [$color,$label] = $labels[$status] ?? ['secondary',$status];
    return '<span class="badge text-bg-' . e($color) . '">' . e($label) . '</span>';
}
function reviewEvidence(string $json): string
{
    $data = json_decode($json,true) ?: [];
    $labels = [
        'exact_full_name'=>'Full name matches','exact_last_name'=>'Last name matches','similar_name_only'=>'Names are similar',
        'exact_email'=>'Email matches','exact_phone'=>'Phone matches','exact_postal'=>'Postal code matches',
        'exact_address'=>'Address matches','exact_birth_date'=>'Birth date matches',
        'possible_parent_student_structure'=>'Form suggests a parent/student structure',
        'different_full_name'=>'Full names differ','different_last_name'=>'Last names differ',
        'conflicting_birth_date'=>'Birth dates conflict','source_name_role_unverified'=>'Form Name role is unverified',
        'self_label_names_disagree'=>'Self label conflicts with differing names',
    ];
    $html = '';
    foreach (['signals'=>['âœ“','text-success'],'conflicts'=>['âš ','text-danger']] as $kind=>$decor) {
        foreach ($data[$kind] ?? [] as $code) { $html .= '<li><span class="' . $decor[1] . '" aria-hidden="true">' . $decor[0] . '</span> ' . e($labels[$code] ?? $code) . '</li>'; }
    }
    return $html === '' ? '<li>No supporting evidence recorded.</li>' : $html;
}
function reviewReason(string $reason): string
{
    $labels = ['manual_review_pending'=>'Held for administrator review','source_name_role_unverified'=>'Form Name role needs review',
        'self_label_names_disagree'=>'Self label conflicts with names','manual_rejection'=>'Previously rejected identity',
        'existing_links_same_person'=>'Existing links identify one person','existing_links_different_people'=>'Existing links identify different people',
        'insufficient_evidence'=>'Insufficient evidence','review_signals'=>'Multiple review signals',
        'contact_or_name_conflict'=>'Contact or name conflict','multiple_strong_signals'=>'Multiple strong signals'];
    return $labels[$reason] ?? str_replace('_',' ',$reason);
}

adminHead('Identity match review');
echo '<header class="review-hero mb-4"><div class="d-flex flex-wrap justify-content-between gap-3 align-items-end"><div><p class="text-uppercase small fw-bold mb-2">BMA Analytics Â· Identity review</p><h1 class="h2 mb-2">Resolve payer and waiver identities</h1><p class="mb-0">Compare the source records, confirm people and relationships, and keep a record of every decision.</p></div><div class="fs-4 fw-bold">' . e($queue['total']) . ' results</div></div></header>';
if (isset($_SESSION['match_review_notice'])) { echo '<div class="alert alert-success" role="status">' . e($_SESSION['match_review_notice']) . '</div>'; unset($_SESSION['match_review_notice']); }
if (isset($_SESSION['match_review_error'])) { echo '<div class="alert alert-danger" role="alert">' . e($_SESSION['match_review_error']) . '</div>'; unset($_SESSION['match_review_error']); }
echo '<form method="get" class="card card-body mb-4 border-0 shadow-sm"><div class="row g-3 align-items-end"><div class="col-lg-8"><label for="q" class="form-label fw-semibold">Search people and records</label><input id="q" class="form-control" name="q" maxlength="100" value="' . e($search) . '" placeholder="Name, email, phone, Stripe customer ID, or BMA person ID"></div><div class="col-lg-2"><label for="status" class="form-label fw-semibold">Status</label><select id="status" name="status" class="form-select">';
foreach (['open'=>'Open queue','possible'=>'Review','unmatched'=>'Unmatched','conflict'=>'Conflict','confirmed'=>'Confirmed','rejected'=>'Rejected','all'=>'All'] as $key=>$label) { echo '<option value="' . e($key) . '"' . ($status === $key ? ' selected' : '') . '>' . e($label) . '</option>'; }
echo '</select></div><div class="col-lg-2"><button class="btn btn-primary w-100">Search</button></div></div></form>';
if ($queue['rows'] === []) { echo '<div class="card card-body text-center py-5"><h2 class="h5">No candidates found</h2><p class="text-secondary mb-0">Try another search or status.</p></div>'; }
foreach ($queue['rows'] as $row) {
    $score = (int)round((float)$row['match_score']*100);
    $waiverName = $row['waiver_role'] === 'signer' ? $row['signer_print_name'] : $row['source_name'];
    echo '<article class="match-card bg-white mb-4 overflow-hidden"><div class="p-4"><div class="d-flex flex-wrap gap-2 align-items-center mb-3">' . reviewBadge((string)$row['match_status']);
    if ($score >= 90 && !$row['hard_conflict'] && in_array($row['match_status'],['possible','linked','confirmed'],true)) { echo '<span class="badge text-bg-primary">High confidence</span>'; }
    echo '<span class="small text-secondary">Candidate #' . e($row['id']) . ' Â· ' . e($row['waiver_role'] === 'signer' ? 'Signer comparison' : 'Form Name role unresolved') . '</span></div>';
    echo '<div class="row g-4"><section class="col-lg-5"><div class="side-label mb-2">STRIPE Â· PAYER ACCOUNT</div><h2 class="h4 mb-1">' . e($row['stripe_name'] ?: 'Name unavailable') . '</h2><div class="detail">' . e($row['stripe_email'] ?: 'Email unavailable') . '<br>Phone not supplied in Stripe CSV<br>' . e(trim((string)$row['address_line1'] . ' ' . (string)$row['address_city'] . ' ' . (string)$row['address_postal_code']) ?: 'Address unavailable') . '</div><div class="small mt-2">' . e($row['stripe_customer_id']) . ' Â· ' . e($row['payer_bma'] ?: 'No BMA person linked') . '</div></section>';
    echo '<section class="col-lg-5"><div class="side-label mb-2">COGNITO Â· ' . e($row['waiver_role'] === 'signer' ? 'SIGNER' : 'UNRESOLVED NAME') . '</div><h2 class="h4 mb-1">' . e($waiverName ?: 'Name unavailable') . '</h2><div class="detail">' . e($row['contact_email'] ?: 'Email unavailable') . ' <small>(owner unverified)</small><br>' . e($row['contact_phone'] ?: 'Phone unavailable') . ' <small>(owner unverified)</small><br>' . e($row['address_text'] ?: 'Address unavailable') . '</div><div class="small mt-2">Signer: ' . e($row['signer_bma'] ?: 'unlinked') . ' Â· Student: ' . e($row['student_bma'] ?: 'unlinked') . '</div></section>';
    echo '<div class="col-lg-2"><div class="side-label mb-2">CONFIDENCE</div><div class="score">' . e($score) . '<span class="fs-6"> / 100</span></div><div class="small text-secondary mt-2">' . e(reviewReason((string)$row['match_reason'])) . '</div></div></div>';
    echo '<div class="row mt-3"><div class="col-lg-5"><strong>Relationship in form:</strong> ' . e($row['relationship_to_student'] ?: 'Not supplied') . '<p class="small text-secondary mb-0">The formâ€™s Name field and household contact ownership require review.</p></div><div class="col-lg-7"><strong>Evidence and conflicts</strong><ul class="evidence-list small mt-2 mb-0">' . reviewEvidence((string)$row['evidence']) . '</ul></div></div></div>';
    echo '<div class="match-action p-4 bg-light"><form method="post" class="review-form"><input type="hidden" name="csrf" value="' . e($_SESSION['match_review_csrf']) . '"><input type="hidden" name="candidate_id" value="' . e($row['id']) . '"><div class="row g-3"><div class="col-lg-4"><label class="form-label fw-semibold">Decision</label><select name="action" class="form-select action-select" required><option value="">Choose actionâ€¦</option>';
    foreach (['confirm_same'=>'Confirm same person','confirm_relationship'=>'Confirm payer/member relationship','link_existing'=>'Link to existing person','create_separate'=>'Create new separate person','reject'=>'Reject proposed match','later'=>'Mark for later review','reopen'=>'Undo / reopen prior decision'] as $key=>$label) { echo '<option value="' . e($key) . '">' . e($label) . '</option>'; }
    echo '</select></div><div class="col-lg-4"><label class="form-label">Existing BMA person ID</label><input name="person_bma" class="form-control" placeholder="BMA-000001"><div class="form-text">For same-person or link-existing actions; leave blank to create for same-person.</div></div><div class="col-lg-4"><label class="form-label">Link side</label><select name="side" class="form-select"><option value="">Choose sideâ€¦</option><option value="stripe">Stripe payer</option><option value="signer">Cognito signer</option><option value="student">Cognito student</option></select></div>';
    echo '<div class="col-lg-4"><label class="form-label">Payer BMA ID</label><input name="payer_bma" class="form-control" placeholder="BMA-000001"></div><div class="col-lg-4"><label class="form-label">Signer BMA ID</label><input name="signer_bma" class="form-control" placeholder="BMA-000001"></div><div class="col-lg-4"><label class="form-label">Student BMA ID</label><input name="student_bma" class="form-control" placeholder="BMA-000002"></div><div class="col-12"><label class="form-check"><input type="checkbox" name="confirm_source_relationship" value="1" class="form-check-input"><span class="form-check-label">I verified the form relationship and these signer/student roles</span></label></div><div class="col-12"><label class="form-label">Review notes</label><textarea name="notes" class="form-control" maxlength="2000" rows="2" placeholder="Why this decision is correct"></textarea></div><div class="col-12 d-flex justify-content-between align-items-center"><small class="text-secondary">Decisions and notes are audited. New people do not inherit shared contact details.</small><button class="btn btn-primary" type="submit">Review decision</button></div></div></form>';
    $history = $service->history((int)$row['id']);
    if ($history !== []) { echo '<details class="mt-3"><summary class="small fw-semibold">Decision history (' . e(count($history)) . ' recent)</summary><ol class="small mt-2">'; foreach ($history as $event) { $meta = json_decode((string)$event['metadata'],true) ?: []; echo '<li>' . e($event['created_at']) . ' Â· ' . e($event['display_name'] ?: 'Administrator') . ' Â· ' . e($meta['action'] ?? $event['action']) . ' Â· ' . e($meta['before']['status'] ?? '?') . ' â†’ ' . e($meta['after']['status'] ?? '?'); if (($meta['notes'] ?? '') !== '') { echo ' Â· ' . e($meta['notes']); } echo '</li>'; } echo '</ol></details>'; }
    echo '</div></article>';
}
if ($queue['total'] > 25) { echo '<nav aria-label="Queue pages" class="d-flex gap-2 justify-content-center mb-4">'; if ($page > 1) { echo '<a class="btn btn-outline-secondary" href="?' . e(http_build_query(['q'=>$search,'status'=>$status,'page'=>$page-1])) . '">Previous</a>'; } if ($page*25 < $queue['total']) { echo '<a class="btn btn-outline-secondary" href="?' . e(http_build_query(['q'=>$search,'status'=>$status,'page'=>$page+1])) . '">Next</a>'; } echo '</nav>'; }
echo '<script src="/assets/js/match-review.js" defer></script>';
adminFoot();

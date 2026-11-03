<?php
declare(strict_types=1);
require_once __DIR__ . '/_common.php';
require_once APP_ROOT . '/app/Matching/MatchReviewService.php';
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

require_once APP_ROOT . '/app/Views/match-review.php';
adminHead('Identity match review', ['/assets/css/match-review.css?v=4']);
renderMatchReview($queue, $search, $status, $page, fn(int $id): array => $service->history($id));
adminFoot();

<?php
session_start();
require_once __DIR__ . '/../LOGIN_PAGE/access.php';
tokaRequireRole();
require_once __DIR__ . '/../LOGIN_PAGE/dbconnect.php';
require_once __DIR__ . '/freeze_poll_helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$userId = (int) ($_SESSION['user']['user_id'] ?? 0);
if ($userId < 1) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}

try {
    $pdo = getDbConnection();
    $groupId = filter_var($_GET['group_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$groupId) {
        http_response_code(400);
        echo json_encode(['error' => 'A valid group is required.']);
        exit;
    }

    $memberStmt = $pdo->prepare('SELECT 1 FROM group_members WHERE group_id = :group_id AND user_id = :user_id LIMIT 1');
    $memberStmt->execute([':group_id' => $groupId, ':user_id' => $userId]);
    if (!$memberStmt->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['error' => 'Group unavailable.']);
        exit;
    }

    $polls = tokaFreezePollsForUser($pdo, $userId, (int) $groupId);
    $activePoll = null;
    $unreadOutcome = null;
    foreach ($polls as $poll) {
        if ($poll['poll_status'] === 'Active' && $activePoll === null) $activePoll = $poll;
        if ($poll['poll_status'] !== 'Active' && $poll['outcome_seen_at'] === null && $unreadOutcome === null) {
            $unreadOutcome = $poll;
        }
    }

    echo json_encode([
        'fingerprint' => tokaFreezePollFingerprint($polls),
        'active_poll' => $activePoll ? [
            'poll_id' => (int) $activePoll['poll_id'],
            'poll_status' => $activePoll['poll_status'],
            'approvals' => (int) $activePoll['approvals'],
            'rejections' => (int) $activePoll['rejections'],
            'eligible_count' => (int) $activePoll['eligible_count'],
            'my_vote' => $activePoll['my_vote'],
        ] : null,
        'unread_outcome_id' => $unreadOutcome ? (int) $unreadOutcome['poll_id'] : null,
    ]);
} catch (Throwable $e) {
    error_log('Freeze poll status error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Freeze vote status is temporarily unavailable.']);
}

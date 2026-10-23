<?php
session_start();
require_once __DIR__ . '/../LOGIN_PAGE/access.php';
tokaRequireRole();
require_once __DIR__ . '/../LOGIN_PAGE/dbconnect.php';

$userId = (int) ($_SESSION['user']['user_id'] ?? 0);
$token = $_POST['csrf_token'] ?? '';
$action = is_string($_POST['poll_action'] ?? null) ? $_POST['poll_action'] : '';
$pollId = filter_var($_POST['poll_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$voteChoice = is_string($_POST['vote_choice'] ?? null) ? $_POST['vote_choice'] : '';
$message = '';
$pollGroupId = 0;

function freezePollRedirect(PDO $pdo, int $userId, int $pollGroupId, string $message): never
{
    if ($pollGroupId > 0) {
        $stmt = $pdo->prepare('SELECT 1 FROM group_members WHERE group_id = :group_id AND user_id = :user_id LIMIT 1');
        $stmt->execute([':group_id' => $pollGroupId, ':user_id' => $userId]);
        if ($stmt->fetchColumn()) {
            if ($message !== '') $_SESSION['group_dashboard_flash'][$pollGroupId] = $message;
            header('Location: group_dashboard.php?id=' . $pollGroupId, true, 303);
            exit;
        }
    }

    if ($message !== '') $_SESSION['group_success'] = $message;
    header('Location: my_groups.php', true, 303);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit;
    }
    if ($userId < 1 || !is_string($token) || empty($_SESSION['freeze_poll_csrf'])
        || !hash_equals($_SESSION['freeze_poll_csrf'], $token)) {
        throw new DomainException('This action expired. Refresh the page and try again.', 403);
    }
    if (!$pollId || !in_array($action, ['vote', 'ack_outcome'], true)) {
        throw new DomainException('That freeze request is no longer available.', 422);
    }

    $pdo = getDbConnection();
    $stmt = $pdo->prepare('SELECT group_id FROM freeze_polls WHERE poll_id = :poll_id');
    $stmt->execute([':poll_id' => $pollId]);
    $pollGroupId = (int) ($stmt->fetchColumn() ?: 0);
    if ($pollGroupId < 1) throw new DomainException('That freeze request is no longer available.', 404);

    if ($action === 'ack_outcome') {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT poll_status FROM freeze_polls WHERE poll_id = :poll_id FOR UPDATE');
        $stmt->execute([':poll_id' => $pollId]);
        $pollStatus = $stmt->fetchColumn();
        if (!in_array($pollStatus, ['Passed', 'Failed'], true)) {
            throw new DomainException('This freeze request has no final result yet.', 409);
        }
        $stmt = $pdo->prepare('SELECT 1 FROM freeze_poll_members WHERE poll_id = :poll_id AND user_id = :user_id');
        $stmt->execute([':poll_id' => $pollId, ':user_id' => $userId]);
        if (!$stmt->fetchColumn()) throw new DomainException('You are not eligible to view this group notice.', 403);

        $stmt = $pdo->prepare(
            'UPDATE freeze_poll_members fpm
             JOIN freeze_polls fp ON fp.poll_id = fpm.poll_id
             SET fpm.outcome_seen_at = NOW()
             WHERE fpm.user_id = :user_id AND fp.group_id = :group_id
               AND fp.poll_status IN (\'Passed\', \'Failed\')
               AND fp.poll_id <= :poll_id AND fpm.outcome_seen_at IS NULL'
        );
        $stmt->execute([':user_id' => $userId, ':group_id' => $pollGroupId, ':poll_id' => $pollId]);
        $pdo->commit();
        freezePollRedirect($pdo, $userId, $pollGroupId, '');
    }

    if (!in_array($voteChoice, ['Yes', 'No'], true)) {
        throw new DomainException('Choose Approve Freeze or Reject Freeze.', 422);
    }

    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT group_status FROM paluwagan_groups WHERE group_id = :group_id FOR UPDATE');
    $stmt->execute([':group_id' => $pollGroupId]);
    $groupStatus = $stmt->fetchColumn();
    $stmt = $pdo->prepare(
        'SELECT poll_status, reason FROM freeze_polls WHERE poll_id = :poll_id AND group_id = :group_id FOR UPDATE'
    );
    $stmt->execute([':poll_id' => $pollId, ':group_id' => $pollGroupId]);
    $poll = $stmt->fetch();
    if (!$poll || $poll['poll_status'] !== 'Active' || $groupStatus !== 'Active') {
        throw new DomainException('Voting for this freeze request has ended.', 409);
    }

    $stmt = $pdo->prepare(
        'SELECT user_id FROM freeze_poll_members
         WHERE poll_id = :poll_id AND user_id = :user_id FOR UPDATE'
    );
    $stmt->execute([':poll_id' => $pollId, ':user_id' => $userId]);
    if (!$stmt->fetchColumn()) throw new DomainException('You were not a member when this freeze request began.', 403);

    $stmt = $pdo->prepare('SELECT vote_choice FROM poll_votes WHERE poll_id = :poll_id AND user_id = :user_id');
    $stmt->execute([':poll_id' => $pollId, ':user_id' => $userId]);
    if ($stmt->fetchColumn() !== false) throw new DomainException('Your vote has already been recorded and cannot be changed.', 409);

    $stmt = $pdo->prepare('INSERT INTO poll_votes (poll_id, user_id, vote_choice) VALUES (:poll_id, :user_id, :choice)');
    $stmt->execute([':poll_id' => $pollId, ':user_id' => $userId, ':choice' => $voteChoice]);

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS eligible_count,
                (SELECT COUNT(*) FROM poll_votes WHERE poll_id = :vote_poll_yes AND vote_choice = \'Yes\') AS approvals,
                (SELECT COUNT(*) FROM poll_votes WHERE poll_id = :vote_poll_no AND vote_choice = \'No\') AS rejections
         FROM freeze_poll_members WHERE poll_id = :member_poll'
    );
    $stmt->execute([
        ':vote_poll_yes' => $pollId,
        ':vote_poll_no' => $pollId,
        ':member_poll' => $pollId,
    ]);
    $counts = $stmt->fetch();
    $eligible = (int) $counts['eligible_count'];
    $approvals = (int) $counts['approvals'];
    $rejections = (int) $counts['rejections'];
    $votesCast = $approvals + $rejections;
    $result = null;
    $activity = '';
    $details = '';

    if ($eligible > 0 && $approvals * 2 > $eligible) {
        $result = 'Passed';
        $activity = 'Group Frozen';
        $details = "Freeze request approved by member vote ({$approvals} approvals, {$rejections} rejections of {$eligible} eligible members). Reason: {$poll['reason']}";
        $stmt = $pdo->prepare(
            'UPDATE paluwagan_groups SET group_status = :frozen
             WHERE group_id = :group_id AND group_status = :active'
        );
        $stmt->execute([':frozen' => 'Frozen', ':group_id' => $pollGroupId, ':active' => 'Active']);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('The group status changed while the vote was being counted.');
    } elseif ($eligible > 0 && $rejections * 2 > $eligible) {
        $result = 'Failed';
        $activity = 'Freeze Request Rejected';
        $details = "Freeze request rejected by member vote ({$approvals} approvals, {$rejections} rejections of {$eligible} eligible members). Reason: {$poll['reason']}";
    } elseif ($eligible > 0 && $votesCast >= $eligible) {
        $result = 'Failed';
        $activity = 'Freeze Request Closed';
        $details = "Freeze request closed without a majority ({$approvals} approvals, {$rejections} rejections of {$eligible} eligible members). The group remains active.";
    }

    if ($result !== null) {
        $stmt = $pdo->prepare(
            'UPDATE freeze_polls SET poll_status = :status
             WHERE poll_id = :poll_id AND poll_status = :active'
        );
        $stmt->execute([':status' => $result, ':poll_id' => $pollId, ':active' => 'Active']);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('The freeze vote was already finalized.');
        $pdo->prepare(
            'INSERT INTO audit_logs (group_id, actor_user_id, activity_type, details, verification_status)
             VALUES (:group_id, :actor, :activity, :details, :verification)'
        )->execute([
            ':group_id' => $pollGroupId,
            ':actor' => $userId,
            ':activity' => $activity,
            ':details' => $details,
            ':verification' => 'Member Vote',
        ]);
    }

    $pdo->commit();
    $message = $result === 'Passed'
        ? 'The members approved the freeze. This group is now frozen.'
        : ($result === 'Failed'
            ? ($activity === 'Freeze Request Closed'
                ? 'The vote ended without a majority. The group remains active.'
                : 'The members rejected the freeze request. The group remains active.')
            : 'Your vote has been recorded and cannot be changed.');
    freezePollRedirect($pdo, $userId, $pollGroupId, $message);
} catch (DomainException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    $message = $e->getMessage();
    if (isset($pdo) && $pollGroupId > 0) {
        freezePollRedirect($pdo, $userId, $pollGroupId, $message);
    }
    $_SESSION['group_success'] = $message;
    header('Location: my_groups.php', true, 303);
    exit;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Freeze poll action error: ' . $e->getMessage());
    $message = 'We could not save your freeze vote. Please refresh and try again.';
    if (isset($pdo) && $pollGroupId > 0) {
        freezePollRedirect($pdo, $userId, $pollGroupId, $message);
    }
    $_SESSION['group_success'] = $message;
    header('Location: my_groups.php', true, 303);
    exit;
}

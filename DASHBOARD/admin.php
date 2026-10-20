<?php
session_start();

require_once __DIR__ . '/../LOGIN_PAGE/access.php';
$adminUser = tokaRequireRole(true);

$username = $_SESSION['user']['username'] ?? 'User';
$adminViews = [
    'dashboard' => ['label' => 'Dashboard', 'description' => 'Platform oversight, dispute management, and user control'],
    'total-groups' => ['label' => 'Total Groups', 'description' => 'Every group recorded on the platform, including groups waiting to start'],
    'completed-rounds' => ['label' => 'Completed Rounds', 'description' => 'Rounds that have been completed across all groups'],
    'group-pots' => ['label' => 'Group Pots', 'description' => 'Current round status and money pool values for each group'],
    'frozen-groups' => ['label' => 'Frozen / Flagged Groups', 'description' => 'Groups currently frozen or flagged for review'],
    'user-directory' => ['label' => 'User Directory', 'description' => 'Registration and account details for all users'],
    'system-audit-logs' => ['label' => 'System Audit Logs', 'description' => 'Read-only global activity ledger'],
];
$requestedAdminView = $_GET['view'] ?? 'dashboard';
$adminView = is_string($requestedAdminView) && isset($adminViews[$requestedAdminView])
    ? $requestedAdminView
    : 'dashboard';
$adminPage = $adminViews[$adminView];
$adminHeading = $adminView === 'dashboard' ? 'Admin Panel' : $adminPage['label'];
$registeredUsers = [];
$auditLogs = [];
$disputedGroups = [];
$allGroups = [];
$completedRounds = [];
$groupPots = [];
$frozenGroups = [];
$activeGroupCount = null;
$completedCycleCount = null;
$frozenGroupCount = null;
$activePoolVolume = null;
$usersError = '';
$auditError = '';
$disputesError = '';
$groupsError = '';
$roundsError = '';
$potsError = '';
$frozenGroupsError = '';
$pdo = null;
$moderationError = '';
$moderationSuccess = $_SESSION['admin_moderation_success'] ?? '';
unset($_SESSION['admin_moderation_success']);
if (empty($_SESSION['admin_moderation_csrf'])) {
    $_SESSION['admin_moderation_csrf'] = bin2hex(random_bytes(32));
}
$moderationToken = $_SESSION['admin_moderation_csrf'];

try {
    $pdo = getDbConnection();
} catch (PDOException $e) {
    error_log('Admin database connection error: ' . $e->getMessage());
    $usersError = 'We could not load registered users. Please refresh to try again.';
    $auditError = 'We could not load the audit logs. Please refresh to try again.';
    $disputesError = 'We could not load groups requiring intervention. Please refresh to try again.';
    $groupsError = 'We could not load platform groups. Please refresh to try again.';
    $roundsError = 'We could not load completed rounds. Please refresh to try again.';
    $potsError = 'We could not load group pot information. Please refresh to try again.';
    $frozenGroupsError = 'We could not load frozen group records. Please refresh to try again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = is_string($_POST['moderation_action'] ?? null) ? $_POST['moderation_action'] : '';
    $token = $_POST['moderation_token'] ?? '';
    $targetUserId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $targetGroupId = filter_var($_POST['group_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $expectedStatus = is_string($_POST['expected_status'] ?? null) ? $_POST['expected_status'] : '';

    try {
        if (!is_string($token) || !hash_equals($moderationToken, $token)) {
            throw new DomainException('Your confirmation expired. Refresh the page and try again.', 403);
        }
        if (!$pdo) throw new RuntimeException('Database unavailable.');

        if (in_array($action, ['ban', 'unban'], true)) {
            if (!$targetUserId
                || !in_array($expectedStatus, ['Active', 'Suspended', 'Banned', 'Action Needed'], true)) {
                throw new DomainException('Choose a valid user and moderation action.', 422);
            }

            $pdo->beginTransaction();
            // Recheck both accounts under a lock before making any status change.
            $stmt = $pdo->prepare(
                'SELECT user_id, username, is_admin, account_status FROM users
                 WHERE user_id IN (:admin_id, :target_id) ORDER BY user_id FOR UPDATE'
            );
            $stmt->execute([':admin_id' => $adminUser['user_id'], ':target_id' => $targetUserId]);
            $lockedUsers = [];
            foreach ($stmt->fetchAll() as $lockedUser) $lockedUsers[(int) $lockedUser['user_id']] = $lockedUser;
            $actor = $lockedUsers[$adminUser['user_id']] ?? null;
            $target = $lockedUsers[$targetUserId] ?? null;
            if (!$actor || !tokaIsAdmin($actor) || $actor['account_status'] !== 'Active') {
                throw new DomainException('Only the active admin can manage accounts.', 403);
            }
            if (!$target) throw new DomainException('This user no longer exists. Refresh the page.', 404);
            if ($targetUserId === $adminUser['user_id'] || !empty($target['is_admin']) || $target['username'] === 'SaruPeroAdmin') {
                throw new DomainException('The admin account cannot be banned or unbanned.', 403);
            }
            if ($target['account_status'] !== $expectedStatus
                || ($action === 'ban' && $expectedStatus === 'Banned')
                || ($action === 'unban' && $expectedStatus !== 'Banned')) {
                throw new DomainException('This user’s status has changed. Review the updated roster and try again.', 409);
            }

            $newStatus = $action === 'ban' ? 'Banned' : 'Active';
            $stmt = $pdo->prepare('UPDATE users SET account_status = :status WHERE user_id = :user_id');
            $stmt->execute([':status' => $newStatus, ':user_id' => $targetUserId]);
            $verb = $action === 'ban' ? 'banned' : 'unbanned';
            $pdo->prepare(
                'INSERT INTO audit_logs (actor_user_id, activity_type, details, verification_status)
                 VALUES (:actor, :activity, :details, :verification)'
            )->execute([
                ':actor' => $adminUser['user_id'],
                ':activity' => $action === 'ban' ? 'User Banned' : 'User Unbanned',
                ':details' => $target['username'] . ' (User ID ' . $targetUserId . ') was ' . $verb . ' by the admin.',
                ':verification' => 'Admin',
            ]);
            $pdo->commit();
            $_SESSION['admin_moderation_success'] = $target['username'] . ' has been ' . $verb . '.';
            header('Location: admin.php#roster-heading', true, 303);
            exit;
        } elseif (in_array($action, ['unfreeze', 'dissolve'], true)) {
            if (!$targetGroupId || $expectedStatus !== 'Frozen') {
                throw new DomainException('Choose a valid group and intervention action.', 422);
            }

            $pdo->beginTransaction();
            // Verify admin is active under lock
            $adminStmt = $pdo->prepare('SELECT user_id, is_admin, account_status FROM users WHERE user_id = :admin_id FOR UPDATE');
            $adminStmt->execute([':admin_id' => $adminUser['user_id']]);
            $actor = $adminStmt->fetch();
            if (!$actor || !tokaIsAdmin($actor) || $actor['account_status'] !== 'Active') {
                throw new DomainException('Only the active admin can manage groups.', 403);
            }

            // Lock and verify target group
            $groupStmt = $pdo->prepare('SELECT group_id, group_code, group_name, group_status FROM paluwagan_groups WHERE group_id = :group_id FOR UPDATE');
            $groupStmt->execute([':group_id' => $targetGroupId]);
            $targetGroup = $groupStmt->fetch();
            if (!$targetGroup) {
                throw new DomainException('This group no longer exists. Refresh the page.', 404);
            }
            if ($targetGroup['group_status'] !== 'Frozen') {
                throw new DomainException('This group is no longer frozen. Review the updated list.', 409);
            }

            $newStatus = $action === 'unfreeze' ? 'Active' : 'Dissolved';
            $updateStmt = $pdo->prepare('UPDATE paluwagan_groups SET group_status = :status WHERE group_id = :group_id');
            $updateStmt->execute([':status' => $newStatus, ':group_id' => $targetGroupId]);

            // Update any active freeze poll for this group
            $pollStatus = $action === 'unfreeze' ? 'Failed' : 'Passed';
            $pdo->prepare("UPDATE freeze_polls SET poll_status = :status WHERE group_id = :group_id AND poll_status = 'Active'")
                ->execute([':status' => $pollStatus, ':group_id' => $targetGroupId]);

            $verb = $action === 'unfreeze' ? 'unfrozen' : 'dissolved';
            $activity = $action === 'unfreeze' ? 'Group Unfrozen' : 'Group Dissolved';
            $pdo->prepare(
                'INSERT INTO audit_logs (group_id, actor_user_id, activity_type, details, verification_status)
                 VALUES (:group_id, :actor, :activity, :details, :verification)'
            )->execute([
                ':group_id' => $targetGroupId,
                ':actor' => $adminUser['user_id'],
                ':activity' => $activity,
                ':details' => $targetGroup['group_name'] . ' (' . $targetGroup['group_code'] . ') was force-' . $verb . ' by admin.',
                ':verification' => 'Admin',
            ]);
            $pdo->commit();
            $_SESSION['admin_moderation_success'] = $targetGroup['group_name'] . ' has been ' . $verb . '.';
            header('Location: admin.php#dispute-heading', true, 303);
            exit;
        } else {
            throw new DomainException('Choose a valid moderation action.', 422);
        }
    } catch (DomainException $e) {
        if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
        http_response_code($e->getCode());
        $moderationError = $e->getMessage();
    } catch (Throwable $e) {
        if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
        error_log('Admin moderation error: ' . $e->getMessage());
        http_response_code(500);
        $moderationError = 'We could not complete this action. Please refresh and try again.';
    }
}

if ($pdo) {
    try {
        $registeredUsers = $pdo->query(
            'SELECT u.user_id, u.username, u.full_name, u.email, u.created_at,
                    u.trust_score, u.account_status, u.is_admin,
                    COALESCE(m.group_count, 0) AS group_count
             FROM users u
             LEFT JOIN (
                 SELECT user_id, COUNT(DISTINCT group_id) AS group_count
                 FROM group_members GROUP BY user_id
             ) m ON m.user_id = u.user_id
             ORDER BY u.created_at DESC, u.user_id DESC'
        )->fetchAll();
    } catch (PDOException $e) {
        error_log('Admin roster DB error: ' . $e->getMessage());
        $usersError = 'We could not load registered users. Please refresh to try again.';
    }

    try {
        $disputeStmt = $pdo->query(
            "SELECT
                pg.group_id,
                pg.group_code,
                pg.group_name,
                pg.group_status,
                pg.total_member_slots,
                COALESCE(gm_count.member_count, 0) AS member_count,
                fp.poll_id,
                fp.reason AS poll_reason,
                COALESCE(fp_electorate.eligible_count, gm_count.member_count, pg.total_member_slots) AS eligible_count,
                COALESCE(fp_votes.yes_votes, 0) AS yes_votes,
                al.details AS audit_freeze_details
             FROM paluwagan_groups pg
             LEFT JOIN (
                 SELECT group_id, COUNT(*) AS member_count
                 FROM group_members
                 GROUP BY group_id
             ) gm_count ON gm_count.group_id = pg.group_id
             LEFT JOIN (
                 SELECT fp1.poll_id, fp1.group_id, fp1.reason
                 FROM freeze_polls fp1
                 INNER JOIN (
                     SELECT group_id, MAX(poll_id) AS max_poll_id
                     FROM freeze_polls
                     GROUP BY group_id
                 ) fp2 ON fp1.poll_id = fp2.max_poll_id
             ) fp ON fp.group_id = pg.group_id
             LEFT JOIN (
                 SELECT poll_id, COUNT(CASE WHEN vote_choice = 'Yes' THEN 1 END) AS yes_votes
                 FROM poll_votes
                 GROUP BY poll_id
             ) fp_votes ON fp_votes.poll_id = fp.poll_id
             LEFT JOIN (
                 SELECT poll_id, COUNT(*) AS eligible_count
                 FROM freeze_poll_members
                 GROUP BY poll_id
             ) fp_electorate ON fp_electorate.poll_id = fp.poll_id
             LEFT JOIN (
                 SELECT al1.group_id, al1.details
                 FROM audit_logs al1
                 INNER JOIN (
                     SELECT group_id, MAX(log_id) AS max_log_id
                     FROM audit_logs
                     WHERE activity_type = 'Group Frozen'
                     GROUP BY group_id
                 ) al2 ON al1.log_id = al2.max_log_id
             ) al ON al.group_id = pg.group_id
             WHERE pg.group_status = 'Frozen'
             ORDER BY pg.group_id DESC"
        );
        $rawDisputes = $disputeStmt->fetchAll();
        foreach ($rawDisputes as $row) {
            $totalMembers = (int) ($row['eligible_count'] > 0 ? $row['eligible_count'] : $row['total_member_slots']);
            $hasPoll = !empty($row['poll_id']);
            $yesVotes = (int) $row['yes_votes'];
            $reason = !empty($row['poll_reason'])
                ? $row['poll_reason']
                : (!empty($row['audit_freeze_details']) ? $row['audit_freeze_details'] : 'Administrative Review');

            $voteDisplay = $hasPoll ? "{$yesVotes}/{$totalMembers}" : ($totalMembers > 0 ? "—/{$totalMembers}" : "—");
            $voteTitle = $hasPoll
                ? "{$yesVotes} votes to freeze out of {$totalMembers} members"
                : "Frozen without member vote ({$totalMembers} members)";

            $disputedGroups[] = [
                'group_id' => (int) $row['group_id'],
                'group_code' => $row['group_code'],
                'group_name' => $row['group_name'],
                'group_status' => $row['group_status'],
                'votes' => $hasPoll ? $yesVotes : null,
                'members' => $totalMembers,
                'vote_display' => $voteDisplay,
                'vote_title' => $voteTitle,
                'reason' => $reason,
            ];
        }
    } catch (PDOException $e) {
        error_log('Admin disputes DB error: ' . $e->getMessage());
        $disputesError = 'We could not load groups requiring intervention. Please refresh to try again.';
    }

    try {
        // Start with the ledger so entries survive missing optional group/payment links.
        // No membership filter: this is the master log for every group.
        $auditLogs = $pdo->query(
            'SELECT al.log_id, al.timestamp, al.actor_user_id, al.activity_type, al.details,
                    u.username, pg.group_code, p.transaction_no
             FROM audit_logs al
             LEFT JOIN users u ON u.user_id = al.actor_user_id
             LEFT JOIN paluwagan_groups pg ON pg.group_id = al.group_id
             LEFT JOIN payments p ON p.payment_id = al.payment_id
             ORDER BY al.timestamp DESC, al.log_id DESC'
        )->fetchAll();
    } catch (PDOException $e) {
        error_log('Admin audit DB error: ' . $e->getMessage());
        $auditError = 'We could not load the audit logs. Please refresh to try again.';
    }

    try {
        $groupStats = $pdo->query(
            "SELECT
                COALESCE(SUM(CASE WHEN group_status IN ('Active', 'Waiting') THEN 1 ELSE 0 END), 0) AS active_group_count,
                (SELECT COUNT(*) FROM round_schedules WHERE round_status = 'Paid Out') AS completed_cycle_count,
                COALESCE(SUM(CASE WHEN group_status = 'Frozen' THEN 1 ELSE 0 END), 0) AS frozen_group_count,
                COALESCE(SUM(CASE WHEN pg.group_status = 'Active' THEN COALESCE(active_rounds.accumulated_pot, 0) ELSE 0 END), 0) AS active_pool_volume
             FROM paluwagan_groups pg
             LEFT JOIN (
                 SELECT rs.group_id, SUM(p.amount_due) AS accumulated_pot
                 FROM round_schedules rs
                 LEFT JOIN payments p ON p.round_id = rs.round_id AND p.payment_status = 'Verified'
                 WHERE rs.round_status = 'Ongoing'
                 GROUP BY rs.group_id
             ) active_rounds ON active_rounds.group_id = pg.group_id"
        )->fetch();
        if ($groupStats) {
            $activeGroupCount = (int) $groupStats['active_group_count'];
            $completedCycleCount = (int) $groupStats['completed_cycle_count'];
            $frozenGroupCount = (int) $groupStats['frozen_group_count'];
            $activePoolVolume = $groupStats['active_pool_volume'];
        }
    } catch (PDOException $e) {
        error_log('Admin group statistics DB error: ' . $e->getMessage());
    }

    try {
        $allGroups = $pdo->query(
            'SELECT pg.group_id, pg.group_code, pg.group_name, pg.group_status,
                    pg.created_at, pg.first_payment_due, pg.total_member_slots,
                    COALESCE(members.member_count, 0) AS member_count
             FROM paluwagan_groups pg
             LEFT JOIN (
                 SELECT group_id, COUNT(*) AS member_count
                 FROM group_members GROUP BY group_id
             ) members ON members.group_id = pg.group_id
             ORDER BY pg.created_at DESC, pg.group_id DESC'
        )->fetchAll();
    } catch (PDOException $e) {
        error_log('Admin total groups DB error: ' . $e->getMessage());
        $groupsError = 'We could not load platform groups. Please refresh to try again.';
    }

    try {
        $completedRounds = $pdo->query(
            "SELECT pg.group_id, pg.group_code, pg.group_name,
                    rs.round_number, rs.target_deadline,
                    COALESCE(rs.operator_approved_at, rs.actual_disbursement_date) AS completed_at,
                    receiver.username AS receiver_name
             FROM round_schedules rs
             JOIN paluwagan_groups pg ON pg.group_id = rs.group_id
             JOIN group_members receiver_member ON receiver_member.member_id = rs.receiver_member_id
             JOIN users receiver ON receiver.user_id = receiver_member.user_id
             WHERE rs.round_status = 'Paid Out'
             ORDER BY completed_at DESC, pg.group_id DESC, rs.round_number DESC"
        )->fetchAll();
    } catch (PDOException $e) {
        error_log('Admin completed rounds DB error: ' . $e->getMessage());
        $roundsError = 'We could not load completed rounds. Please refresh to try again.';
    }

    try {
        $groupPots = $pdo->query(
            "SELECT pg.group_id, pg.group_code, pg.group_name, pg.group_status,
                    pg.first_payment_due, pg.calculated_total_pot,
                    current_round.round_number, current_round.round_status,
                    current_round.target_deadline,
                    COALESCE(round_pots.accumulated_pot, 0) AS accumulated_pot,
                    COALESCE(cycle_pots.accumulated_cycle_pot, 0) AS accumulated_cycle_pot
             FROM paluwagan_groups pg
             LEFT JOIN round_schedules current_round
               ON current_round.round_id = (
                   SELECT candidate.round_id
                   FROM round_schedules candidate
                   WHERE candidate.group_id = pg.group_id
                   ORDER BY CASE WHEN candidate.round_status = 'Paid Out' THEN 1 ELSE 0 END,
                            CASE WHEN candidate.round_status <> 'Paid Out' THEN candidate.round_number END,
                            candidate.round_number DESC
                   LIMIT 1
               )
             LEFT JOIN (
                 SELECT p.round_id, SUM(p.amount_due) AS accumulated_pot
                 FROM payments p
                 WHERE p.payment_status = 'Verified'
                 GROUP BY p.round_id
             ) round_pots ON round_pots.round_id = current_round.round_id
             LEFT JOIN (
                 SELECT rs.group_id, SUM(p.amount_due) AS accumulated_cycle_pot
                 FROM round_schedules rs
                 JOIN payments p ON p.round_id = rs.round_id
                 WHERE p.payment_status = 'Verified'
                 GROUP BY rs.group_id
             ) cycle_pots ON cycle_pots.group_id = pg.group_id
             ORDER BY pg.created_at DESC, pg.group_id DESC"
        )->fetchAll();
    } catch (PDOException $e) {
        error_log('Admin group pots DB error: ' . $e->getMessage());
        $potsError = 'We could not load group pot information. Please refresh to try again.';
    }

    try {
        $frozenGroups = $pdo->query(
            "SELECT pg.group_id, pg.group_code, pg.group_name,
                    COALESCE(poll_actor.username, freeze_actor.username, 'Not recorded') AS initiated_by,
                    COALESCE(freeze_event.timestamp, freeze_poll.created_at) AS freeze_approval_at,
                    COALESCE(NULLIF(freeze_poll.reason, ''), NULLIF(freeze_event.details, ''), 'No reason recorded') AS freeze_reason
             FROM paluwagan_groups pg
             LEFT JOIN (
                 SELECT al.group_id, al.actor_user_id, al.timestamp, al.details
                 FROM audit_logs al
                 INNER JOIN (
                     SELECT group_id, MAX(log_id) AS log_id
                     FROM audit_logs
                     WHERE activity_type = 'Group Frozen'
                     GROUP BY group_id
                 ) latest_freeze ON latest_freeze.log_id = al.log_id
             ) freeze_event ON freeze_event.group_id = pg.group_id
             LEFT JOIN users freeze_actor ON freeze_actor.user_id = freeze_event.actor_user_id
             LEFT JOIN freeze_polls freeze_poll
               ON freeze_poll.poll_id = (
                   SELECT fp.poll_id FROM freeze_polls fp
                   WHERE fp.group_id = pg.group_id
                   ORDER BY fp.poll_id DESC LIMIT 1
               )
             LEFT JOIN users poll_actor ON poll_actor.user_id = freeze_poll.initiator_user_id
             WHERE pg.group_status = 'Frozen'
             ORDER BY pg.group_id DESC"
        )->fetchAll();
    } catch (PDOException $e) {
        error_log('Admin frozen groups DB error: ' . $e->getMessage());
        $frozenGroupsError = 'We could not load frozen group records. Please refresh to try again.';
    }
}

$bannedCount = count(array_filter($registeredUsers, fn ($user) => $user['account_status'] === 'Banned'));

function adminH(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function adminInitials(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);
    $initials = '';
    foreach (array_slice($parts ?: ['?'], 0, 2) as $part) {
        $initials .= mb_substr($part, 0, 1, 'UTF-8');
    }
    return mb_strtoupper($initials, 'UTF-8');
}

function adminDateTime(?string $value): string
{
    if ($value === null || $value === '') return 'Not recorded';
    $timestamp = strtotime($value);
    return $timestamp === false ? 'Not recorded' : date('M j, Y h:i A', $timestamp);
}

function adminActivityKind(string $activity): string
{
    if (stripos($activity, 'unbanned') !== false) return 'positive';
    if (preg_match('/reject|declin|freez|frozen|ban|dissolv/i', $activity)) return 'alert';
    if (preg_match('/missed|overdue|late|pending/i', $activity)) return 'warning';
    if (preg_match('/verified|approved|started|completed|confirmed|disbursed/i', $activity)) return 'positive';
    return 'info';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= adminH($adminHeading) ?> - Toka</title>
    <link rel="stylesheet" href="dashboard.css?v=<?= filemtime(__DIR__ . '/dashboard.css') ?>">
    <link rel="stylesheet" href="admin.css?v=<?= filemtime(__DIR__ . '/admin.css') ?>">
    <link rel="stylesheet" href="pagination.css?v=<?= filemtime(__DIR__ . '/pagination.css') ?>">
    <link rel="stylesheet" href="../assets/typography.css?v=<?= filemtime(__DIR__ . '/../assets/typography.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="icon" type="image/svg+xml" href="../assets/toka_icon.svg">
    <script src="admin.js?v=<?= filemtime(__DIR__ . '/admin.js') ?>" defer></script>
</head>
<body class="admin-page">
    <div class="app">
        <aside class="sidebar">
            <div class="sidebar-top">
                <div class="brand"><a href="admin.php" aria-label="Toka admin home"><img src="../assets/Toka.svg" alt="Toka"></a></div>
                <nav class="nav" aria-label="Main navigation">
                    <a href="admin.php" class="nav-item<?= $adminView === 'dashboard' ? ' active' : '' ?>"<?= $adminView === 'dashboard' ? ' aria-current="page"' : '' ?>>
                        <span class="nav-icon" aria-hidden="true"><i class="fa-solid fa-border-all"></i></span><span>Dashboard</span>
                    </a>
                    <a href="admin.php?view=total-groups" class="nav-item<?= $adminView === 'total-groups' ? ' active' : '' ?>"<?= $adminView === 'total-groups' ? ' aria-current="page"' : '' ?>>
                        <span class="nav-icon" aria-hidden="true"><i class="fa-solid fa-people-group"></i></span><span>Total Groups</span>
                    </a>
                    <a href="admin.php?view=completed-rounds" class="nav-item<?= $adminView === 'completed-rounds' ? ' active' : '' ?>"<?= $adminView === 'completed-rounds' ? ' aria-current="page"' : '' ?>>
                        <span class="nav-icon" aria-hidden="true"><i class="fa-solid fa-flag-checkered"></i></span><span>Completed Rounds</span>
                    </a>
                    <a href="admin.php?view=group-pots" class="nav-item<?= $adminView === 'group-pots' ? ' active' : '' ?>"<?= $adminView === 'group-pots' ? ' aria-current="page"' : '' ?>>
                        <span class="nav-icon" aria-hidden="true"><i class="fa-solid fa-coins"></i></span><span>Group Pots</span>
                    </a>
                    <a href="admin.php?view=frozen-groups" class="nav-item<?= $adminView === 'frozen-groups' ? ' active' : '' ?>"<?= $adminView === 'frozen-groups' ? ' aria-current="page"' : '' ?>>
                        <span class="nav-icon" aria-hidden="true"><i class="fa-solid fa-snowflake"></i></span><span>Frozen / Flagged Groups</span>
                    </a>
                    <a href="admin.php?view=user-directory" class="nav-item<?= $adminView === 'user-directory' ? ' active' : '' ?>"<?= $adminView === 'user-directory' ? ' aria-current="page"' : '' ?>>
                        <span class="nav-icon" aria-hidden="true"><i class="fa-solid fa-address-book"></i></span><span>User Directory</span>
                    </a>
                    <a href="admin.php?view=system-audit-logs" class="nav-item<?= $adminView === 'system-audit-logs' ? ' active' : '' ?>"<?= $adminView === 'system-audit-logs' ? ' aria-current="page"' : '' ?>>
                        <span class="nav-icon" aria-hidden="true"><i class="fa-solid fa-clipboard-list"></i></span><span>System Audit Logs</span>
                    </a>
                </nav>
            </div>
            <div class="sidebar-bottom">
                <div class="profile"><div class="profile-circle" aria-hidden="true"><?= adminH(strtoupper(substr($username, 0, 1))) ?></div><span><?= adminH($username) ?></span></div>
                <?php if (isset($_SESSION['user'])): ?><a href="../LOGIN_PAGE/logout.php" class="logout" aria-label="Log out" title="Log out"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i></a><?php endif; ?>
            </div>
        </aside>

        <main class="main admin-main" id="admin-overview" tabindex="-1">
            <?php if ($adminView === 'dashboard'): ?>
            <header class="admin-header">
                <h1><?= adminH($adminHeading) ?></h1>
                <p><?= adminH($adminPage['description']) ?></p>
            </header>
            <?php endif; ?>

            <noscript><p class="admin-notice">Enable JavaScript to search these records and confirm account or group actions.</p></noscript>
            <?php if ($moderationError !== ''): ?><p class="admin-notice" role="alert"><?= adminH($moderationError) ?></p><?php endif; ?>
            <div id="storage-notice" class="admin-notice" role="status" hidden></div>

            <?php if ($adminView === 'dashboard'): ?>
            <section class="admin-stats" aria-label="Platform overview">
                <article class="admin-stat stat-active"><strong id="active-count"><?= $activeGroupCount === null ? '—' : $activeGroupCount ?></strong><span>Active &amp; Waiting Groups</span></article>
                <article class="admin-stat stat-completed"><strong id="completed-count"><?= $completedCycleCount === null ? '—' : $completedCycleCount ?></strong><span>Completed Group Rounds</span></article>
                <article class="admin-stat stat-volume"><strong id="volume-total"><?= $activePoolVolume === null ? '—' : '₱' . adminH(number_format((float) $activePoolVolume, 0)) ?></strong><span>Total Active Money Pool Value</span></article>
                <article class="admin-stat stat-flagged"><strong id="flagged-count"><?= $frozenGroupCount === null ? '—' : $frozenGroupCount ?></strong><span>Flagged / Frozen Groups</span></article>
            </section>

            <div class="admin-columns">
                <section class="admin-panel dispute-panel" aria-labelledby="dispute-heading">
                    <header class="panel-heading"><h2 id="dispute-heading" tabindex="-1">Dispute Intervention Desk</h2><p>Frozen and deadlocked groups requiring admin action</p></header>
                    <?php if ($disputesError !== ''): ?><p class="admin-notice" role="alert"><?= adminH($disputesError) ?></p><?php endif; ?>
                    <div class="dispute-scroll" tabindex="0" role="region" aria-label="Dispute table">
                        <table class="dispute-table">
                            <caption class="sr-only">Groups requiring intervention. Votes show votes to freeze out of total members.</caption>
                            <thead><tr><th scope="col">Group</th><th scope="col">Votes</th><th scope="col">Status</th><th scope="col">Reason</th><th scope="col">Actions</th></tr></thead>
                            <tbody id="dispute-rows" data-available="<?= $disputesError === '' ? 'true' : 'false' ?>">
                                <?php foreach ($disputedGroups as $group): ?>
                                    <tr data-group-id="<?= (int) $group['group_id'] ?>" data-name="<?= adminH($group['group_name']) ?>" data-code="<?= adminH($group['group_code']) ?>" data-status="<?= adminH($group['group_status']) ?>">
                                        <td>
                                            <span class="group-name"><?= adminH($group['group_name']) ?></span>
                                            <small><?= adminH($group['group_code']) ?></small>
                                        </td>
                                        <td class="vote-count" title="<?= adminH($group['vote_title']) ?>"><?= adminH($group['vote_display']) ?></td>
                                        <td><span class="status-pill"><?= adminH($group['group_status']) ?></span></td>
                                        <td class="dispute-reason"><?= adminH($group['reason']) ?></td>
                                        <td>
                                            <div class="dispute-actions">
                                                <button type="button" class="admin-button secondary" data-action="unfreeze" data-id="<?= (int) $group['group_id'] ?>" data-name="<?= adminH($group['group_name']) ?>" aria-label="Force-Unfreeze <?= adminH($group['group_name']) ?>">Force-Unfreeze</button>
                                                <button type="button" class="admin-button" data-action="dissolve" data-id="<?= (int) $group['group_id'] ?>" data-name="<?= adminH($group['group_name']) ?>" aria-label="Force-Dissolve <?= adminH($group['group_name']) ?>">Force-Dissolve</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div id="disputes-empty" class="admin-empty" <?= $disputedGroups || $disputesError !== '' ? 'hidden' : '' ?>><span aria-hidden="true">&#10003;</span><h3>All caught up</h3><p>No groups need intervention right now.</p></div>
                </section>

                <section class="admin-panel roster-panel" aria-labelledby="roster-heading">
                    <header class="panel-heading"><h2 id="roster-heading">User Roster &amp; Blacklist</h2></header>
                    <div class="roster-search"><label class="sr-only" for="user-search">Search users by name or ID</label><input id="user-search" type="search" placeholder="Search name or ID..." autocomplete="off" aria-controls="user-roster" <?= $usersError !== '' ? 'disabled' : '' ?>></div>
                    <?php if ($usersError !== ''): ?><p class="admin-notice" role="alert"><?= adminH($usersError) ?></p><?php endif; ?>
                    <ul id="user-roster" class="user-roster" data-available="<?= $usersError === '' ? 'true' : 'false' ?>">
                        <?php foreach ($registeredUsers as $user): ?>
                            <?php
                            $isBanned = $user['account_status'] === 'Banned';
                            $protectedAccount = !empty($user['is_admin']) || (int) $user['user_id'] === $adminUser['user_id'] || $user['username'] === 'SaruPeroAdmin';
                            ?>
                            <li class="user-row<?= $isBanned ? ' is-banned' : '' ?>" data-user-id="<?= (int) $user['user_id'] ?>" data-name="<?= adminH($user['username']) ?>" data-search="<?= adminH($user['user_id'] . ' ' . $user['username']) ?>" data-status="<?= adminH($user['account_status']) ?>">
                                <span class="user-avatar" aria-hidden="true"><?= adminH(adminInitials($user['username'])) ?></span>
                                <div class="user-description">
                                    <div class="user-name"><strong><?= adminH($user['username']) ?></strong><?php if ($user['account_status'] !== 'Active'): ?><span class="status-pill"><?= adminH($user['account_status']) ?></span><?php endif; ?></div>
                                    <span class="user-meta">ID: <?= (int) $user['user_id'] ?> · <?= (int) $user['group_count'] ?> <?= (int) $user['group_count'] === 1 ? 'group' : 'groups' ?> · Trust: <?= (int) $user['trust_score'] ?></span>
                                </div>
                                <button type="button" class="admin-button<?= $isBanned ? ' secondary' : '' ?>" data-action="<?= $isBanned ? 'unban' : 'ban' ?>" data-id="<?= (int) $user['user_id'] ?>" aria-label="<?= adminH(($isBanned ? 'Unban ' : 'Ban ') . $user['username']) ?>" <?= $protectedAccount ? 'disabled title="The admin account is protected from bans."' : '' ?>><?= $isBanned ? 'Unban' : 'Ban' ?></button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div id="users-empty" class="admin-empty" <?= $registeredUsers || $usersError !== '' ? 'hidden' : '' ?>><h3>No users found</h3><p><?= $registeredUsers ? 'Try another name or user ID.' : 'No users have registered yet.' ?></p><button class="admin-button secondary" id="clear-search" type="button" <?= !$registeredUsers ? 'hidden' : '' ?>>Clear search</button></div>
                    <p class="roster-count" id="roster-count" role="status"><?= $usersError !== '' ? 'User count unavailable.' : count($registeredUsers) . ' of ' . count($registeredUsers) . ' users · ' . $bannedCount . ' banned' ?></p>
                    <p class="roster-controls-note" id="roster-controls-note">The admin account is protected from bans.</p>
                </section>
            </div>

            <?php endif; ?>

            <?php if ($adminView === 'system-audit-logs'): ?>
            <section class="admin-panel audit-panel" aria-labelledby="audit-heading">
                <header class="audit-heading">
                    <div><h2 id="audit-heading">Master System Audit Search</h2><p>Read-only global activity ledger</p></div>
                    <label class="sr-only" for="audit-search">Search by User ID, Group Code, or Transaction Reference</label>
                    <input id="audit-search" type="search" placeholder="Search by User ID, Group Code, or Txn Ref..." autocomplete="off" aria-controls="audit-rows" <?= $auditError !== '' ? 'disabled' : '' ?>>
                </header>
                <?php if ($auditError !== ''): ?><p class="admin-notice" role="alert"><?= adminH($auditError) ?></p><?php endif; ?>
                <div class="audit-scroll" tabindex="0" role="region" aria-label="Global activity ledger">
                    <table class="audit-table">
                        <caption class="sr-only">Read-only history of group and payment activity.</caption>
                        <thead><tr><th scope="col">Timestamp</th><th scope="col">User ID</th><th scope="col">Group Code</th><th scope="col">Txn Ref</th><th scope="col">Activity</th><th scope="col">Details</th></tr></thead>
                        <tbody id="audit-rows" data-available="<?= $auditError === '' ? 'true' : 'false' ?>">
                            <?php foreach ($auditLogs as $log): ?>
                                <tr data-search="<?= adminH(implode(' ', [$log['actor_user_id'], $log['username'] ?? '', $log['group_code'] ?? '', $log['transaction_no'] ?? ''])) ?>">
                                    <td class="audit-timestamp"><?= $log['timestamp'] ? adminH(date('m/d/Y h:i A', strtotime($log['timestamp']))) : '—' ?></td>
                                    <td class="audit-user" title="<?= adminH($log['username'] ?? '') ?>"><?= (int) $log['actor_user_id'] ?></td>
                                    <td class="audit-group"><?= adminH($log['group_code'] ?? '—') ?></td>
                                    <td class="audit-ref"><?= adminH(($log['transaction_no'] !== null && $log['transaction_no'] !== '') ? $log['transaction_no'] : '—') ?></td>
                                    <td><span class="audit-activity <?= adminActivityKind($log['activity_type']) ?>"><?= adminH($log['activity_type']) ?></span></td>
                                    <td><?= adminH($log['details'] ?? '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <nav id="audit-pagination" class="admin-pagination" aria-label="Audit log pages" hidden></nav>
                <p id="audit-empty" class="audit-empty" role="status" <?= $auditLogs || $auditError !== '' ? 'hidden' : '' ?>><?= $auditLogs ? 'No audit activity matches your search.' : 'No audit activity has been recorded yet.' ?></p>
                <p id="audit-count" class="sr-only" role="status" aria-live="polite"><?= $auditError !== '' ? 'Audit count unavailable.' : count($auditLogs) . ' audit records shown.' ?></p>
            </section>
            <?php endif; ?>

            <?php if ($adminView === 'total-groups'): ?>
            <section id="section-total-groups" class="admin-panel audit-panel" aria-labelledby="total-groups-heading">
                <header class="audit-heading"><div><h2 id="total-groups-heading">Total Groups</h2><p>Every group currently recorded on the platform, including groups waiting to start</p></div></header>
                <?php if ($groupsError !== ''): ?><p class="admin-notice" role="alert"><?= adminH($groupsError) ?></p><?php endif; ?>
                <div class="audit-scroll" tabindex="0" role="region" aria-label="All platform groups">
                    <table class="audit-table">
                        <caption class="sr-only">All groups, their statuses, membership, and dates.</caption>
                        <thead><tr><th scope="col">Group Name / ID</th><th scope="col">Group Code</th><th scope="col">Status</th><th scope="col">Members / Slots</th><th scope="col">Created</th><th scope="col">First Payment Due</th></tr></thead>
                        <tbody>
                            <?php foreach ($allGroups as $group): ?>
                                <tr>
                                    <td class="audit-user"><?= adminH($group['group_name']) ?><small class="table-subtext">ID: <?= (int) $group['group_id'] ?></small></td>
                                    <td class="audit-group"><?= adminH($group['group_code']) ?></td>
                                    <td><span class="status-pill"><?= adminH($group['group_status']) ?></span></td>
                                    <td><?= (int) $group['member_count'] ?> / <?= (int) $group['total_member_slots'] ?></td>
                                    <td><?= adminH(adminDateTime($group['created_at'])) ?></td>
                                    <td><?= adminH(adminDateTime($group['first_payment_due'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$allGroups && $groupsError === ''): ?><tr><td colspan="6" class="table-empty">No groups have been created yet.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($adminView === 'completed-rounds'): ?>
            <section id="section-completed-rounds" class="admin-panel audit-panel" aria-labelledby="completed-rounds-heading">
                <header class="audit-heading"><div><h2 id="completed-rounds-heading">Completed Rounds</h2><p>Rounds marked as paid out, with the recorded operator approval or disbursement time</p></div></header>
                <?php if ($roundsError !== ''): ?><p class="admin-notice" role="alert"><?= adminH($roundsError) ?></p><?php endif; ?>
                <div class="audit-scroll" tabindex="0" role="region" aria-label="Completed group rounds">
                    <table class="audit-table">
                        <caption class="sr-only">Completed rounds for all groups.</caption>
                        <thead><tr><th scope="col">Group Name / ID</th><th scope="col">Group Code</th><th scope="col">Round</th><th scope="col">Receiver</th><th scope="col">Completed Date &amp; Time</th></tr></thead>
                        <tbody>
                            <?php foreach ($completedRounds as $round): ?>
                                <tr>
                                    <td class="audit-user"><?= adminH($round['group_name']) ?><small class="table-subtext">ID: <?= (int) $round['group_id'] ?></small></td>
                                    <td class="audit-group"><?= adminH($round['group_code']) ?></td>
                                    <td>Round <?= (int) $round['round_number'] ?></td>
                                    <td><?= adminH($round['receiver_name']) ?></td>
                                    <td class="audit-timestamp"><?= adminH(adminDateTime($round['completed_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$completedRounds && $roundsError === ''): ?><tr><td colspan="5" class="table-empty">No rounds have been completed yet.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($adminView === 'group-pots'): ?>
            <section id="section-group-pots" class="admin-panel audit-panel" aria-labelledby="group-pots-heading">
                <header class="audit-heading"><div><h2 id="group-pots-heading">Group Pots</h2><p>Current-round verified payments, expected pots, and total verified contributions across each cycle</p></div></header>
                <?php if ($potsError !== ''): ?><p class="admin-notice" role="alert"><?= adminH($potsError) ?></p><?php endif; ?>
                <div class="audit-scroll" tabindex="0" role="region" aria-label="Group round pots">
                    <table class="audit-table">
                        <caption class="sr-only">Current round statuses, due dates, accumulated pots, expected pots, and total verified contributions across all rounds of each group's cycle.</caption>
                        <thead><tr><th scope="col">Group Name / Group ID</th><th scope="col">Round Status and Due Date</th><th scope="col">Accumulated Pot</th><th scope="col">Expected Pot by Round End</th><th scope="col" title="Verified contributions across all rounds, including paid-out rounds. Excludes late fees.">Total Accumulation</th></tr></thead>
                        <tbody>
                            <?php foreach ($groupPots as $group): ?>
                                <?php
                                $potStatus = $group['round_status'] !== null
                                    ? $group['group_status'] . ' - Round ' . (int) $group['round_number'] . ' - ' . $group['round_status']
                                    : ($group['group_status'] === 'Waiting' ? 'Waiting to start' : $group['group_status'] . ' - no round scheduled');
                                $potDue = $group['target_deadline'] ?? $group['first_payment_due'];
                                ?>
                                <tr>
                                    <td class="audit-user"><?= adminH($group['group_name']) ?><small class="table-subtext">ID: <?= (int) $group['group_id'] ?> · <?= adminH($group['group_code']) ?></small></td>
                                    <td><span class="status-pill"><?= adminH($potStatus) ?></span><small class="table-subtext">Due: <?= adminH(adminDateTime($potDue)) ?></small></td>
                                    <td class="audit-ref">&#8369;<?= adminH(number_format((float) $group['accumulated_pot'], 2)) ?></td>
                                    <td class="audit-ref">&#8369;<?= adminH(number_format((float) $group['calculated_total_pot'], 2)) ?></td>
                                    <td class="audit-ref">&#8369;<?= adminH(number_format((float) $group['accumulated_cycle_pot'], 2)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$groupPots && $potsError === ''): ?><tr><td colspan="5" class="table-empty">No group pot records are available.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($adminView === 'frozen-groups'): ?>
            <section id="section-frozen-groups" class="admin-panel audit-panel" aria-labelledby="frozen-groups-heading">
                <header class="audit-heading"><div><h2 id="frozen-groups-heading">Frozen / Flagged Groups</h2><p>Current frozen groups; date and reason use the latest freeze event or poll details recorded in the system</p></div></header>
                <?php if ($frozenGroupsError !== ''): ?><p class="admin-notice" role="alert"><?= adminH($frozenGroupsError) ?></p><?php endif; ?>
                <div class="audit-scroll" tabindex="0" role="region" aria-label="Frozen and flagged groups">
                    <table class="audit-table">
                        <caption class="sr-only">Groups currently frozen on the platform and their recorded freeze details.</caption>
                        <thead><tr><th scope="col">Group Name / Group ID</th><th scope="col">Freeze Initiated By</th><th scope="col">Approval Date &amp; Time</th><th scope="col">Reason for Freezing</th></tr></thead>
                        <tbody>
                            <?php foreach ($frozenGroups as $group): ?>
                                <tr>
                                    <td class="audit-user"><?= adminH($group['group_name']) ?><small class="table-subtext">ID: <?= (int) $group['group_id'] ?> · <?= adminH($group['group_code']) ?></small></td>
                                    <td><?= adminH($group['initiated_by']) ?></td>
                                    <td class="audit-timestamp"><?= adminH(adminDateTime($group['freeze_approval_at'])) ?></td>
                                    <td><?= adminH($group['freeze_reason']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$frozenGroups && $frozenGroupsError === ''): ?><tr><td colspan="4" class="table-empty">No groups are currently frozen.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($adminView === 'user-directory'): ?>
            <section id="section-user-directory" class="admin-panel audit-panel" aria-labelledby="user-directory-heading">
                <header class="audit-heading"><div><h2 id="user-directory-heading">User Directory</h2><p>Registration and account details for all registered users</p></div></header>
                <?php if ($usersError !== ''): ?><p class="admin-notice" role="alert"><?= adminH($usersError) ?></p><?php endif; ?>
                <div class="audit-scroll" tabindex="0" role="region" aria-label="Registered user directory">
                    <table class="audit-table directory-table">
                        <caption class="sr-only">Registered users and the account details stored in the database.</caption>
                        <thead><tr><th scope="col">User ID</th><th scope="col">Username</th><th scope="col">Full Name</th><th scope="col">Email Address</th><th scope="col">Registered Date &amp; Time</th><th scope="col">Account Status</th><th scope="col">Role</th><th scope="col">Trust</th><th scope="col">Groups</th></tr></thead>
                        <tbody>
                            <?php foreach ($registeredUsers as $user): ?>
                                <?php $fullName = trim((string) ($user['full_name'] ?? '')); ?>
                                <tr>
                                    <td class="audit-user"><?= (int) $user['user_id'] ?></td>
                                    <td><?= adminH($user['username']) ?></td>
                                    <td><?= adminH($fullName !== '' ? $fullName : 'Not provided') ?></td>
                                    <td><?= adminH($user['email']) ?></td>
                                    <td class="audit-timestamp"><?= adminH(adminDateTime($user['created_at'])) ?></td>
                                    <td><span class="status-pill"><?= adminH($user['account_status']) ?></span></td>
                                    <td><?= !empty($user['is_admin']) ? 'Admin' : 'User' ?></td>
                                    <td><?= (int) $user['trust_score'] ?></td>
                                    <td><?= (int) $user['group_count'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$registeredUsers && $usersError === ''): ?><tr><td colspan="9" class="table-empty">No users have registered yet.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <?php endif; ?>

            <footer class="preview-footer"><p>All platform records, dispute interventions, and moderation logs are synced with the live database.</p></footer>
            <div class="admin-toast" id="admin-feedback" role="status" aria-live="polite" aria-atomic="true"><?= adminH($moderationSuccess) ?></div>
        </main>
    </div>

    <form id="moderation-form" method="post" action="admin.php" hidden>
        <input type="hidden" name="moderation_token" value="<?= adminH($moderationToken) ?>">
        <input type="hidden" name="moderation_action" id="moderation-action">
        <input type="hidden" name="user_id" id="moderation-user">
        <input type="hidden" name="group_id" id="moderation-group">
        <input type="hidden" name="expected_status" id="moderation-status">
    </form>
    <dialog id="admin-confirm" class="admin-dialog" aria-labelledby="confirm-title" aria-describedby="confirm-description">
        <form id="confirm-form" method="dialog">
            <span class="dialog-mark" aria-hidden="true">!</span>
            <h2 id="confirm-title"></h2><p id="confirm-description"></p>
            <div class="dialog-actions"><button class="admin-button secondary" id="cancel-action" type="button" autofocus>Cancel</button><button class="admin-button" id="confirm-action" type="submit">Confirm</button></div>
        </form>
    </dialog>
<script src="../LOGIN_PAGE/tab-auth.js?v=<?= filemtime(__DIR__ . '/../LOGIN_PAGE/tab-auth.js') ?>"></script>
</body>
</html>

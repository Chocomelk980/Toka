<?php
session_start();

require_once __DIR__ . '/../LOGIN_PAGE/access.php';
$adminUser = tokaRequireRole(true);

$username = $_SESSION['user']['username'] ?? 'User';
$registeredUsers = [];
$auditLogs = [];
$activeGroupCount = null;
$completedCycleCount = null;
$frozenGroupCount = null;
$activePoolVolume = null;
$usersError = '';
$auditError = '';
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
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = is_string($_POST['moderation_action'] ?? null) ? $_POST['moderation_action'] : '';
    $token = $_POST['moderation_token'] ?? '';
    $targetId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $expectedStatus = is_string($_POST['expected_status'] ?? null) ? $_POST['expected_status'] : '';

    try {
        if (!is_string($token) || !hash_equals($moderationToken, $token)) {
            throw new DomainException('Your confirmation expired. Refresh the page and try again.', 403);
        }
        if (!in_array($action, ['ban', 'unban'], true) || !$targetId
            || !in_array($expectedStatus, ['Active', 'Suspended', 'Banned', 'Action Needed'], true)) {
            throw new DomainException('Choose a valid user and moderation action.', 422);
        }
        if (!$pdo) throw new RuntimeException('Database unavailable.');

        $pdo->beginTransaction();
        // Recheck both accounts under a lock before making any status change.
        $stmt = $pdo->prepare(
            'SELECT user_id, username, is_admin, account_status FROM users
             WHERE user_id IN (:admin_id, :target_id) ORDER BY user_id FOR UPDATE'
        );
        $stmt->execute([':admin_id' => $adminUser['user_id'], ':target_id' => $targetId]);
        $lockedUsers = [];
        foreach ($stmt->fetchAll() as $lockedUser) $lockedUsers[(int) $lockedUser['user_id']] = $lockedUser;
        $actor = $lockedUsers[$adminUser['user_id']] ?? null;
        $target = $lockedUsers[$targetId] ?? null;
        if (!$actor || !tokaIsAdmin($actor) || $actor['account_status'] !== 'Active') {
            throw new DomainException('Only the active admin can manage accounts.', 403);
        }
        if (!$target) throw new DomainException('This user no longer exists. Refresh the page.', 404);
        if ($targetId === $adminUser['user_id'] || !empty($target['is_admin']) || $target['username'] === 'SaruPeroAdmin') {
            throw new DomainException('The admin account cannot be banned or unbanned.', 403);
        }
        if ($target['account_status'] !== $expectedStatus
            || ($action === 'ban' && $expectedStatus === 'Banned')
            || ($action === 'unban' && $expectedStatus !== 'Banned')) {
            throw new DomainException('This user’s status has changed. Review the updated roster and try again.', 409);
        }

        $newStatus = $action === 'ban' ? 'Banned' : 'Active';
        $stmt = $pdo->prepare('UPDATE users SET account_status = :status WHERE user_id = :user_id');
        $stmt->execute([':status' => $newStatus, ':user_id' => $targetId]);
        $verb = $action === 'ban' ? 'banned' : 'unbanned';
        $pdo->prepare(
            'INSERT INTO audit_logs (actor_user_id, activity_type, details, verification_status)
             VALUES (:actor, :activity, :details, :verification)'
        )->execute([
            ':actor' => $adminUser['user_id'],
            ':activity' => $action === 'ban' ? 'User Banned' : 'User Unbanned',
            ':details' => $target['username'] . ' (User ID ' . $targetId . ') was ' . $verb . ' by the admin.',
            ':verification' => 'Admin',
        ]);
        $pdo->commit();
        $_SESSION['admin_moderation_success'] = $target['username'] . ' has been ' . $verb . '.';
        header('Location: admin.php#roster-heading', true, 303);
        exit;
    } catch (DomainException $e) {
        if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
        http_response_code($e->getCode());
        $moderationError = $e->getMessage();
    } catch (Throwable $e) {
        if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
        error_log('Admin moderation error: ' . $e->getMessage());
        http_response_code(500);
        $moderationError = 'We could not update this account. Please refresh and try again.';
    }
}

if ($pdo) {
    try {
        $registeredUsers = $pdo->query(
            'SELECT u.user_id, u.username, u.trust_score, u.account_status, u.is_admin,
                    COALESCE(m.group_count, 0) AS group_count
             FROM users u
             LEFT JOIN (
                 SELECT user_id, COUNT(DISTINCT group_id) AS group_count
                 FROM group_members GROUP BY user_id
             ) m ON m.user_id = u.user_id
             ORDER BY u.user_id ASC'
        )->fetchAll();
    } catch (PDOException $e) {
        error_log('Admin roster DB error: ' . $e->getMessage());
        $usersError = 'We could not load registered users. Please refresh to try again.';
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
                COALESCE(SUM(CASE WHEN group_status = 'Active' THEN 1 ELSE 0 END), 0) AS active_group_count,
                COALESCE(SUM(CASE WHEN group_status = 'Completed' THEN 1 ELSE 0 END), 0) AS completed_cycle_count,
                COALESCE(SUM(CASE WHEN group_status = 'Frozen' THEN 1 ELSE 0 END), 0) AS frozen_group_count,
                COALESCE(SUM(CASE WHEN group_status = 'Active' THEN calculated_total_pot ELSE 0 END), 0) AS active_pool_volume
             FROM paluwagan_groups"
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
    <title>Admin Panel - Toka</title>
    <link rel="stylesheet" href="dashboard.css?v=<?= filemtime(__DIR__ . '/dashboard.css') ?>">
    <link rel="stylesheet" href="admin.css?v=<?= filemtime(__DIR__ . '/admin.css') ?>">
    <script src="admin.js?v=<?= filemtime(__DIR__ . '/admin.js') ?>" defer></script>
</head>
<body class="admin-page">
    <div class="app">
        <aside class="sidebar">
            <div class="sidebar-top">
                <div class="brand"><a href="admin.php" aria-label="Toka admin home"><img src="../assets/Toka.svg" alt="Toka"></a></div>
                <nav class="nav" aria-label="Main navigation">
                    <a href="admin.php" class="nav-item active" aria-current="page">
                        <span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3 4 6v6c0 5 8 9 8 9s8-4 8-9V6l-8-3Z"/><path d="M12 7v7m0 2v1"/></svg></span><span>Admin Panel</span>
                    </a>
                    <a href="#admin-overview" class="nav-item"><span class="nav-icon" aria-hidden="true">&#8962;</span><span>Dashboard</span></a>
                </nav>
            </div>
            <div class="sidebar-bottom">
                <div class="profile"><div class="profile-circle" aria-hidden="true"><?= adminH(strtoupper(substr($username, 0, 1))) ?></div><span><?= adminH($username) ?></span></div>
                <?php if (isset($_SESSION['user'])): ?><a href="../LOGIN_PAGE/logout.php" class="logout" aria-label="Log out">&#8618;</a><?php endif; ?>
            </div>
        </aside>

        <main class="main admin-main" id="admin-overview" tabindex="-1">
            <header class="admin-header">
                <h1>Admin Panel</h1>
                <p>Platform oversight, dispute management, and user control</p>
            </header>

            <noscript><p class="admin-notice">Enable JavaScript to search these records and confirm account or group actions.</p></noscript>
            <?php if ($moderationError !== ''): ?><p class="admin-notice" role="alert"><?= adminH($moderationError) ?></p><?php endif; ?>
            <div id="storage-notice" class="admin-notice" role="status" hidden></div>

            <section class="admin-stats" aria-label="Platform overview">
                <article class="admin-stat stat-active"><span class="stat-icon" aria-hidden="true"><i></i></span><strong id="active-count"><?= $activeGroupCount === null ? '—' : $activeGroupCount ?></strong><span>Total Active Groups</span></article>
                <article class="admin-stat stat-completed"><span class="stat-icon" aria-hidden="true"><i></i></span><strong id="completed-count"><?= $completedCycleCount === null ? '—' : $completedCycleCount ?></strong><span>Completed Cycles</span></article>
                <article class="admin-stat stat-volume"><span class="stat-icon" aria-hidden="true"><i></i></span><strong id="volume-total"><?= $activePoolVolume === null ? '—' : '₱' . adminH(number_format((float) $activePoolVolume, 0)) ?></strong><span>Total Active Money Pool Value</span></article>
                <article class="admin-stat stat-flagged"><span class="stat-icon" aria-hidden="true"><i></i></span><strong id="flagged-count"><?= $frozenGroupCount === null ? '—' : $frozenGroupCount ?></strong><span>Flagged / Frozen Groups</span></article>
            </section>

            <div class="admin-columns">
                <section class="admin-panel dispute-panel" aria-labelledby="dispute-heading">
                    <header class="panel-heading"><h2 id="dispute-heading" tabindex="-1">Dispute Intervention Desk</h2><p>Frozen and deadlocked groups requiring admin action</p></header>
                    <div class="dispute-scroll" tabindex="0" role="region" aria-label="Dispute table">
                        <table class="dispute-table">
                            <caption class="sr-only">Groups requiring intervention. Votes show votes to freeze out of total members.</caption>
                            <thead><tr><th scope="col">Group</th><th scope="col">Votes</th><th scope="col">Status</th><th scope="col">Reason</th><th scope="col">Actions</th></tr></thead>
                            <tbody id="dispute-rows"></tbody>
                        </table>
                    </div>
                    <div id="disputes-empty" class="admin-empty" hidden><span aria-hidden="true">&#10003;</span><h3>All caught up</h3><p>No groups need intervention right now.</p></div>
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
                <nav id="audit-pagination" class="audit-pagination" aria-label="Audit log pages" hidden>
                    <p id="audit-page-status" class="audit-page-status" aria-live="polite"></p>
                    <div class="audit-page-controls">
                        <button id="audit-prev" class="admin-button secondary" type="button">Previous</button>
                        <div id="audit-page-numbers" class="audit-page-numbers" role="group" aria-label="Select an audit log page"></div>
                        <button id="audit-next" class="admin-button secondary" type="button">Next</button>
                    </div>
                </nav>
                <p id="audit-empty" class="audit-empty" role="status" <?= $auditLogs || $auditError !== '' ? 'hidden' : '' ?>><?= $auditLogs ? 'No audit activity matches your search.' : 'No audit activity has been recorded yet.' ?></p>
                <p id="audit-count" class="sr-only" role="status" aria-live="polite"><?= $auditError !== '' ? 'Audit count unavailable.' : count($auditLogs) . ' audit records shown.' ?></p>
            </section>

            <footer class="preview-footer"><p>Users and audit logs load from the database on refresh. Group statistics and intervention controls are still a preview.</p><button id="reset-preview" type="button">Reset group preview</button></footer>
            <div class="admin-toast" id="admin-feedback" role="status" aria-live="polite" aria-atomic="true"><?= adminH($moderationSuccess) ?></div>
        </main>
    </div>

    <form id="moderation-form" method="post" action="admin.php" hidden>
        <input type="hidden" name="moderation_token" value="<?= adminH($moderationToken) ?>">
        <input type="hidden" name="moderation_action" id="moderation-action">
        <input type="hidden" name="user_id" id="moderation-user">
        <input type="hidden" name="expected_status" id="moderation-status">
    </form>
    <dialog id="admin-confirm" class="admin-dialog" aria-labelledby="confirm-title" aria-describedby="confirm-description">
        <form id="confirm-form" method="dialog">
            <span class="dialog-mark" aria-hidden="true">!</span>
            <h2 id="confirm-title"></h2><p id="confirm-description"></p>
            <div class="dialog-actions"><button class="admin-button secondary" id="cancel-action" type="button" autofocus>Cancel</button><button class="admin-button" id="confirm-action" type="submit">Confirm</button></div>
        </form>
    </dialog>
</body>
</html>
